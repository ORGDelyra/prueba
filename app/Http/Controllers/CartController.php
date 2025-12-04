<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class CartController extends Controller
{
    /**
     * Crear un nuevo pedido
     */
    public function crearPedido(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'productos' => 'required|array|min:1',
            'productos.*.id_producto' => 'required|exists:products,id',
            'productos.*.cantidad' => 'required|integer|min:1',
            'productos.*.precio_unitario' => 'required|numeric|min:0',
            'tipo_entrega' => 'required|in:domicilio,recogida',
            'direccion_entrega' => 'required_if:tipo_entrega,domicilio|string|nullable',
            'latitud_entrega' => 'required_if:tipo_entrega,domicilio|string|nullable',
            'longitud_entrega' => 'required_if:tipo_entrega,domicilio|string|nullable',
            'id_rama' => 'nullable|exists:branches,id'
        ]);

        try {
            DB::beginTransaction();

            // ✅ IMPORTANTE: Eliminar el carrito temporal existente (activo=1)
            $carritoTemporal = Cart::where('id_usuario', $user->id)
                ->where('activo', 1)
                ->whereNull('estado_pedido')
                ->first();

            if ($carritoTemporal) {
                Log::info('🗑️ Eliminando carrito temporal ID: ' . $carritoTemporal->id);
                // Eliminar productos del carrito temporal
                $carritoTemporal->products()->detach();
                // Eliminar el carrito temporal
                $carritoTemporal->delete();
            }

            // ✅ Crear NUEVO carrito como PEDIDO confirmado
            $cart = Cart::create([
                'id_usuario' => $user->id,
                'tipo_entrega' => $data['tipo_entrega'],
                'direccion_entrega' => $data['direccion_entrega'] ?? null,
                'latitud_entrega' => $data['latitud_entrega'] ?? null,
                'longitud_entrega' => $data['longitud_entrega'] ?? null,
                'activo' => 0,                  // ✅ Pedido confirmado (no es carrito temporal)
                'estado_pedido' => 'pendiente'  // ✅ Estado inicial del pedido
            ]);

            Log::info('✅ Pedido creado ID: ' . $cart->id . ' para usuario: ' . $user->id);

            // Agregar productos al pedido
            $total = 0;
            $productos_data = [];

            foreach ($data['productos'] as $producto_data) {
                $producto = Product::find($producto_data['id_producto']);

                // Validar que hay suficiente stock
                if ($producto->cantidad < $producto_data['cantidad']) {
                    throw new \Exception("Stock insuficiente para el producto: {$producto->nombre}. Solo quedan {$producto->cantidad} unidades disponibles");
                }

                $subtotal = $producto_data['cantidad'] * $producto_data['precio_unitario'];
                $total += $subtotal;

                // Descontar del inventario
                $producto->cantidad -= $producto_data['cantidad'];
                $producto->save();

                // Usar pivot table
                $cart->products()->attach($producto_data['id_producto'], [
                    'cantidad' => $producto_data['cantidad'],
                    'precio_unitario' => $producto_data['precio_unitario']
                ]);

                $productos_data[] = [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'cantidad' => $producto_data['cantidad'],
                    'precio_unitario' => $producto_data['precio_unitario'],
                    'subtotal' => $subtotal
                ];
            }

            DB::commit();

            return response()->json([
                'mensaje' => 'Pedido creado exitosamente',
                'pedido' => [
                    'id' => $cart->id,
                    'id_usuario' => $cart->id_usuario,
                    'tipo_entrega' => $cart->tipo_entrega,
                    'direccion_entrega' => $cart->direccion_entrega,
                    'latitud_entrega' => $cart->latitud_entrega,
                    'longitud_entrega' => $cart->longitud_entrega,
                    'id_domiciliario' => $cart->id_domiciliario,
                    'estado_pedido' => $cart->estado_pedido,
                    'total' => $total,
                    'productos' => $productos_data,
                    'created_at' => $cart->created_at
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'mensaje' => 'Error al crear el pedido: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Ver los pedidos del cliente autenticado
     */
    public function misPedidos(Request $request)
    {
        $user = Auth::user();

        $pedidos = Cart::with(['products.images', 'products.category', 'domiciliario'])
            ->where('id_usuario', $user->id)
            ->where('activo', false) // Solo pedidos confirmados
            ->orderBy('created_at', 'desc')
            ->get();

        $pedidos_formateados = $pedidos->map(function ($pedido) {
            return [
                'id' => $pedido->id,
                'id_usuario' => $pedido->id_usuario,
                'tipo_entrega' => $pedido->tipo_entrega,
                'direccion_entrega' => $pedido->direccion_entrega,
                'latitud_entrega' => $pedido->latitud_entrega,
                'longitud_entrega' => $pedido->longitud_entrega,
                'estado_pedido' => $pedido->estado_pedido,
                'id_domiciliario' => $pedido->id_domiciliario,
                'domiciliario' => $pedido->domiciliario ? [
                    'id' => $pedido->domiciliario->id,
                    'nombre_completo' => $pedido->domiciliario->primer_nombre . ' ' . $pedido->domiciliario->primer_apellido,
                    'telefono' => $pedido->domiciliario->telefono
                ] : null,
                'total' => $pedido->products->sum(fn($p) => $p->pivot->cantidad * $p->pivot->precio_unitario),
                'productos' => $pedido->products->map(fn($p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'cantidad' => $p->pivot->cantidad,
                    'precio_unitario' => $p->pivot->precio_unitario,
                    'subtotal' => $p->pivot->cantidad * $p->pivot->precio_unitario,
                    'images' => $p->images,
                    'category' => $p->category
                ]),
                'created_at' => $pedido->created_at
            ];
        });

        return response()->json($pedidos_formateados);
    }

    /**
     * Ver pedidos pendientes de la tienda (para vendedor)
     */
    public function pedidosTienda(Request $request)
    {
        $user = Auth::user();

        // Verificar que sea vendedor (rol 2 o 3) o administrador (rol 1)
        if (!in_array($user->id_rol, [1, 2, 3])) {
            return response()->json([
                'mensaje' => 'No tienes permisos para ver los pedidos'
            ], 403);
        }

        $pedidos = Cart::with('products', 'user', 'domiciliario')
            ->whereIn('estado_pedido', ['pendiente', 'confirmado', 'en_preparacion'])
            ->orderBy('created_at', 'asc')
            ->get();

        $pedidos_formateados = $pedidos->map(function ($pedido) {
            return [
                'id' => $pedido->id,
                'id_usuario' => $pedido->id_usuario,
                'usuario' => [
                    'nombre_completo' => $pedido->user->primer_nombre . ' ' . $pedido->user->primer_apellido,
                    'telefono' => $pedido->user->telefono,
                    'correo' => $pedido->user->correo
                ],
                'tipo_entrega' => $pedido->tipo_entrega,
                'direccion_entrega' => $pedido->direccion_entrega,
                'estado_pedido' => $pedido->estado_pedido,
                'id_domiciliario' => $pedido->id_domiciliario,
                'domiciliario' => $pedido->domiciliario ? [
                    'id' => $pedido->domiciliario->id,
                    'nombre_completo' => $pedido->domiciliario->primer_nombre . ' ' . $pedido->domiciliario->primer_apellido
                ] : null,
                'total' => $pedido->products->sum(fn($p) => $p->pivot->cantidad * $p->pivot->precio_unitario),
                'productos' => $pedido->products->map(fn($p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'cantidad' => $p->pivot->cantidad,
                    'precio_unitario' => $p->pivot->precio_unitario
                ]),
                'created_at' => $pedido->created_at
            ];
        });

        return response()->json($pedidos_formateados);
    }

    /**
     * Actualizar estado del pedido
     */
    public function actualizarEstado(Request $request, Cart $cart)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!in_array($user->id_rol, [1, 2, 3])) {
            return response()->json([
                'mensaje' => 'No tienes permisos para actualizar pedidos'
            ], 403);
        }

        $data = $request->validate([
            'estado_pedido' => 'required|in:pendiente,confirmado,en_preparacion,listo,entregado,recogido'
        ]);

        $cart->update(['estado_pedido' => $data['estado_pedido']]);

        return response()->json([
            'mensaje' => "Estado actualizado a {$data['estado_pedido']}",
            'pedido' => [
                'id' => $cart->id,
                'estado_pedido' => $cart->estado_pedido,
                'updated_at' => $cart->updated_at
            ]
        ], 200);
    }

    /**
     * Asignar domiciliario al pedido
     */
    public function asignarDomiciliario(Request $request, Cart $cart)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!in_array($user->id_rol, [1, 2, 3])) {
            return response()->json([
                'mensaje' => 'No tienes permisos para asignar domiciliarios'
            ], 403);
        }

        // Verificar que es un pedido de domicilio
        if ($cart->tipo_entrega !== 'domicilio') {
            return response()->json([
                'mensaje' => 'Solo se pueden asignar domiciliarios a pedidos de domicilio'
            ], 400);
        }

        $data = $request->validate([
            'id_domiciliario' => 'required|exists:users,id'
        ]);

        // Verificar que el usuario es domiciliario (rol 4)
        $domiciliario = User::find($data['id_domiciliario']);
        if ($domiciliario->id_rol !== 4) {
            return response()->json([
                'mensaje' => 'El usuario seleccionado no es un domiciliario'
            ], 400);
        }

        $cart->update(['id_domiciliario' => $data['id_domiciliario']]);

        return response()->json([
            'mensaje' => 'Domiciliario asignado correctamente',
            'pedido' => [
                'id' => $cart->id,
                'id_domiciliario' => $cart->id_domiciliario,
                'domiciliario' => [
                    'id' => $domiciliario->id,
                    'nombre_completo' => $domiciliario->primer_nombre . ' ' . $domiciliario->primer_apellido,
                    'telefono' => $domiciliario->telefono
                ],
                'estado_pedido' => $cart->estado_pedido,
                'updated_at' => $cart->updated_at
            ]
        ], 200);
    }

    /**
     * Ver entregas asignadas al domiciliario
     */
    public function misEntregas(Request $request)
    {
        $user = Auth::user();

        // Verificar que sea domiciliario (rol 4)
        if ($user->id_rol !== 4) {
            return response()->json([
                'mensaje' => 'No tienes permisos para ver entregas'
            ], 403);
        }

        $entregas = Cart::with('products', 'user')
            ->where('id_domiciliario', $user->id)
            ->whereIn('estado_pedido', ['listo', 'en_camino'])
            ->orderBy('created_at', 'asc')
            ->get();

        $entregas_formateadas = $entregas->map(function ($entrega) {
            return [
                'id' => $entrega->id,
                'id_usuario' => $entrega->id_usuario,
                'cliente' => [
                    'nombre_completo' => $entrega->user->primer_nombre . ' ' . $entrega->user->primer_apellido,
                    'telefono' => $entrega->user->telefono,
                    'correo' => $entrega->user->correo
                ],
                'direccion_entrega' => $entrega->direccion_entrega,
                'latitud_entrega' => $entrega->latitud_entrega,
                'longitud_entrega' => $entrega->longitud_entrega,
                'estado_pedido' => $entrega->estado_pedido,
                'total' => $entrega->products->sum(fn($p) => $p->pivot->cantidad * $p->pivot->precio_unitario),
                'productos' => $entrega->products->map(fn($p) => [
                    'nombre' => $p->nombre,
                    'cantidad' => $p->pivot->cantidad,
                    'precio_unitario' => $p->pivot->precio_unitario
                ]),
                'created_at' => $entrega->created_at
            ];
        });

        return response()->json($entregas_formateadas);
    }

    /**
     * Marcar pedido como entregado
     */
    public function marcarEntregado(Request $request, Cart $cart)
    {
        $user = Auth::user();

        // Verificar que sea domiciliario
        if ($user->id_rol !== 4) {
            return response()->json([
                'mensaje' => 'No tienes permisos para marcar entregas'
            ], 403);
        }

        // Verificar que el pedido le pertenece
        if ($cart->id_domiciliario !== $user->id) {
            return response()->json([
                'mensaje' => 'Este pedido no está asignado a ti'
            ], 403);
        }

        // Verificar que es domicilio
        if ($cart->tipo_entrega !== 'domicilio') {
            return response()->json([
                'mensaje' => 'Solo pedidos de domicilio se pueden marcar como entregados'
            ], 400);
        }

        $data = $request->validate([
            'codigo_confirmacion' => 'nullable|string|max:50',
            'comentario' => 'nullable|string|max:500'
        ]);

        $cart->update(['estado_pedido' => 'entregado']);

        return response()->json([
            'mensaje' => 'Pedido marcado como entregado',
            'pedido' => [
                'id' => $cart->id,
                'estado_pedido' => $cart->estado_pedido,
                'updated_at' => $cart->updated_at
            ]
        ], 200);
    }

    /**
     * Marcar pedido como recogido
     */
    public function marcarRecogido(Request $request, Cart $cart)
    {
        $user = Auth::user();

        // Verificar que sea el dueño del pedido
        if ($cart->id_usuario !== $user->id) {
            return response()->json([
                'mensaje' => 'No puedes marcar como recogido un pedido que no es tuyo'
            ], 403);
        }

        // Verificar que es recogida en tienda
        if ($cart->tipo_entrega !== 'recogida') {
            return response()->json([
                'mensaje' => 'Solo pedidos de recogida se pueden marcar como recogidos'
            ], 400);
        }

        $data = $request->validate([
            'comentario' => 'nullable|string|max:500'
        ]);

        $cart->update(['estado_pedido' => 'recogido']);

        return response()->json([
            'mensaje' => 'Pedido marcado como recogido',
            'pedido' => [
                'id' => $cart->id,
                'estado_pedido' => $cart->estado_pedido,
                'updated_at' => $cart->updated_at
            ]
        ], 200);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        Log::info('📋 index() - Buscando carritos del usuario: ' . $user->id);

        // Obtener solo carritos activos (no pedidos confirmados)
        // Busca tanto NULL como cadena vacía por si la BD tiene inconsistencias
        $carts = Cart::where('id_usuario', $user->id)
            ->where('activo', 1)
            ->where(function($query) {
                $query->whereNull('estado_pedido')
                      ->orWhere('estado_pedido', '');
            })
            ->with(['products.images', 'products.category'])
            ->orderBy('created_at', 'desc')
            ->get();

        Log::info('✅ Carritos encontrados: ' . $carts->count());
        Log::info('📦 Carritos IDs: ' . $carts->pluck('id')->toJson());

        return response()->json($carts);
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
    public function show(Cart $cart)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Cart $cart)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Cart $cart)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cart $cart)
    {
        //
    }

    /**
     * Actualizar la cantidad de un producto en el carrito
     * PUT /api/cart/{cart}/products/{product}
     */
    public function updateQuantity(Request $request, $cart, $product)
    {
        try {
            $user = Auth::user();

            // Validar que el carrito pertenezca al usuario
            $cart = Cart::where('id', $cart)
                ->where('id_usuario', $user->id)
                ->first();

            if (!$cart) {
                return response()->json(['mensaje' => 'Carrito no encontrado'], 404);
            }

            // Validar cantidad solicitada
            $request->validate([
                'cantidad' => 'required|integer|min:1'
            ]);

            $nuevaCantidad = $request->input('cantidad');

            // Verificar que el producto existe y tiene stock suficiente
            $productModel = Product::find($product);

            if (!$productModel) {
                return response()->json(['mensaje' => 'Producto no encontrado'], 404);
            }

            if ($nuevaCantidad > $productModel->cantidad) {
                return response()->json([
                    'mensaje' => "Solo hay {$productModel->cantidad} unidades disponibles",
                    'stock_disponible' => $productModel->cantidad
                ], 400);
            }

            // Actualizar cantidad en la tabla pivote
            $cart->products()->updateExistingPivot($product, [
                'cantidad' => $nuevaCantidad
            ]);

            return response()->json([
                'mensaje' => 'Cantidad actualizada correctamente',
                'producto' => $productModel->nombre,
                'nueva_cantidad' => $nuevaCantidad
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al actualizar cantidad',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un producto del carrito
     * DELETE /api/cart/{cart}/products/{product}
     */
    public function removeProduct($cart, $product)
    {
        try {
            $user = Auth::user();

            $cart = Cart::where('id', $cart)
                ->where('id_usuario', $user->id)
                ->first();

            if (!$cart) {
                return response()->json(['mensaje' => 'Carrito no encontrado'], 404);
            }

            // Eliminar el producto del carrito (tabla pivote)
            $cart->products()->detach($product);

            return response()->json([
                'mensaje' => 'Producto eliminado del carrito'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al eliminar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Obtener pedidos disponibles para que domiciliarios puedan tomar
     * GET /api/cart/pedidos-disponibles
     */
    public function pedidosDisponibles(Request $request)
    {
        $user = Auth::user();

        // Verificar que sea domiciliario (rol 4)
        if ($user->id_rol !== 4) {
            return response()->json([
                'mensaje' => 'Solo los domiciliarios pueden ver pedidos disponibles'
            ], 403);
        }

        Log::info("📋 pedidosDisponibles() - Domiciliario: {$user->id}");

        // Pedidos con:
        // - activo = 0 (pedido confirmado)
        // - tipo_entrega = 'domicilio'
        // - estado_pedido = 'listo'
        // - id_domiciliario = NULL (sin asignar)
        $pedidos = Cart::with(['products', 'user'])
            ->where('activo', 0)
            ->where('tipo_entrega', 'domicilio')
            ->where('estado_pedido', 'listo')
            ->whereNull('id_domiciliario')
            ->orderBy('created_at', 'asc')
            ->get();

        Log::info("✅ Pedidos disponibles encontrados: " . $pedidos->count());

        // Formatear respuesta
        $pedidos_formateados = $pedidos->map(function ($pedido) {
            return [
                'id' => $pedido->id,
                'estado_pedido' => $pedido->estado_pedido,
                'tipo_entrega' => $pedido->tipo_entrega,
                'cliente' => [
                    'id' => $pedido->user->id,
                    'nombre_completo' => $pedido->user->primer_nombre . ' ' . $pedido->user->primer_apellido,
                    'telefono' => $pedido->user->telefono,
                    'correo' => $pedido->user->correo
                ],
                'direccion_entrega' => $pedido->direccion_entrega,
                'latitud_entrega' => $pedido->latitud_entrega,
                'longitud_entrega' => $pedido->longitud_entrega,
                'total' => $pedido->products->sum(fn($p) => $p->pivot->cantidad * $p->pivot->precio_unitario),
                'cantidad_productos' => $pedido->products->count(),
                'productos' => $pedido->products->map(fn($p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'cantidad' => $p->pivot->cantidad,
                    'precio_unitario' => $p->pivot->precio_unitario
                ]),
                'created_at' => $pedido->created_at
            ];
        });

        return response()->json($pedidos_formateados);
    }

    /**
     * ✅ Domiciliario toma un pedido disponible (se asigna a sí mismo)
     * PUT /api/cart/{id}/tomar-pedido
     */
    public function tomarPedido(Request $request, Cart $cart)
    {
        $user = Auth::user();

        // Verificar que sea domiciliario (rol 4)
        if ($user->id_rol !== 4) {
            return response()->json([
                'mensaje' => 'Solo los domiciliarios pueden tomar pedidos'
            ], 403);
        }

        Log::info("🚚 tomarPedido() - Domiciliario: {$user->id}, Pedido: {$cart->id}");

        // Verificar que el pedido sea de domicilio
        if ($cart->tipo_entrega !== 'domicilio') {
            return response()->json([
                'mensaje' => 'Este pedido no es de domicilio'
            ], 400);
        }

        // Verificar que el pedido esté disponible (sin domiciliario asignado)
        if ($cart->id_domiciliario !== null) {
            return response()->json([
                'mensaje' => 'Este pedido ya fue tomado por otro domiciliario'
            ], 409); // Conflict
        }

        // Verificar que el pedido esté listo para entregar
        if ($cart->estado_pedido !== 'listo') {
            return response()->json([
                'mensaje' => 'Este pedido aún no está listo para entregar. Estado actual: ' . $cart->estado_pedido
            ], 400);
        }

        // ✅ Asignar el domiciliario y cambiar estado a 'en_camino'
        $cart->update([
            'id_domiciliario' => $user->id,
            'estado_pedido' => 'en_camino'
        ]);

        Log::info("✅ Domiciliario {$user->id} tomó el pedido {$cart->id}");

        // Recargar con relaciones
        $cart->load(['products', 'user']);

        return response()->json([
            'mensaje' => 'Pedido tomado exitosamente',
            'pedido' => [
                'id' => $cart->id,
                'id_domiciliario' => $cart->id_domiciliario,
                'estado_pedido' => $cart->estado_pedido,
                'tipo_entrega' => $cart->tipo_entrega,
                'cliente' => [
                    'nombre_completo' => $cart->user->primer_nombre . ' ' . $cart->user->primer_apellido,
                    'telefono' => $cart->user->telefono
                ],
                'direccion_entrega' => $cart->direccion_entrega,
                'latitud_entrega' => $cart->latitud_entrega,
                'longitud_entrega' => $cart->longitud_entrega,
                'total' => $cart->products->sum(fn($p) => $p->pivot->cantidad * $p->pivot->precio_unitario),
                'productos' => $cart->products
            ]
        ], 200);
    }
}
