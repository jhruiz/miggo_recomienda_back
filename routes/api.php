<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProviderProfileController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteRequestController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\RatingController;
use App\Http\Responses\ApiResponse;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (prefijo /api/v1)
|--------------------------------------------------------------------------
| El prefijo /api/v1 se configura en bootstrap/app.php (apiPrefix).
| Todas las respuestas usan el envelope estándar { data, message, errors?, meta? }
| provisto por App\Http\Responses\ApiResponse.
|
| Los endpoints de dominio (auth, perfiles, solicitudes, cotizaciones,
| trabajos, calificaciones, chat, notificaciones) se agregan en tareas
| posteriores del plan de implementación.
*/

// Endpoint de salud/versión de la API. Útil para smoke tests del frontend.
Route::get('/health', function () {
    return ApiResponse::success(
        data: [
            'status' => 'ok',
            'api_version' => 'v1',
        ],
        message: 'API operativa.',
    );
});

/*
|--------------------------------------------------------------------------
| Módulo: Autenticación (/api/v1/auth)
|--------------------------------------------------------------------------
| Registro y verificación de correo (tarea 2.1).
| El login y logout se agregan en la tarea 2.3; el rate limiting del login
| en la tarea 2.5. No se declaran aquí para evitar colisiones.
*/
Route::prefix('auth')->group(function () {
    // Registro de nuevo usuario (público).
    Route::post('/register', [AuthController::class, 'register']);

    // Verificación de correo electrónico mediante enlace firmado (público).
    Route::post('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');

    // Reenvío del correo de verificación (requiere autenticación).
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('auth:sanctum');

    // --- Tarea 2.3 (login / logout) ------------------------------------
    // Inicio de sesión (público). La tarea 2.5 aplica aquí el middleware
    // de rate limiting por IP: `throttle:login` usa el limiter nombrado
    // `login` definido en App\Providers\AppServiceProvider (5 intentos por
    // IP en 15 min, bloqueo de 15 min, respuesta 429 con `Retry-After`).
    // El conteo de SOLO intentos fallidos lo gestiona el controlador vía
    // App\Support\LoginRateLimiter (hit en fallo, clear en éxito).
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Cierre de sesión: revoca el token activo (requiere autenticación).
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Módulo: Perfiles públicos de Prestador (/api/v1/providers) — tarea 5.4
|--------------------------------------------------------------------------
| Perfil público del Prestador (sin autenticación). El listado y búsqueda
| público (`GET /providers`, tarea 5.7) y el detalle (`GET /providers/{id}`,
| tarea 5.4) se exponen sin requerir autenticación.
*/
// Listado y búsqueda pública paginada de prestadores con filtros (tarea 5.7).
Route::get('/providers', [ProviderProfileController::class, 'index']);

Route::get('/providers/{id}', [ProviderProfileController::class, 'show'])
    ->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Módulo: Categorías de servicio (/api/v1/service-categories) — tarea 18.1
|--------------------------------------------------------------------------
| Catálogo público (sin paginar) de categorías de servicio. Lo consume el
| frontend para poblar los selectores de categoría (Requisitos 4.9, 13.7).
*/
Route::get('/service-categories', [ServiceCategoryController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Módulo: Ubicaciones (/api/v1/countries|departments|cities) — tarea 19.3
|--------------------------------------------------------------------------
| Catálogo geográfico público (sin paginar) en cascada país → departamento →
| ciudad (Requisitos 15.1, 15.2, 15.4, 15.5, 15.6, 15.7). De solo lectura; lo
| consume el frontend para poblar los selectores de ubicación.
|
| - GET /countries                          Todos los países (id, name, iso_code).
| - GET /departments?country_id={id}        Departamentos del país indicado.
| - GET /cities?department_id={id}          Ciudades del departamento indicado.
|
| Si el id de filtro falta o no existe, se devuelve una lista vacía con el
| mensaje "No se encontraron resultados." y HTTP 200 (nunca un error).
*/
Route::get('/countries', [LocationController::class, 'countries']);
Route::get('/departments', [LocationController::class, 'departments']);
Route::get('/cities', [LocationController::class, 'cities']);

/*
|--------------------------------------------------------------------------
| Módulo: Solicitudes de Cotización (/api/v1/quote-requests) — tarea 7.1
|--------------------------------------------------------------------------
| Creación, edición y cierre de Solicitudes_Cotización (Requisitos 4.1, 4.2,
| 4.6, 4.7). El listado y el detalle son públicos (sin auth) según el diseño;
| crear, editar y cerrar requieren autenticación (Cliente) y, en el caso de
| editar/cerrar, ser el Cliente dueño (verificado en el controlador, 403).
|
| La búsqueda/filtrado (Requisito 4.3) y la postulación de prestadores
| (Requisito 4.4) se agregan en la tarea 7.2. La selección de ganador
| (Requisito 4.5) la añade la tarea 7.5 sobre este mismo grupo de rutas.
*/
// Endpoints públicos.
Route::get('/quote-requests', [QuoteRequestController::class, 'index']);
Route::get('/quote-requests/{quoteRequest}', [QuoteRequestController::class, 'show'])
    ->whereNumber('quoteRequest');

// Endpoints autenticados (Cliente). PUT/close exigen además ser el dueño.
Route::middleware('auth:sanctum')->group(function () {
    // Postulaciones del Prestador autenticado. Segmento literal; el resto de
    // rutas con parámetro tienen whereNumber, por lo que no hay colisión.
    Route::get('/quote-requests/my-applications', [QuoteRequestController::class, 'myApplications'])
        ->middleware('role.provider');

    Route::post('/quote-requests', [QuoteRequestController::class, 'store']);

    Route::put('/quote-requests/{quoteRequest}', [QuoteRequestController::class, 'update'])
        ->whereNumber('quoteRequest');

    // Alta/baja de imágenes de referencia (tarea 20.4, Requisito 4.16).
    Route::post('/quote-requests/{quoteRequest}/reference-images', [QuoteRequestController::class, 'addReferenceImages'])
        ->whereNumber('quoteRequest');
    Route::delete('/quote-requests/{quoteRequest}/reference-images/{mediaId}', [QuoteRequestController::class, 'removeReferenceImage'])
        ->whereNumber('quoteRequest')
        ->whereNumber('mediaId');

    Route::post('/quote-requests/{quoteRequest}/close', [QuoteRequestController::class, 'close'])
        ->whereNumber('quoteRequest');

    // Selección del Prestador ganador (tarea 7.5, Requisito 4.5). Solo el
    // Cliente dueño; cambia la solicitud a "en proceso" y notifica al
    // Prestador seleccionado. 409 si no está "abierta"; 422 si el Prestador
    // no ha postulado.
    Route::post('/quote-requests/{quoteRequest}/select/{providerId}', [QuoteRequestController::class, 'select'])
        ->whereNumber('quoteRequest')
        ->whereNumber('providerId');

    // Postulación de un Prestador a la solicitud (tarea 7.2, Requisitos 4.4,
    // 4.8). Requiere rol `prestador` activo. Registra la postulación,
    // notifica al Cliente dueño y rechaza duplicadas / solicitudes no
    // "abiertas" con 409.
    Route::post('/quote-requests/{quoteRequest}/apply', [QuoteRequestController::class, 'apply'])
        ->middleware('role.provider')
        ->whereNumber('quoteRequest');

    // Listado de postulaciones de la solicitud, para el Cliente dueño (tarea
    // 7.2, Requisito 4.4). 403 si el solicitante no es el dueño.
    Route::get('/quote-requests/{quoteRequest}/applications', [QuoteRequestController::class, 'applications'])
        ->whereNumber('quoteRequest');
});

/*
|--------------------------------------------------------------------------
| Módulo: Cotizaciones (/api/v1/quotes) — tarea 8.1
|--------------------------------------------------------------------------
| Creación de Cotizaciones estandarizadas con ítems, cálculo del total y
| adjuntos (Requisitos 5.1-5.6, 6.1, 6.5). Todas requieren autenticación.
|
| - POST /quotes                 Crear cotización. Requiere rol `prestador`
|   activo (middleware `role.provider`). Valida rangos (422 sin persistir) y
|   rechaza con 409 si la solicitud no está "abierta".
| - GET  /quotes/request/{id}    Vista comparativa de cotizaciones de una
|   solicitud (Cliente dueño). Se declara ANTES de /quotes/{id} para que el
|   segmento literal `request` no sea capturado como un id numérico.
| - GET  /quotes/{id}            Detalle de una cotización (partes involucradas).
|
| La aceptación/rechazo (POST /{id}/accept, /{id}/reject) la añade la tarea 8.5
| sobre este mismo grupo, de forma aditiva.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/quotes', [QuoteController::class, 'store'])
        ->middleware('role.provider');

    // Cotizaciones directas (tarea 11.1, Requisitos 10.1-10.6). Se declaran
    // ANTES de /quotes/{id} para que el segmento literal `direct` no sea
    // capturado por la ruta de detalle. La solicitud directa (POST
    // /quotes/direct) la hace el Cliente autenticado hacia un Prestador
    // concreto: no exige rol `prestador` del solicitante (422 si el objetivo no
    // es Prestador activo, 409 si se supera el límite de 5 activas). La
    // respuesta (POST /quotes/direct/{id}/respond) solo la puede dar el
    // Prestador objetivo mientras la Cotización está "solicitada".
    Route::post('/quotes/direct', [QuoteController::class, 'requestDirect']);

    Route::post('/quotes/direct/{quote}/respond', [QuoteController::class, 'respondDirect'])
        ->whereNumber('quote');

    // Listados del usuario autenticado (literales, antes de /quotes/{quote}).
    Route::get('/quotes/sent', [QuoteController::class, 'sent'])
        ->middleware('role.provider');

    Route::get('/quotes/received', [QuoteController::class, 'received']);

    Route::get('/quotes/request/{quoteRequest}', [QuoteController::class, 'forRequest'])
        ->whereNumber('quoteRequest');

    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])
        ->whereNumber('quote');

    // Aceptación/rechazo de la Cotización por el Cliente receptor (tarea 8.5,
    // Requisitos 5.7, 5.8, 6.4). Solo el Cliente receptor (403 en otro caso).
    // Al aceptar: la Cotización pasa a "aceptada", las demás de la Solicitud a
    // "no_seleccionada" y se crea el Trabajo asociado. Al rechazar: pasa a
    // "rechazada" y se notifica al Prestador. Una Cotización "expirada" (o en
    // cualquier estado que no sea "pendiente") produce 409.
    Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])
        ->whereNumber('quote');

    Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject'])
        ->whereNumber('quote');
});

/*
|--------------------------------------------------------------------------
| Módulo: Trabajos (/api/v1/jobs) — tarea 9.1
|--------------------------------------------------------------------------
| Listado y detalle de Trabajos (Requisitos 7.1, 7.2). Ambos endpoints
| requieren autenticación (auth:sanctum).
|
| - GET /jobs        Lista los Trabajos del usuario autenticado (como Cliente
|   o como Prestador).
| - GET /jobs/{id}   Detalle de un Trabajo. Solo las partes involucradas; 403
|   en otro caso (verificado en el controlador).
|
| La creación del Trabajo NO se expone como endpoint: ocurre al aceptar una
| Cotización vía QuoteService (tarea 8.5), que invoca JobService::createJob().
| Las acciones de completar/confirmar/cancelar (tareas 9.2, 9.4) se añadirán
| aquí de forma aditiva.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/jobs', [JobController::class, 'index']);

    Route::get('/jobs/{job}', [JobController::class, 'show'])
        ->whereNumber('job');

    // --- Tarea 9.2: completar / confirmar / rechazar finalización ----------
    // (Requisitos 7.3, 7.4, 7.5, 7.6). Todas exigen ser una de las partes;
    // el controlador verifica el rol concreto (Prestador para completar,
    // Cliente para confirmar/rechazar) y responde 403/409/422 según el caso.

    // El Prestador marca el Trabajo como completado (Requisito 7.3): registra
    // la fecha real, notifica al Cliente y despacha AutoCompleteJobJob (7 días).
    Route::post('/jobs/{job}/complete', [JobController::class, 'complete'])
        ->whereNumber('job');

    // El Cliente confirma la finalización (Requisito 7.4): estado "completado",
    // habilita Calificaciones y recalcula Índice_Cumplimiento (vía seams).
    Route::post('/jobs/{job}/confirm', [JobController::class, 'confirm'])
        ->whereNumber('job');

    // El Cliente rechaza la finalización (Requisito 7.6): mantiene "en progreso",
    // guarda el motivo (≤500) y notifica al Prestador.
    Route::post('/jobs/{job}/reject-completion', [JobController::class, 'rejectCompletion'])
        ->whereNumber('job');

    // --- Tarea 9.4: cancelación por mutuo acuerdo --------------------------
    // (Requisitos 7.7, 7.8). Ambas exigen ser una de las partes (el controlador
    // responde 403/409/422 según el caso).

    // Una parte solicita cancelar (Requisito 7.8): marca su flag, guarda su
    // motivo (≤500), mantiene "en progreso" y notifica a la otra parte. Si la
    // contraparte ya solicitó, el Trabajo pasa a "cancelado" (Requisito 7.7).
    Route::post('/jobs/{job}/request-cancel', [JobController::class, 'requestCancel'])
        ->whereNumber('job');

    // La otra parte confirma la cancelación mutua (Requisito 7.7): al quedar
    // ambos flags en true, el estado pasa a "cancelado" conservando ambos
    // motivos.
    Route::post('/jobs/{job}/confirm-cancel', [JobController::class, 'confirmCancel'])
        ->whereNumber('job');
});

/*
|--------------------------------------------------------------------------
| Módulo: Calificaciones (/api/v1/ratings) — tarea 12.1
|--------------------------------------------------------------------------
| Emisión y consulta de Calificaciones bidireccionales (Requisitos 8.1, 8.2,
| 8.3, 8.4, 8.6, 8.7). Ambos endpoints requieren autenticación (auth:sanctum).
|
| - POST /ratings            Emite una Calificación para un Trabajo. La
|   validación de score (entero 1-5) y comentario (10-500) se hace en
|   StoreRatingRequest (422 sin persistir). El calificador es el usuario
|   autenticado; el calificado es la otra parte del Trabajo. Rechaza con 409 si
|   las calificaciones no están habilitadas, si ya calificó (duplicada) o si la
|   ventana de 30 días se cerró; 403 si no es parte del Trabajo.
| - GET  /ratings/job/{job}  Lista las Calificaciones emitidas de un Trabajo.
|   Solo las partes involucradas (403 en otro caso).
|
| La habilitación de las Calificaciones NO se expone como endpoint: ocurre al
| completarse un Trabajo vía el seam JobCompletionHandler (RatingsJobCompletionHandler),
| que invoca RatingService::enableRatings().
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/ratings', [RatingController::class, 'store']);

    Route::get('/ratings/job/{job}', [RatingController::class, 'forJob'])
        ->whereNumber('job');
});

/*
|--------------------------------------------------------------------------
| Módulo: Perfiles (/api/v1/profile) — tarea 4.1
|--------------------------------------------------------------------------
| Doble perfil y gestión de roles. Todos los endpoints requieren
| autenticación (auth:sanctum).
|
| COORDINACIÓN con la tarea 4.3: la autorización por rol `prestador` activo
| (Requisito 2.6) la añade 4.3 vía un middleware/Gate aplicado sobre los
| endpoints de prestador de abajo. Aquí solo se exige auth:sanctum; NO se
| implementa aquí la verificación de rol para no pisar el trabajo de 4.3.
*/
Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    // Actualizar datos de identidad compartidos del usuario (Requisito 2.5).
    Route::put('/', [ProfileController::class, 'update']);

    // Foto de perfil: cualquier usuario autenticado (sin role.provider).
    Route::post('/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('/avatar', [ProfileController::class, 'destroyAvatar']);

    // Activar rol de Prestador: crea provider_profile + asigna rol prestador
    // sin remover cliente, en transacción (Requisitos 2.2, 2.3).
    Route::post('/provider/activate', [ProfileController::class, 'activateProvider']);

    // Actualizar datos del perfil de prestador (descripción, ubicación).
    // 4.3 añadirá aquí la verificación del rol `prestador` activo.
    Route::put('/provider', [ProfileController::class, 'updateProvider'])
        ->middleware('role.provider');

    /*
    |----------------------------------------------------------------------
    | Portafolios del Prestador (spec portafolios-multiples) — rol prestador
    |----------------------------------------------------------------------
    | CRUD de Portafolios y de las fotos (ítems) de cada uno. Se exige rol
    | `prestador` activo (`role.provider`) además de auth:sanctum. No existe
    | ruta para mover una foto entre Portafolios (Req. 2.10).
    */
    Route::middleware('role.provider')->prefix('provider/portfolios')->group(function () {
        Route::get('/', [PortfolioController::class, 'index']);
        Route::post('/', [PortfolioController::class, 'store']);
        Route::get('/{portfolio}', [PortfolioController::class, 'show']);
        Route::put('/{portfolio}', [PortfolioController::class, 'update']);
        Route::delete('/{portfolio}', [PortfolioController::class, 'destroy']);

        Route::post('/{portfolio}/items', [PortfolioItemController::class, 'store']);

        // scopeBindings: un ítem que no pertenece al Portafolio de la URL da 404.
        Route::scopeBindings()->group(function () {
            Route::put('/{portfolio}/items/{item}', [PortfolioItemController::class, 'update']);
            Route::delete('/{portfolio}/items/{item}', [PortfolioItemController::class, 'destroy']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Módulo: Chat (/api/v1/chat) — tarea 13.3
|--------------------------------------------------------------------------
| Envío, historial y listado de canales de mensajería (Requisitos 11.4, 11.5,
| 11.6, 11.7, 11.8). Todos los endpoints requieren autenticación (auth:sanctum)
| y, además, que el usuario sea participante del canal (Cliente o Prestador),
| verificado en el controlador (403 en otro caso).
|
| - GET  /chat/channels                     Lista los canales del usuario.
| - GET  /chat/channels/{channel}/messages  Historial paginado por cursor (50),
|   ordenado cronológicamente (Requisito 11.6).
| - POST /chat/channels/{channel}/messages  Envía un mensaje. El contenido
|   (1-2000) se valida en SendChatMessageRequest (422 sin persistir, Req 11.8);
|   un canal "cerrado" produce 409 (Req 11.7). Al persistir, transmite el evento
|   NewChatMessage por Reverb (Req 11.4).
|
| La notificación de mensaje no leído (Requisito 11.9) y la UI de chat
| pertenecen a la tarea 13.6 y se añadirán de forma aditiva.
*/
Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
    Route::get('/channels', [ChatController::class, 'channels']);

    Route::get('/channels/{channel}/messages', [ChatController::class, 'messages'])
        ->whereNumber('channel');

    Route::post('/channels/{channel}/messages', [ChatController::class, 'send'])
        ->whereNumber('channel');
});

/*
|--------------------------------------------------------------------------
| Módulo: Notificaciones (/api/v1/notifications) — tarea 14.2b
|--------------------------------------------------------------------------
| Centro de notificaciones in-app del Usuario (Requisitos 12.3, 12.4, 12.5,
| 12.7). Todos los endpoints requieren autenticación (auth:sanctum) y operan
| solo sobre las notificaciones del propio Usuario autenticado (aislamiento
| por `user_id` en el servicio; marcar una ajena produce 404).
|
| - GET /notifications              Lista las notificaciones del usuario,
|   ordenadas por fecha descendente y acotadas a 200 (Requisito 12.3).
| - GET /notifications/unread-count Contador de no leídas; display "99+" si
|   supera 100 (Requisito 12.5).
| - PUT /notifications/{id}/read    Marca una notificación propia como leída
|   (Requisito 12.4). 404 si no existe o no es del usuario.
| - PUT /notifications/read-all     Marca todas las no leídas como leídas
|   (Requisito 12.7).
|
| `unread-count` y `read-all` se declaran como segmentos literales y, junto a
| la restricción whereNumber del id, evitan colisiones con /{id}/read.
*/
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);

    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);

    Route::put('/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::put('/{id}/read', [NotificationController::class, 'markAsRead'])
        ->whereNumber('id');
});

// Usuario autenticado (Sanctum).
//
// Incluye los roles del usuario (`cliente`, `prestador`) para que el frontend
// pueda determinar qué vistas habilitar y permitir la alternancia de rol
// activo (Requisito 2.4). El rol activo NO se persiste en el servidor: se
// gestiona en el store Pinia del cliente (ver design.md, decisión 5).
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return ApiResponse::success(
        data: app(ProfileService::class)->presentIdentity($request->user()),
        message: 'Usuario autenticado.',
    );
});
