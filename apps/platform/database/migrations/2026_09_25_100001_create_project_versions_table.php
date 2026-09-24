<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Full snapshots of a project's editable document (pages, sections, design
     * tokens), used for "restore previous version".
     */
    public function up(): void
    {
        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('reason', 20);
            $table->string('label', 120)->nullable();
            $table->jsonb('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'created_at']);
        });

        DB::statement("ALTER TABLE project_versions ADD CONSTRAINT project_versions_reason_check CHECK (reason IN ('generation', 'autosave', 'manual', 'pre_regenerate', 'pre_publish', 'pre_restore'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_versions');
    }
};
