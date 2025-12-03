<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_selects', function (Blueprint $table) {
            $table->decimal('precio_unitario', 10, 2)->nullable()->after('cantidad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_selects', function (Blueprint $table) {
            $table->dropColumn('precio_unitario');
        });
    }
};
