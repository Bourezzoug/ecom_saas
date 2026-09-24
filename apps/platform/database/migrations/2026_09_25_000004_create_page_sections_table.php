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
        Schema::create('page_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 64);
            $table->unsignedSmallInteger('section_version');
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('content')->default('{}');
            $table->jsonb('style')->default('{}');
            $table->text('brief')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUlid('last_generation_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign('section_key')->references('key')->on('section_types');
            $table->index(['page_id', 'position']);
            $table->index('section_key');
        });

        DB::statement("ALTER TABLE page_sections ADD CONSTRAINT page_sections_status_check CHECK (status IN ('pending', 'generating', 'ready', 'failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
