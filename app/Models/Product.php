<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
        protected $fillable = [
            'id_usuario',
            'id_sucursal',
            'id_categoria',
            'nombre',
            'descripcion',
            'precio',
            'cantidad',
            'image_url',
        ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'id_categoria');
    }

    public function carts()
    {
        return $this->belongsToMany(
            Cart::class,
            'product_selects',
            'id_producto',
            'id_carrito'
        )->withPivot('cantidad', 'precio_unitario')->withTimestamps();
    }

    public function images()
    {
        return $this->morphMany(\App\Models\Image::class, 'imageable');
    }
}
