<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ie_files', function (Blueprint $table) {
            $table->id(); // BIGINT PK
            $table->string('type');                 // e.g., location
            $table->string('direction');            // import/export
            $table->string('status')->default('uploaded');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mimetype')->nullable(); // <-- useful
            $table->unsignedBigInteger('size')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            $table->json('options')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();

            // helpful indexes for listing/filtering
            $table->index(['type', 'direction']);
            $table->index(['status']);
            $table->index(['created_at']);
        });

        Schema::create('ie_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('ie_files')->cascadeOnDelete(); // shorthand
            $table->unsignedInteger('row_index');
            $table->json('payload');
            $table->string('status')->default('pending'); // processed/failed/skipped
            $table->text('message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['file_id', 'row_index']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ie_rows');
        Schema::dropIfExists('ie_files');
    }
};