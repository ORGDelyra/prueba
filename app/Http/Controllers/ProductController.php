<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;



class ProductController extends Controller
{
    // Lista productos del vendedor autenticado (protegido)
    public function index()
    {
        $user = Auth::user();
        if(!$user || $user->id_rol != 3){
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }

        $products = Product::where('id_usuario', $user->id)
            ->with(['category', 'images'])
            ->get();
        return response()->json($products);
    }

    // Lista pública de productos (catálogo general)
    public function publicIndex()
    {
        $products = Product::with('category','user')->get();
        return response()->json($products);
    }

    // Lista productos de una sucursal específica (público)
    public function productsByBranch($branchId)
    {
        $products = Product::where('id_sucursal', $branchId)
            ->where('cantidad', '>', 0)
            ->with(['category', 'images'])
            ->get();
        return response()->json($products);
    }

    // Mostrar un producto específico (protegido)
    public function show(Product $product)
    {
        return response()->json($product->load('category','user','images'), 200);
    }

    // Crear producto (solo comerciante) + imágenes múltiples
    public function store(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if($user->id_rol != 3){ // 3 = comerciante
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'id_sucursal' => 'nullable|exists:branches,id',
            'id_categoria' => 'required|exists:categories,id',
            'nombre' => 'required|string|max:100',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric',
            'cantidad' => 'required|integer',
            'image_url' => 'nullable|url',         // URL de la imagen principal (Cloudinary)
            'imagenes' => 'nullable|array',         // Array de URLs de Cloudinary
            'imagenes.*' => 'url',                  // Cada URL debe ser válida
            'comprobante_url' => 'nullable|url',   // URL del comprobante (opcional)
        ]);

        // Si no se proporciona id_sucursal, buscar la sucursal del vendedor automáticamente
        $idSucursal = $data['id_sucursal'] ?? null;
        if (!$idSucursal) {
            $branch = \App\Models\Branch::where('id_usuario', $user->id)->first();
            if ($branch) {
                $idSucursal = $branch->id;
            }
        }

        // Crear el producto
        $product = $user->products()->create([
            'id_sucursal' => $idSucursal,
            'id_categoria' => $data['id_categoria'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'precio' => $data['precio'],
            'cantidad' => $data['cantidad']
        ]);

        // Guardar imágenes del producto
        // Prioridad: si viene array 'imagenes', usar eso; si no, usar 'image_url'
        $imagenesGuardar = [];

        if (!empty($data['imagenes'])) {
            $imagenesGuardar = $data['imagenes'];
        } elseif (!empty($data['image_url'])) {
            $imagenesGuardar = [$data['image_url']];
        }

        // Guardar cada imagen
        foreach ($imagenesGuardar as $index => $url) {
            $product->images()->create([
                'url' => $url,
                'type' => 'product',
                'descripcion' => 'Imagen ' . ($index + 1) . ' del producto',
            ]);
        }

        // Guardar comprobante de pago si existe
        if ($request->comprobante_url) {
            $product->images()->create([
                'url' => $data['comprobante_url'],
                'type' => 'comprobante',
                'descripcion' => 'Comprobante de pago adjunto',
            ]);
        }

        return response()->json([
            'mensaje' => 'Producto y activos guardados con éxito',
            'product' => $product->load('images')
        ], 201);
    }

    // Actualizar producto (solo comerciante dueño del producto)
    public function update(Request $request, Product $product)
    {
        $user = Auth::user();
        if($user->id_rol != 3 || $product->id_usuario != $user->id){
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }
        $data = $request->validate([
            'id_sucursal' => 'sometimes|nullable|exists:branches,id',
            'id_categoria' => 'sometimes|exists:categories,id',
            'nombre' => 'sometimes|string|max:100',
            'descripcion' => 'nullable|string',
            'precio' => 'sometimes|numeric',
            'cantidad' => 'sometimes|integer'
        ]);

        $product->update($data);
        return response()->json($product);
    }

    // Eliminar producto (solo comerciante dueño del producto)
    public function destroy(Product $product)
    {
        $user = Auth::user();
        
        // Validar que el usuario es vendedor y dueño del producto
        if ($user->id_rol != 3 || $product->id_usuario != $user->id) {
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }
        
        // Eliminar las imágenes asociadas
        if ($product->images()->count() > 0) {
            foreach ($product->images as $image) {
                // Opcional: Eliminar de Cloudinary también
                // Cloudinary::destroy($image->public_id);
                $image->delete();
            }
        }
        
        // Eliminar el producto
        $product->delete();
        
        return response()->json([
            'mensaje' => 'Producto eliminado exitosamente'
        ], 200);
    }

