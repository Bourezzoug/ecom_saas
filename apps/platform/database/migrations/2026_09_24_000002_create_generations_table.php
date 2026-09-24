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
     * One row per AI call. A user action (e.g. "generate store") has a root row
     * that carries the charged credits; follow-up calls point at it via parent_id.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable()->index();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 40);
            $table->string('action_key', 40)->nullable();
            $table->nullableUlidMorphs('subject');
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('system_prompt')->nullable();
            $table->text('prompt')->nullable();
            $table->jsonb('json_schema')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('raw_output')->nullable();
            $table->jsonb('validation_errors')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->integer('credits')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
        });

        // Self-reference is added after the table exists (the primary key must come first).
        Schema::table('generations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('generations')->nullOnDelete();
        });

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'repaired', 'failed'))");
        DB::statement("CREATE INDEX generations_active_status_index ON generations (status) WHERE status IN ('queued', 'running')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
