<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $fillable = [
        'id_usuario',
        'activo',
        'tipo_entrega',
        'direccion_entrega',
        'latitud_entrega',
        'longitud_entrega',
        'id_domiciliario',
        'estado_pedido'
    ];

    protected $casts = [
        'activo' => 'boolean',
        'tipo_entrega' => 'string',
        'estado_pedido' => 'string'
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_selects',
            'id_carrito',
            'id_producto'
        )->withPivot('cantidad', 'precio_unitario')->withTimestamps();
    }

    public function paymentTransaction()
    {
        return $this->hasOne(PaymentTransaction::class,'id_carrito');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function domiciliario()
    {
        return $this->belongsTo(User::class, 'id_domiciliario');
    }

    public function shipment()
    {
        return $this->hasOne(Shipment::class, 'id_carrito');
    }
}

