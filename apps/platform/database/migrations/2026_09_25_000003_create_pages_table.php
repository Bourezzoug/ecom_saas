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
     * kind = page | header | footer. Header and footer are "layout parts": one
     * row each per project, edited like a page with a single section.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('page');
            $table->string('type', 20)->default('custom');
            $table->string('title');
            $table->string('slug');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_homepage')->default(false);
            $table->jsonb('seo')->default('{}');
            $table->timestamps();

            $table->index(['project_id', 'kind', 'position']);
            $table->unique(['project_id', 'slug']);
        });

        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_kind_check CHECK (kind IN ('page', 'header', 'footer'))");
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_type_check CHECK (type IN ('home', 'about', 'contact', 'faq', 'landing', 'custom'))");
        DB::statement('CREATE UNIQUE INDEX pages_one_homepage_per_project ON pages (project_id) WHERE is_homepage');
        DB::statement("CREATE UNIQUE INDEX pages_one_layout_part_per_project ON pages (project_id, kind) WHERE kind <> 'page'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
