<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'id_remitente',       // Usuario que envía el mensaje
        'id_destinatario',    // Usuario que recibe el mensaje
        'id_pedido',          // Carrito/pedido al cual se refiere el chat
        'contenido',          // Texto del mensaje
        'imagen_url',         // URL de la imagen (comprobante de pago)
        'tipo_imagen',        // Tipo: 'comprobante', 'producto', 'otro'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Usuario que envía el mensaje
     */
    public function remitente()
    {
        return $this->belongsTo(User::class, 'id_remitente');
    }

    /**
     * Relación: Usuario que recibe el mensaje
     */
    public function destinatario()
    {
        return $this->belongsTo(User::class, 'id_destinatario');
    }

    /**
     * Relación: Pedido/carrito al cual se refiere
     */
    public function pedido()
    {
        return $this->belongsTo(Cart::class, 'id_pedido');
    }
}
