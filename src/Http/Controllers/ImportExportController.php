<?php

namespace Enadstack\ImporterExporter\Http\Controllers;

use Enadstack\ImporterExporter\Models\IeFile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

/**
 * V1: thin controller that delegates to user-defined Importer/Exporter classes
 * which you register in config('importer-exporter.importers/exporters').
 *
 * Importers must expose: headers()
 * Exporters must expose: headers(), source(array $filters), map($item)
 */
class ImportExportController extends Controller
{
    public function template(string $type)
    {
        $importer = $this->resolveImporter($type);
        abort_unless($importer, 404, "Importer for [$type] is not registered.");

        $headers = $importer->headers();
        $csv = implode(',', $headers) . PHP_EOL;

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$type}_template.csv\"",
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'type' => ['required','string'],
            'file' => ['required','file','mimetypes:text/plain,text/csv'],
        ]);

        $type = $request->string('type')->toString();
        abort_unless($this->resolveImporter($type), 422, "Importer for [$type] is not registered.");

        $disk = config('importer-exporter.disk');
        $storedPath = $request->file('file')->store('ie/imports', $disk);

        $file = IeFile::create([
            'type'          => $type,
            'direction'     => 'import',
            'status'        => 'uploaded',
            'disk'          => $disk,
            'path'          => $storedPath,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'size'          => $request->file('file')->getSize(),
            'user_id'       => optional($request->user())->id,
        ]);

        // V1: just mark as queued; processing job comes next step
        // dispatch(new \Enadstack\ImporterExporter\Jobs\ProcessImportJob($file->id));
        //$file->update(['status' => 'queued']);
        $this->processNow($file);

        return response()->json(['id' => $file->id, 'status' => $file->status], 201);
    }

    public function export(Request $request, string $type)
    {
        $exporter = $this->resolveExporter($type);
        abort_unless($exporter, 404, "Exporter for [$type] is not registered.");

        $headers = $exporter->headers();
        $name = "{$type}_export_" . now()->format('Ymd_His') . ".csv";

        // Create a file log entry first (direction=export)
        $disk = config('importer-exporter.disk', config('filesystems.default', 'local'));
        $file = IeFile::create([
            'type' => $type,
            'direction' => 'export',
            'status' => 'processing',
            'disk' => $disk,
            'path' => '',
            'original_name' => $name,
            'size' => null,
            'options' => $request->all(),
            'user_id' => optional($request->user())->id,
        ]);

        try {
            // Build CSV in a temp stream, then store to disk (more reliable than streaming)
            $stream = fopen('php://temp', 'r+');

            // UTF-8 BOM for Excel/Arabic
            fwrite($stream, "\xEF\xBB\xBF");

            // header row
            fputcsv($stream, $headers);

            $total = 0;
            $ok = 0;
            foreach ($exporter->source($request->all()) as $item) {
                $row = $exporter->map($item);
                // Normalize to scalars so fputcsv doesn't choke on enums/objects
                $row = array_map(function ($v) {
                    if ($v instanceof \BackedEnum) return $v->value;
                    if (is_bool($v)) return $v ? 1 : 0;
                    return is_scalar($v) || $v === null ? $v : (string)$v;
                }, $row);

                fputcsv($stream, $row);
                $total++;
                $ok++;
            }

            // Save to disk and close temp stream
            rewind($stream);
            $path = 'ie/exports/' . $name;
            Storage::disk($disk)->put($path, stream_get_contents($stream));
            fclose($stream);

            $size = Storage::disk($disk)->size($path);

            // Update file log
            $file->update([
                'status' => 'completed',
                'path' => $path,
                'size' => $size,
                'total_rows' => $total,
                'success_rows' => $ok,
                'failed_rows' => 0,
            ]);

            // Prepare headers
            $headersArr = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}\"",
                'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'              => 'no-cache',
            ];

            // If the disk is local, serve via BinaryFileResponse so we can delete after send.
            $diskConfig = config("filesystems.disks.$disk");
            $isLocal = ($diskConfig['driver'] ?? null) === 'local';
            if ($isLocal) {
                $absolute = Storage::disk($disk)->path($path);
                return response()->download($absolute, $name, $headersArr)->deleteFileAfterSend(true);
            }

            // Non-local disks (e.g., s3) return a StreamedResponse; deleteFileAfterSend() is not available.
            // Consider a scheduled cleanup for old exports on non-local disks.
            return Storage::disk($disk)->download($path, $name, $headersArr);
        } catch (\Throwable $e) {
            // Mark as failed and rethrow for visibility
            $file->update(['status' => 'failed']);
            throw $e;
        }
    }

    public function files(Request $request)
    {
        $query = IeFile::query()->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $query->paginate($request->integer('per_page', 20));
    }

    public function show(string $id)
    {
        return IeFile::with('rows')->findOrFail($id);
    }

    // ----------------- helpers -----------------

    protected function resolveImporter(string $type)
    {
        $map = config('importer-exporter.importers', []);
        return isset($map[$type]) ? app($map[$type]) : null;
    }

    protected function resolveExporter(string $type)
    {
        $map = config('importer-exporter.exporters', []);
        return isset($map[$type]) ? app($map[$type]) : null;
    }

    protected function processNow(IeFile $file): void
    {
        $importer = $this->resolveImporter($file->type);
        if (!$importer || !method_exists($importer, 'headers')) {
            $file->update(['status' => 'failed']);
            return;
        }

        $disk = config('importer-exporter.disk');
        $stream = Storage::disk($disk)->readStream($file->path);
        if (!$stream) {
            $file->update(['status' => 'failed']);
            return;
        }

        // Header row
        $headers = fgetcsv($stream);
        $expected = $importer->headers();
        if ($headers !== $expected) {
            fclose($stream);
            $file->update(['status' => 'failed']);
            return;
        }

        $total = $ok = $fail = 0;
        $rowIndex = 2; // CSV row number (including header)

        while (($row = fgetcsv($stream)) !== false) {
            $assoc = array_combine($headers, array_map(fn($v) => $v === '' ? null : $v, $row));
            $total++;

            // Optional validation if your importer has rules()
            $errors = null;
            if (method_exists($importer, 'rules')) {
                $validator = \Validator::make($assoc, $importer->rules());
                if ($validator->fails()) {
                    $errors = $validator->errors()->toJson();
                }
            }

            if ($errors) {
                $file->rows()->create([
                    'file_id'     => $file->id,
                    'row_index'    => $rowIndex++,
                    'payload'      => $assoc,
                    'status'       => 'failed',
                    'message'      => $errors,
                    'processed_at' => now(),
                ]);
                $fail++;
                continue;
            }

            try {
                $payload = method_exists($importer, 'map') ? $importer->map($assoc) : $assoc;
                if (method_exists($importer, 'persist')) {
                    $importer->persist($payload);
                }

                $file->rows()->create([
                    'file_id'      => $file->id,
                    'row_index'    => $rowIndex++,
                    'payload'      => $assoc,
                    'status'       => 'processed',
                    'processed_at' => now(),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $file->rows()->create([
                    'file_id'      => $file->id,
                    'row_index'    => $rowIndex++,
                    'payload'      => $assoc,
                    'status'       => 'failed',
                    'message'      => $e->getMessage(),
                    'processed_at' => now(),
                ]);
                $fail++;
            }
        }

        fclose($stream);

        $file->update([
            'status'       => $fail === 0 ? 'completed' : ($ok > 0 ? 'partial' : 'failed'),
            'total_rows'   => $total,
            'success_rows' => $ok,
            'failed_rows'  => $fail,
        ]);
    }
}