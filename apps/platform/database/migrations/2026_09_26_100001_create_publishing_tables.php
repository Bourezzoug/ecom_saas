<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (docs/ARCHITECTURE.md §2.5).
     */
    public function up(): void
    {
        Schema::create('wp_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('site_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('signing_secret')->nullable();
            $table->char('key_last_four', 4)->nullable();
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->string('elementor_version', 20)->nullable();
            $table->string('woocommerce_version', 20)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->string('theme', 60)->nullable();
            $table->jsonb('last_health')->nullable();
            $table->timestampTz('last_health_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE wp_connections ADD CONSTRAINT wp_connections_status_check CHECK (status IN ('pending', 'connected', 'error', 'revoked'))");
        DB::statement("CREATE UNIQUE INDEX wp_connections_one_active_per_project ON wp_connections (project_id) WHERE status <> 'revoked'");

        Schema::create('publishes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('wp_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target', 10)->default('push');
            $table->string('status', 20)->default('queued');
            $table->jsonb('options')->default('{}');
            $table->jsonb('summary')->default('{}');
            $table->foreignUlid('export_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        DB::statement("ALTER TABLE publishes ADD CONSTRAINT publishes_target_check CHECK (target IN ('push', 'zip'))");
        DB::statement("ALTER TABLE publishes ADD CONSTRAINT publishes_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'partial', 'failed'))");
        DB::statement("CREATE UNIQUE INDEX publishes_one_running_per_project ON publishes (project_id) WHERE status IN ('queued', 'running')");

        Schema::create('publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('publish_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->string('item_ref', 40)->nullable();
            $table->string('label')->nullable();
            $table->string('action', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['publish_id', 'id']);
        });

        DB::statement("ALTER TABLE publish_logs ADD CONSTRAINT publish_logs_status_check CHECK (status IN ('pending', 'ok', 'failed', 'conflict', 'skipped'))");

        Schema::create('wp_remote_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('wp_connection_id')->constrained()->cascadeOnDelete();
            $table->string('local_type', 20);
            $table->string('local_ref', 40);
            $table->unsignedBigInteger('remote_id');
            $table->char('published_hash', 64)->nullable();
            $table->timestampTz('published_at')->nullable();

            $table->unique(['wp_connection_id', 'local_type', 'local_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wp_remote_mappings');
        Schema::dropIfExists('publish_logs');
        Schema::dropIfExists('publishes');
        Schema::dropIfExists('wp_connections');
    }
};
