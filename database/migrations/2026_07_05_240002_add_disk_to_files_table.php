<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Which filesystem disk `path` lives on. Stored per-row so the disk
            // can change over time (local now, S3 in Phase 5) without rewriting
            // existing rows — the disk is resolved via Storage::disk($file->disk).
            $table->string('disk')->default('media')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
