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
     * credit_transactions is an append-only ledger and the source of truth.
     * teams.credit_balance is a cache, written in the same transaction as each
     * ledger row (see App\Domain\Credits\CreditLedger).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->integer('credit_balance')->default(0)->after('is_personal');
        });

        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_credit_balance_check CHECK (credit_balance >= 0)');

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('action_key', 40)->nullable();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refunds_id')->nullable()->unique()->constrained('credit_transactions');
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->unique();
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
        });

        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_type_check CHECK (type IN ('grant', 'purchase', 'spend', 'refund', 'adjustment', 'expire'))");
        DB::statement('ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_balance_check CHECK (balance_after >= 0)');
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_sign_check CHECK (
            (type IN ('grant', 'purchase', 'refund') AND amount > 0)
            OR (type IN ('spend', 'expire') AND amount < 0)
            OR (type = 'adjustment' AND amount <> 0)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
