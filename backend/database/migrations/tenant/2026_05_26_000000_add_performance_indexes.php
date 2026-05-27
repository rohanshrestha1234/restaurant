<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->index('deleted_at', 'menu_items_deleted_at_idx');
            $table->index(['is_available', 'deleted_at'], 'menu_items_avail_deleted_idx');
        });

        Schema::table('restaurant_spaces', function (Blueprint $table) {
            $table->index('is_active', 'restaurant_spaces_is_active_idx');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->index(['restaurant_space_id', 'status'], 'restaurant_tables_space_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropIndex('restaurant_tables_space_status_idx');
        });

        Schema::table('restaurant_spaces', function (Blueprint $table) {
            $table->dropIndex('restaurant_spaces_is_active_idx');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex('menu_items_avail_deleted_idx');
            $table->dropIndex('menu_items_deleted_at_idx');
        });
    }
};
