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
        Schema::table('carts', function (Blueprint $table) {
            // Cambiar estado_pedido a nullable sin default
            // Esto permite que sea NULL cuando es un carrito activo sin confirmar
            $table->enum('estado_pedido', ['pendiente', 'confirmado', 'en_preparacion', 'listo', 'entregado', 'recogido'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Volver al estado anterior (con default 'pendiente')
            $table->enum('estado_pedido', ['pendiente', 'confirmado', 'en_preparacion', 'listo', 'entregado', 'recogido'])->default('pendiente')->change();
        });
    }
};
