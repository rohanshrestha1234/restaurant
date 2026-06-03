<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            // SoftDeletes filters `deleted_at IS NULL` on every query including COUNT(*).
            // Composite index covers: list-all, filter-by-availability, and both together.
            $table->index(['deleted_at', 'is_available'], 'menu_items_deleted_at_is_available_idx');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex('menu_items_deleted_at_is_available_idx');
        });
    }
};
