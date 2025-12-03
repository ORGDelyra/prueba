<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = Category::all();
        return response()->json($categories, 200);
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
            'nombre_categoria' => 'required|string|max:100|unique:categories,nombre_categoria',
        ]);

        $category = Category::create($data);

        return response()->json([
            'mensaje' => 'Categoría creada con éxito',
            'categoria' => $category
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        return response()->json($category, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'nombre_categoria' => 'sometimes|string|max:100|unique:categories,nombre_categoria,' . $category->id,
        ]);

        $category->update($data);

        return response()->json([
            'mensaje' => 'Categoría actualizada con éxito',
            'categoria' => $category
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json([
            'mensaje' => 'Categoría eliminada con éxito'
        ], 200);
    }
}
