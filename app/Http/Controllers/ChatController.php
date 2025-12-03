<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Obtener todos los mensajes de un pedido
     * GET /api/chat/{id_pedido}
     */
    public function getMessages($id_pedido)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'mensaje' => 'Usuario no autenticado'
            ], 401);
        }

        // Verificar que el pedido existe
        $pedido = Cart::find($id_pedido);
        if (!$pedido) {
            return response()->json([
                'mensaje' => 'Pedido no encontrado'
            ], 404);
        }

        // Verificar permisos: solo cliente (dueño del pedido), vendedor o domiciliario asignado
        $esCliente = $pedido->id_usuario === $user->id;
        $esVendedor = in_array($user->id_rol, [2, 3]); // rol 2,3 = vendedor/admin
        $esDomiciliario = $pedido->id_domiciliario === $user->id;

        if (!($esCliente || $esVendedor || $esDomiciliario)) {
            return response()->json([
                'mensaje' => 'No tienes permiso para ver este chat'
            ], 403);
        }

        // Obtener mensajes del pedido (ordenados por timestamp)
        $mensajes = Message::where('id_pedido', $id_pedido)
            ->with('remitente', 'destinatario')
            ->orderBy('created_at', 'asc')
            ->get();

        $mensajesFormateados = $mensajes->map(function ($msg) {
            return [
                'id' => $msg->id,
                'id_remitente' => $msg->id_remitente,
                'id_destinatario' => $msg->id_destinatario,
                'usuario' => $msg->remitente->primer_nombre . ' ' . $msg->remitente->primer_apellido,
                'mensaje' => $msg->contenido,
                'imagen_url' => $msg->imagen_url,
                'tipo_imagen' => $msg->tipo_imagen,
                'timestamp' => $msg->created_at->toIso8601String()
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id_pedido' => $id_pedido,
                'messages' => $mensajesFormateados,
                'total' => count($mensajesFormateados)
            ]
        ], 200);
    }

    /**
     * Enviar un mensaje en el chat del pedido
     * POST /api/chat/{id_pedido}/enviar
     *
     * Body esperado:
     * {
     *   "contenido": "Texto del mensaje",
     *   "imagen_url": "https://cloudinary.com/...", (opcional)
     *   "tipo_imagen": "comprobante", (opcional: comprobante, producto, otro)
     *   "id_destinatario": 5 (opcional, por defecto el vendedor del pedido)
     * }
     */
    public function sendMessage(Request $request, $id_pedido)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'mensaje' => 'Usuario no autenticado'
            ], 401);
        }

        // Validar pedido
        $pedido = Cart::find($id_pedido);
        if (!$pedido) {
            return response()->json([
                'mensaje' => 'Pedido no encontrado'
            ], 404);
        }

        // Validar permisos
        $esCliente = $pedido->id_usuario === $user->id;
        $esVendedor = in_array($user->id_rol, [2, 3]);
        $esDomiciliario = $pedido->id_domiciliario === $user->id;

        if (!($esCliente || $esVendedor || $esDomiciliario)) {
            return response()->json([
                'mensaje' => 'No tienes permiso para enviar mensajes en este chat'
            ], 403);
        }

        // Validar datos
        $data = $request->validate([
            'contenido' => 'required|string|max:1000',
            'imagen_url' => 'nullable|url',
            'tipo_imagen' => 'nullable|in:comprobante,producto,otro',
            'id_destinatario' => 'nullable|exists:users,id'
        ]);

        // Si no especifica destinatario, determinarlo automáticamente
        if (!$data['id_destinatario'] ?? null) {
            if ($esCliente) {
                // Cliente → Vendedor
                $product = $pedido->products()->first();
                if ($product && $product->id_usuario) {
                    $data['id_destinatario'] = $product->id_usuario;
                } else {
                    return response()->json([
                        'mensaje' => 'Error: No se puede determinar el vendedor'
                    ], 400);
                }
            } elseif ($esVendedor) {
                // Vendedor → Cliente
                $data['id_destinatario'] = $pedido->id_usuario;
            } elseif ($esDomiciliario) {
                // Domiciliario → Cliente
                $data['id_destinatario'] = $pedido->id_usuario;
            }
        }

        // OPCIÓN A: Solo guardar mensajes con imagen (comprobantes, etc)
        // Los mensajes de texto puro se envían pero NO se persisten en BD
        $tieneImagen = !empty($data['imagen_url'] ?? null);

        try {
            $mensaje = null;

            // Si tiene imagen, guardar en BD
            if ($tieneImagen) {
                $mensaje = Message::create([
                    'id_remitente' => $user->id,
                    'id_destinatario' => $data['id_destinatario'],
                    'id_pedido' => $id_pedido,
                    'contenido' => $data['contenido'],
                    'imagen_url' => $data['imagen_url'],
                    'tipo_imagen' => $data['tipo_imagen'] ?? 'comprobante',
                ]);
            }

            // Responder con el mensaje (guardado o temporal)
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $mensaje?->id ?? null,
                    'id_remitente' => $user->id,
                    'id_destinatario' => $data['id_destinatario'],
                    'usuario' => $user->primer_nombre . ' ' . $user->primer_apellido,
                    'mensaje' => $data['contenido'],
                    'imagen_url' => $data['imagen_url'] ?? null,
                    'tipo_imagen' => $data['tipo_imagen'] ?? null,
                    'timestamp' => now()->toIso8601String(),
                    'almacenado' => $tieneImagen ? true : false, // Indicar si se guardó en BD
                    'nota' => !$tieneImagen ? 'Mensaje temporal (no se guardó en BD). Solo se guardan comprobantes.' : null
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al enviar mensaje: ' . $e->getMessage());
            return response()->json([
                'mensaje' => 'Error al enviar el mensaje',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todas las conversaciones del usuario actual
     * GET /api/conversaciones
     *
     * Devuelve lista de pedidos con últimos mensajes
     */
    public function getConversations(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'mensaje' => 'Usuario no autenticado'
            ], 401);
        }

        // Obtener pedidos relevantes para el usuario
        $pedidosQuery = null;

        if (in_array($user->id_rol, [2, 3])) {
            // Vendedor: pedidos de sus productos
            $pedidosQuery = Cart::whereHas('products', function ($q) use ($user) {
                $q->where('id_usuario', $user->id);
            });
        } elseif ($user->id_rol === 4) {
            // Domiciliario: pedidos asignados a él
            $pedidosQuery = Cart::where('id_domiciliario', $user->id);
        } else {
            // Cliente: sus propios pedidos
            $pedidosQuery = Cart::where('id_usuario', $user->id);
        }

        $pedidos = $pedidosQuery
            ->with(['user', 'products', 'domiciliario'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $conversaciones = $pedidos->map(function ($pedido) {
            // Obtener último mensaje
            $ultimoMensaje = Message::where('id_pedido', $pedido->id)
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'id_pedido' => $pedido->id,
                'cliente' => [
                    'id' => $pedido->user->id,
                    'nombre' => $pedido->user->primer_nombre . ' ' . $pedido->user->primer_apellido
                ],
                'estado_pedido' => $pedido->estado_pedido,
                'tipo_entrega' => $pedido->tipo_entrega,
                'ultimo_mensaje' => $ultimoMensaje ? [
                    'contenido' => $ultimoMensaje->contenido,
                    'remitente' => $ultimoMensaje->remitente->primer_nombre . ' ' . $ultimoMensaje->remitente->primer_apellido,
                    'timestamp' => $ultimoMensaje->created_at->toIso8601String()
                ] : null,
                'created_at' => $pedido->created_at->toIso8601String()
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'conversaciones' => $conversaciones,
                'total' => count($conversaciones)
            ]
        ], 200);
    }

    /**
     * Marcar mensaje como leído (futuro)
     * POST /api/chat/mensaje/{id}/leer
     */
    public function markAsRead($id_mensaje)
    {
        $user = Auth::user();
        $mensaje = Message::find($id_mensaje);

        if (!$mensaje) {
            return response()->json([
                'mensaje' => 'Mensaje no encontrado'
            ], 404);
        }

        // Solo el destinatario puede marcar como leído
        if ($mensaje->id_destinatario !== $user->id) {
            return response()->json([
                'mensaje' => 'No tienes permiso para marcar este mensaje'
            ], 403);
        }

        // Aquí podrías agregar un campo 'leído' a la tabla messages en el futuro
        return response()->json([
            'success' => true,
            'mensaje' => 'Mensaje marcado como leído'
        ], 200);
    }
}
