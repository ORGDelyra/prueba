<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'nombre_categoria'
    ];

    public function products()
    {
        return $this->hasMany(Product::class,'id_categoria');
    }
}
