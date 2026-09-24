<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A cache of /sections, written only by `php artisan sections:sync`.
     */
    public function up(): void
    {
        Schema::create('section_types', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedSmallInteger('version');
            $table->string('name', 120);
            $table->string('category', 40);
            $table->string('placement', 10);
            $table->jsonb('page_types')->default('[]');
            $table->boolean('requires_woocommerce')->default(false);
            $table->jsonb('schema');
            $table->jsonb('meta');
            $table->jsonb('compiled');
            $table->char('template_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_types');
    }
};
