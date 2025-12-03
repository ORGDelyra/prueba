<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_usuario',
        'nombre_sucursal',
        'nit',
        'img_nit',
        'id_commerce_category',

        'latitud',
        'longitud',
        'direccion'
    ];
    public function user()
    {
        return $this->belongsTo(User::class,'id_usuario');
    }
    public function images()
    {
        return $this->morphMany(\App\Models\Image::class, 'imageable');
    }
}
