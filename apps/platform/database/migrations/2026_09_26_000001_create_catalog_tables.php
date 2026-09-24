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
     * The local copy of a store's catalog, pushed into WooCommerce on publish.
     * ULIDs are the stable refs (_aisg_ref meta on the WordPress side).
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->jsonb('image')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('product_categories')->nullOnDelete();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('simple');
            $table->string('name');
            $table->string('slug');
            $table->string('sku', 100)->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('images')->default('[]');
            $table->jsonb('attributes')->default('[]');
            $table->jsonb('variations')->default('[]');
            $table->string('stock_status', 20)->default('instock');
            $table->integer('stock_quantity')->nullable();
            $table->string('status', 20)->default('publish');
            $table->string('source', 20)->default('manual');
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('extra')->default('{}');
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'sku']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('simple', 'variable'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_status_check CHECK (stock_status IN ('instock', 'outofstock', 'onbackorder'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'publish'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_source_check CHECK (source IN ('manual', 'csv', 'ai'))");

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignUlid('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
