<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\PaymentTransactionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\CloudinaryController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí están todas las rutas de la API para el proyecto Delyra
|
*/

// ==========================================
// RUTAS PÚBLICAS (No requieren autenticación)
// ==========================================

// Autenticación
Route::post('/registro', [AuthController::class, 'register']);
Route::post('/inicio/sesion', [AuthController::class, 'login']);

// Productos públicos (catálogo)
Route::get('/products/public', [ProductController::class, 'publicIndex']);

// Productos de una sucursal específica (público)
Route::get('/products/branch/{branchId}', [ProductController::class, 'productsByBranch']);

// Categorías públicas
Route::get('/category', [CategoryController::class, 'index']);

// Ver información de una sucursal (público)
Route::get('/branch/{id}', [BranchController::class, 'show']);

// ==========================================
// RUTAS PROTEGIDAS (Requieren autenticación)
// ==========================================

Route::middleware('auth:sanctum')->group(function () {

    // ==========================================
    // RECURSOS API ESTÁNDAR (CRUD completo)
    // ==========================================

    Route::apiResource('rol', RolController::class);
    Route::apiResource('user', UserController::class);
    Route::apiResource('branch', BranchController::class)->except(['show']);

    // Métodos especiales para imágenes de usuario
    Route::post('/user/profile-image', [UserController::class, 'updateProfileImage']);

    // Subida de archivos a Cloudinary vía backend (devuelve `secure_url`)
    Route::post('/upload/cloudinary', [ImageUploadController::class, 'upload']);

    // Generar URL firmada / acceso privado para recursos en Cloudinary (si se necesita)
    Route::post('/cloudinary/generate-private-url', [CloudinaryController::class, 'generatePrivateUrl']);

    // Productos (CRUD para vendedores)
    Route::apiResource('product', ProductController::class)->except(['index']);
    // Index protegido: solo productos del vendedor autenticado
    Route::get('/product', [ProductController::class, 'index']);

    // Categorías (CRUD completo para admins, excepto index que es público)
    Route::apiResource('category', CategoryController::class)->except(['index']);

    // ==========================================
    // MÉTODOS ESPECIALES - CARRITO Y PEDIDOS (ANTES de apiResource)
    // ==========================================

    // CLIENTE: Crear un nuevo pedido con tipo de entrega
    Route::post('/cart/crear-pedido', [CartController::class, 'crearPedido']);

    // CLIENTE: Ver mis pedidos
    Route::get('/cart/mis-pedidos', [CartController::class, 'misPedidos']);

    // VENDEDOR: Ver pedidos pendientes de la tienda
    Route::get('/cart/pedidos-tienda', [CartController::class, 'pedidosTienda']);

    // DOMICILIARIO: Ver mis entregas asignadas
    Route::get('/cart/mis-entregas', [CartController::class, 'misEntregas']);

    // ✅ DOMICILIARIO: Ver pedidos disponibles para tomar
    Route::get('/cart/pedidos-disponibles', [CartController::class, 'pedidosDisponibles']);

    // ✅ DOMICILIARIO: Tomar un pedido disponible
    Route::put('/cart/{cart}/tomar-pedido', [CartController::class, 'tomarPedido']);

    // Ver carrito del usuario actual
    Route::get('/cart/view', [ProductController::class, 'viewCart']);

    // VENDEDOR: Actualizar estado del pedido
    Route::put('/cart/{cart}/estado', [CartController::class, 'actualizarEstado']);

    // VENDEDOR: Asignar domiciliario a un pedido
    Route::put('/cart/{cart}/asignar-domiciliario', [CartController::class, 'asignarDomiciliario']);

    // DOMICILIARIO: Marcar pedido como entregado
    Route::put('/cart/{cart}/marcar-entregado', [CartController::class, 'marcarEntregado']);

    // CLIENTE: Marcar pedido como recogido (recogida en tienda)
    Route::put('/cart/{cart}/marcar-recogido', [CartController::class, 'marcarRecogido']);

    // Agregar producto al carrito (método antiguo, mantener por compatibilidad)
    Route::post('/cart/{cart}/products', [CartController::class, 'addProduct']);

    // Eliminar producto del carrito
    Route::delete('/cart/{cart}/products/{product}', [CartController::class, 'removeProduct']);

    // Actualizar cantidad de producto en el carrito
    Route::put('/cart/{cart}/products/{product}', [CartController::class, 'updateQuantity']);

    // Carritos (CRUD básico)
    Route::apiResource('cart', CartController::class);

    // Transacciones de pago
    Route::apiResource('transaction', PaymentTransactionController::class);
    Route::apiResource('payment-transaction', PaymentTransactionController::class);

    // Confirmar transacción (vendedor/admin marca como pagado)
    Route::post('/payment-transaction/{paymentTransaction}/confirm', [PaymentTransactionController::class, 'confirm']);

    // Servicios (para domiciliarios)
    Route::apiResource('service', ServiceController::class);

    // Envíos/Envíos (Shipments)
    Route::apiResource('shipment', ShipmentController::class);

    // Shipping (diferente de Shipment)
    Route::apiResource('shipping', ShippingController::class);

    // Vehículos (para domiciliarios)
    Route::apiResource('vehicle', VehicleController::class);

    // ==========================================
    // MÉTODOS ESPECIALES - PRODUCTOS
    // ==========================================

    // Agregar producto al carrito (método especial de ProductController)
    Route::post('/product/{product}/add-to-cart', [ProductController::class, 'addToCart']);

    // Procesar compra (checkout)
    Route::post('/cart/checkout', [ProductController::class, 'checkout']);

    // ==========================================
    // MÉTODOS ESPECIALES - SERVICIOS (Domiciliarios)
    // ==========================================

    // Obtener servicios disponibles (para asignar pedidos)
    Route::get('/service/disponibles', [ServiceController::class, 'disponibles']);

    // Actualizar estado de disponibilidad
    Route::put('/service/{service}/estado', [ServiceController::class, 'updateEstado']);

    // ==========================================
    // MÉTODOS ESPECIALES - ENVÍOS (Shipments)
    // ==========================================

    // Obtener pedidos disponibles para domiciliarios
    Route::get('/shipment/disponibles', [ShipmentController::class, 'disponibles']);

    // Aceptar pedido (domiciliario)
    Route::put('/shipment/{shipment}/aceptar', [ShipmentController::class, 'aceptar']);

    // Completar pedido (domiciliario)
    Route::put('/shipment/{shipment}/completar', [ShipmentController::class, 'completar']);

    // Actualizar estado del envío
    Route::put('/shipment/{shipment}/estado', [ShipmentController::class, 'update']);

    // ==========================================
    // MÉTODOS ESPECIALES - TRANSACCIONES
    // ==========================================

    // Crear transacción de pago desde el carrito
    Route::post('/transaction/from-cart', [PaymentTransactionController::class, 'createFromCart']);

    // ==========================================
    // MÉTODOS ESPECIALES - CHAT
    // ==========================================

    // CHAT: Obtener mensajes de un pedido
    Route::get('/chat/{id_pedido}', [ChatController::class, 'getMessages']);

    // CHAT: Enviar un mensaje en el pedido
    Route::post('/chat/{id_pedido}/enviar', [ChatController::class, 'sendMessage']);

    // CHAT: Obtener todas las conversaciones del usuario
    Route::get('/conversaciones', [ChatController::class, 'getConversations']);

    // CHAT: Marcar mensaje como leído (futuro)
    Route::post('/chat/mensaje/{id}/leer', [ChatController::class, 'markAsRead']);

});

