<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 20)->default('store');
            $table->string('status', 20)->default('draft');
            $table->string('creation_mode', 20)->default('describe');
            $table->string('language', 10);
            $table->string('direction', 3)->default('ltr');
            $table->char('currency', 3)->nullable();
            $table->jsonb('brief')->default('{}');
            $table->jsonb('site_plan')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'updated_at']);
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_kind_check CHECK (kind IN ('store'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('draft', 'generating', 'ready', 'partial', 'failed'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_creation_mode_check CHECK (creation_mode IN ('describe', 'import_products', 'import_design'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_direction_check CHECK (direction IN ('ltr', 'rtl'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
