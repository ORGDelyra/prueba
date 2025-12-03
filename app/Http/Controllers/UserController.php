<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use laravel\Sanctum\HasApiTokens;
class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::included()->filter()->sort()->get();
        return response()->json($users);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     * PUT /api/user/{id}
     *
     * Acepta: email, telefono, primer_nombre, primer_apellido, foto_url, etc.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();

        if (!$user || ($user->id != $id && $user->id_rol !== 1)) {
            return response()->json([
                'mensaje' => 'No tienes permisos para actualizar este usuario'
            ], 403);
        }

        $userToUpdate = User::find($id);
        if (!$userToUpdate) {
            return response()->json([
                'mensaje' => 'Usuario no encontrado'
            ], 404);
        }

        $data = $request->validate([
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'telefono' => 'sometimes|string|max:20',
            'primer_nombre' => 'sometimes|string|max:100',
            'primer_apellido' => 'sometimes|string|max:100',
            'foto_url' => 'nullable|url',  // URL de foto de perfil (Cloudinary)
        ]);

        // Actualizar campos básicos
        $userToUpdate->update($data);

        // Si incluye foto_url, guardar como imagen de perfil
        if (!empty($data['foto_url'])) {
            $userToUpdate->images()->where('type', 'profile')->delete();
            $userToUpdate->images()->create([
                'url' => $data['foto_url'],
                'type' => 'profile',
                'descripcion' => 'Foto de perfil del usuario',
            ]);
        }

        return response()->json([
            'mensaje' => 'Usuario actualizado con éxito',
            'user' => $userToUpdate->load('images')
        ], 200);
    }

    /**
     * Guardar foto de perfil del usuario (URL de Cloudinary)
     */
    public function updateProfileImage(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'mensaje' => 'Error: Usuario no autenticado'
            ], 401);
        }

        $request->validate([
            'profile_url' => 'required|url',
        ]);

        // Eliminar imagen de perfil anterior si existe
        $user->images()->where('type', 'profile')->delete();

        // Crear nueva imagen de perfil
        $user->images()->create([
            'url' => $request->profile_url,
            'type' => 'profile',
            'descripcion' => 'Foto de perfil del usuario',
        ]);

        return response()->json([
            'mensaje' => 'Foto de perfil actualizada con éxito',
            'user' => $user->load('images')
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
