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
        Schema::create('design_tokens', function (Blueprint $table) {
            $table->foreignUlid('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('colors');
            $table->jsonb('fonts');
            $table->string('radius', 10)->default('md');
            $table->string('spacing', 10)->default('normal');
            $table->unsignedSmallInteger('container_width')->default(1200);
            $table->jsonb('extra')->default('{}');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE design_tokens ADD CONSTRAINT design_tokens_radius_check CHECK (radius IN ('none', 'sm', 'md', 'lg', 'full'))");
        DB::statement("ALTER TABLE design_tokens ADD CONSTRAINT design_tokens_spacing_check CHECK (spacing IN ('compact', 'normal', 'airy'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_tokens');
    }
};
