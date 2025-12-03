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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_remitente');
            $table->unsignedBigInteger('id_destinatario');
            $table->unsignedBigInteger('id_pedido')->nullable();
            $table->text('contenido');
            $table->string('imagen_url')->nullable();
            $table->string('tipo_imagen')->nullable(); // 'comprobante', 'producto', 'otro'
            $table->timestamps();

            // Índices para búsquedas rápidas
            $table->index('id_remitente');
            $table->index('id_destinatario');
            $table->index('id_pedido');
            $table->index('created_at');

            // Claves foráneas
            $table->foreign('id_remitente')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('id_destinatario')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('id_pedido')->references('id')->on('carts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
