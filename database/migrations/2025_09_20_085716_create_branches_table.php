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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_usuario')
                    ->constrained('users')
                    ->onDelete('cascade');
            $table->string('nombre_sucursal',50);
            $table->string('nit');
            $table->string('img_nit')->nullable();
            $table->foreignId('id_commerce_category')
                    ->nullable()
                    ->constrained('categories')
                    ->onDelete('set null');
            $table->string('latitud')->nullable();
            $table->string('longitud')->nullable();
            $table->string('direccion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
