<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $branches = Branch::with(['user', 'images' => function($query) {
            $query->where('type', 'logo');
        }])->get();

        // Agregar logo_comercio a cada sucursal
        $branches->transform(function($branch) {
            $logoImage = $branch->images->where('type', 'logo')->first();
            $branch->logo_comercio = $logoImage ? $logoImage->url : null;
            return $branch;
        });

        return response()->json($branches);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre_sucursal'=> 'required|string|max:50',
            'nit' => 'required|string|unique:branches,nit',
            'img_nit' => 'nullable|string',
            'longitud' => 'nullable|string',
            'latitud' => 'nullable|string',
            'direccion' => 'nullable|string',
            'id_commerce_category' => 'nullable|exists:categories,id',
            'logo_url' => 'nullable|url',  // Logo del comercio (URL de Cloudinary, opcional)
            'logo_comercio' => 'nullable|url', // Alias esperado desde frontend
        ]);

        // Obtener usuario autenticado
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'mensaje' => 'Error: Usuario no autenticado'
            ], 401);
        }

        // Verificar que el usuario no tenga ya una sucursal (un solo branch por usuario)
        if ($user->branches()->exists()) {
            return response()->json([
                'mensaje' => 'El usuario ya tiene una sucursal registrada'
            ], 400);
        }

        // Agregar id_usuario a los datos
        $data['id_usuario'] = $user->id;

        $branch = Branch::create($data);
        if(!$branch){
            return response()->json([
                'mensaje' => 'Error: no se ha podido crear la sucursal'
            ],400);
        }

        // Guardar logo de la sucursal si existe (acepta logo_url o logo_comercio)
        $logoUrl = $request->input('logo_url') ?? $request->input('logo_comercio');
        if ($logoUrl) {
            $branch->images()->create([
                'url' => $logoUrl,
                'type' => 'logo',
                'descripcion' => 'Logo del comercio',
            ]);
        }

        // Recargar imágenes y agregar logo_comercio
        $branch->load('images');
        $logoImage = $branch->images->where('type', 'logo')->first();
        $branch->logo_comercio = $logoImage ? $logoImage->url : null;

        return response()->json([
            'mensaje' => 'Sucursal creada con exito',
            'sucursal' => $branch,
            'id' => $branch->id
        ],201);
    }
    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        $branch->load(['user', 'images' => function($query) {
            $query->where('type', 'logo');
        }]);

        // Agregar logo_comercio
        $logoImage = $branch->images->where('type', 'logo')->first();
        $branch->logo_comercio = $logoImage ? $logoImage->url : null;

        return response()->json($branch, 200);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Branch $branch)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Branch $branch)
    {
        $user = $request->user();

        // Verificar que el usuario sea el dueño de la sucursal
        if ($branch->id_usuario !== $user->id) {
            return response()->json([
                'mensaje' => 'No tienes permisos para actualizar esta sucursal',
                'debug' => [
                    'usuario_autenticado' => $user->id,
                    'dueno_sucursal' => $branch->id_usuario,
                    'sucursal_id' => $branch->id
                ]
            ], 403);
        }

        $data = $request->validate([
            'nombre_sucursal'=> 'sometimes|string|max:50',
            'nit' => 'sometimes|string|unique:branches,nit,' . $branch->id,
            'img_nit' => 'sometimes|nullable|string',
            'longitud' => 'sometimes|nullable|string',
            'latitud' => 'sometimes|nullable|string',
            'direccion' => 'sometimes|nullable|string',
            'id_commerce_category' => 'sometimes|nullable|exists:categories,id',
            'logo_comercio' => 'sometimes|nullable|string|max:1000' // URL de Cloudinary
        ]);

        // Actualizar sucursal
        $branch->update($data);

        // Si se proporciona logo_comercio, actualizar/crear la imagen
        if (isset($data['logo_comercio'])) {
            // Eliminar logo anterior si existe
            $branch->images()->where('type', 'logo')->delete();
            
            // Crear nuevo logo
            if ($data['logo_comercio']) {
                $branch->images()->create([
                    'url' => $data['logo_comercio'],
                    'type' => 'logo',
                    'descripcion' => 'Logo del comercio',
                ]);
            }
        }

        // Recargar imágenes y agregar logo_comercio
        $branch->load('images');
        $logoImage = $branch->images->where('type', 'logo')->first();
        $branch->logo_comercio = $logoImage ? $logoImage->url : null;

        return response()->json([
            'mensaje' => 'Sucursal actualizada con éxito',
            'sucursal' => $branch
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Branch $branch)
    {
        $user = $request->user();

        // Verificar que el usuario sea el dueño de la sucursal
        if ($branch->id_usuario !== $user->id) {
            return response()->json([
                'mensaje' => 'No tienes permisos para eliminar esta sucursal'
            ], 403);
        }

        $branch->delete();

        return response()->json([
            'mensaje' => 'Sucursal eliminada con éxito'
        ], 200);
    }
}