    // Agregar producto al carrito (usuario logueado)
    public function addToCart(Request $request, Product $product)
    {
        $user = Auth::user();
        if($user->id_rol != 2){ // 2 = cliente
            return response()->json(['mensaje' => 'Solo los clientes pueden agregar productos al carrito'], 403);
        }

        $data = $request->validate([
            'cantidad' => 'required|integer|min:1'
        ]);

        $cantidad = $data['cantidad'];

        // Verificar stock disponible
        if ($cantidad > $product->cantidad) {
            return response()->json([
                'mensaje' => "Solo hay {$product->cantidad} unidades disponibles",
                'stock_disponible' => $product->cantidad
            ], 400);
        }

        // Log inicial
        \Log::info('🛒 addToCart - Usuario: ' . $user->id . ', Producto: ' . $product->id);
        
        // Buscar carrito activo SIN estado_pedido (no reutilizar pedidos confirmados)
        // Busca tanto NULL como cadena vacía por si la BD tiene inconsistencias
        $cart = Cart::where('id_usuario', $user->id)
            ->where('activo', 1)
            ->where(function($query) {
                $query->whereNull('estado_pedido')
                      ->orWhere('estado_pedido', '');
            })
            ->first();

        \Log::info('🔍 Carrito encontrado: ' . ($cart ? $cart->id : 'ninguno'));

        // Si no existe carrito activo, crear uno nuevo
        if (!$cart) {
            \Log::info('➕ Creando nuevo carrito para usuario ' . $user->id);
            
            $cart = Cart::create([
                'id_usuario' => $user->id,
                'activo' => 1
            ]);
            
            \Log::info('✅ Carrito creado con ID: ' . $cart->id);
        }

        // Verificar si el producto ya está en el carrito
        $cartProduct = $cart->products()->where('id_producto', $product->id)->first();

        if ($cartProduct) {
            $nuevaCantidad = $cartProduct->pivot->cantidad + $cantidad;
            
            // Verificar stock para la nueva cantidad
            if ($nuevaCantidad > $product->cantidad) {
                return response()->json([
                    'mensaje' => "Solo hay {$product->cantidad} unidades disponibles",
                    'stock_disponible' => $product->cantidad
                ], 400);
            }

            $cart->products()->updateExistingPivot($product->id, [
                'cantidad' => $nuevaCantidad,
                'precio_unitario' => $product->precio
            ]);
        } else {
            $cart->products()->attach($product->id, [
                'cantidad' => $cantidad,
                'precio_unitario' => $product->precio
            ]);
        }

        // Recargar carrito con todas las relaciones necesarias
        $cart->load(['products.images', 'products.category']);
        
        \Log::info('Producto agregado al carrito', [
            'cart_id' => $cart->id,
            'producto_id' => $product->id,
            'cantidad' => $cantidad,
            'total_productos_en_carrito' => $cart->products->count()
        ]);

        return response()->json([
            'mensaje' => 'Producto agregado al carrito',
            'cart' => $cart
        ], 200);
    }

    // Ver carrito activo del usuario
    public function viewCart()
    {
        $user = Auth::user();
        if($user->id_rol != 2){
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }
            /** @var \App\Models\User $user */
            $user = Auth::user();
        $cart = Cart::with('products')->where('id_usuario', $user->id)->where('activo', true)->first();
        if(!$cart) return response()->json(['mensaje' => 'Carrito vacío']);

        return response()->json($cart);
    }

    // Checkout: marca carrito como inactivo
    public function checkout()
    {
        $user = Auth::user();
        if($user->id_rol != 2){
            return response()->json(['mensaje' => 'No autorizado'], 403);
        }
            /** @var \App\Models\User $user */
            $user = Auth::user();
        $cart = Cart::where('id_usuario', $user->id)->where('activo', true)->first();
        if(!$cart) return response()->json(['mensaje' => 'Carrito vacío']);

          $cart->update(['activo' => false]);

        return response()->json(['mensaje' => 'Compra realizada', 'cart' => $cart]);
    }
}
