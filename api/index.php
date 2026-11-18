<?php
/**
 * -------------------------------------------------------------
 * Backend EndPoint Único en PHP - SP2-3
 * -------------------------------------------------------------
 * Soporte para Supabase (PostgreSQL) y MySQL, con resiliencia
 * para entornos Serverless como Vercel y ejecución local.
 */

// Configuración de sesión segura compatible con Serverless (Vercel)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @session_start();
}

// Configuración de cabeceras y errores
error_reporting(0);
date_default_timezone_set("America/Mexico_City");
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('RUTA_DATOS', __DIR__ . '/data/tarjetas.json');
define('APP_SECRET_KEY', 'sp2-3_supabase_session_secret_key_2026');

// Inclusión del módulo de conexión a la base de datos
require_once __DIR__ . '/conectdb.php';

/**
 * -------------------------------------------
 * Utilidades de respuesta JSON
 * -------------------------------------------
 */
function responder($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function responderExito($mensaje, $data = null) {
    responder(200, [
        'success' => true,
        'message' => $mensaje,
        'data'    => $data,
    ]);
}

function responderError($mensaje, $statusCode = 400) {
    responder($statusCode, [
        'success' => false,
        'error'   => $mensaje
    ]);
}

/**
 * Genera un token firmado para persistencia entre instancias serverless
 */
function generarTokenUsuario($userId, $nickname, $nombre) {
    $payload = json_encode([
        'id'       => $userId,
        'nickname' => $nickname,
        'nombre'   => $nombre,
        'exp'      => time() + (86400 * 7)
    ]);
    $firma = hash_hmac('sha256', $payload, APP_SECRET_KEY);
    return base64_encode($payload . '::' . $firma);
}

/**
 * Valida el token firmado de usuario
 */
function validarTokenUsuario($token) {
    if (!$token) return null;
    $decoded = base64_decode($token, true);
    if (!$decoded || strpos($decoded, '::') === false) return null;
    list($payload, $firma) = explode('::', $decoded, 2);
    $esperada = hash_hmac('sha256', $payload, APP_SECRET_KEY);
    if (!hash_equals($esperada, $firma)) return null;
    $data = json_decode($payload, true);
    if (!$data || !isset($data['exp']) || $data['exp'] < time()) return null;
    return $data;
}

/**
 * ------------------------------------------------------
 * Autenticación y Control de Sesión
 * ------------------------------------------------------
 */
function obtenerUsuarioActual() {
    // 1. Verificar sesión PHP en memoria / archivo
    if (!empty($_SESSION['usuario_id'])) {
        return [
            'id'       => (int)$_SESSION['usuario_id'],
            'nombre'   => $_SESSION['usuario_nombre'] ?? '',
            'nickname' => $_SESSION['usuario_nickname'] ?? ''
        ];
    }

    // 2. Respaldo mediante cookie firmada (resuelve pérdida de sesión por lambdas serverless)
    $cookieToken = $_COOKIE['app_auth_token'] ?? null;
    if ($cookieToken) {
        $tokenData = validarTokenUsuario($cookieToken);
        if ($tokenData) {
            $_SESSION['usuario_id']       = $tokenData['id'];
            $_SESSION['usuario_nombre']   = $tokenData['nombre'];
            $_SESSION['usuario_nickname'] = $tokenData['nickname'];
            return [
                'id'       => (int)$tokenData['id'],
                'nombre'   => $tokenData['nombre'],
                'nickname' => $tokenData['nickname']
            ];
        }
    }

    return null;
}

function requiereAuth() {
    $user = obtenerUsuarioActual();
    if (!$user) {
        responderError('No autenticado. Inicia sesión.', 401);
    }
    return $user['id'];
}

/**
 * -------------------------------------------------------------------
 * Acceso a datos de Tarjetas (Persistencia en Supabase con Respaldo)
 * -------------------------------------------------------------------
 */
function leerTarjetasJson() {
    if (!file_exists(RUTA_DATOS)) {
        return [];
    }
    $contenido = @file_get_contents(RUTA_DATOS);
    $tarjetas = json_decode($contenido, true);
    return is_array($tarjetas) ? $tarjetas : [];
}

function guardarTarjetasJson($tarjetas) {
    try {
        $carpeta = dirname(RUTA_DATOS);
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0755, true);
        }
        return @file_put_contents(RUTA_DATOS, json_encode($tarjetas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    } catch (Exception $e) {
        return false;
    }
}

function obtenerTarjetasBD($usuarioId) {
    // 1. Intentar consultar desde la Base de Datos (Supabase / MySQL)
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, bio, avatar_url AS \"avatarUrl\", color, usuario_id, created_at AS \"createdAt\" FROM tarjetas WHERE usuario_id = ? ORDER BY created_at ASC");
        $stmt->execute([$usuarioId]);
        $tarjetas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($tarjetas) && count($tarjetas) > 0) {
            return $tarjetas;
        }
    } catch (Exception $e) {
        error_log('Error leyendo tarjetas de BD: ' . $e->getMessage());
    }

    // 2. Fallback a tarjetas.json filtradas por usuario_id
    $tarjetasJson = leerTarjetasJson();
    $filtradas = array_filter($tarjetasJson, function($t) use ($usuarioId) {
        return isset($t['usuario_id']) && (int)$t['usuario_id'] === (int)$usuarioId;
    });

    return array_values($filtradas);
}

function guardarTarjetaBD($tarjeta) {
    $guardado = false;

    // 1. Guardar en Base de Datos Supabase / MySQL
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO tarjetas (id, name, bio, avatar_url, color, usuario_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $tarjeta['id'],
            $tarjeta['name'],
            $tarjeta['bio'] ?? '',
            $tarjeta['avatarUrl'] ?? ($tarjeta['avatar_url'] ?? ''),
            $tarjeta['color'] ?? '#3b82f6',
            $tarjeta['usuario_id'] ?? null,
            $tarjeta['createdAt'] ?? date('Y-m-d H:i:s')
        ]);
        $guardado = true;
    } catch (Exception $e) {
        error_log('Error guardando tarjeta en BD: ' . $e->getMessage());
    }

    // 2. Respaldo local si el sistema de archivos lo permite (no crítico si falla en Serverless)
    try {
        $tarjetas = leerTarjetasJson();
        $tarjetas[] = $tarjeta;
        guardarTarjetasJson($tarjetas);
    } catch (Exception $e) {}

    return $guardado;
}

/**
 * ------------------------------------------------------------
 * Acciones
 * ------------------------------------------------------------
 */
function accionRegistrarUsuario() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderError('Método no permitido. Usa POST.', 405);
    }

    $nombre     = trim($_POST['nombre'] ?? '');
    $nacimiento = trim($_POST['nacimiento'] ?? '');
    $educacion  = trim($_POST['educacion'] ?? '');
    $telefono   = trim($_POST['telefono'] ?? '');
    $horas      = trim($_POST['horas'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $nickname   = trim($_POST['nickname'] ?? '');
    $password   = $_POST['password'] ?? '';

    // Validar campos obligatorios
    if (!$nombre || !$email || !$nickname || !$password) {
        responderError('Por favor, completa todos los campos obligatorios.', 400);
    }

    // Tratamiento seguro de tipos de datos para PostgreSQL / Supabase
    $fechaNac = !empty($nacimiento) ? $nacimiento : null;
    $horasVal = ($horas !== '') ? floatval($horas) : null;

    $db = getDB();

    // Verificar si el email o nickname ya existen
    try {
        $stmtCheck = $db->prepare("SELECT id FROM usuarios WHERE LOWER(email) = LOWER(?) OR LOWER(nickname) = LOWER(?)");
        $stmtCheck->execute([$email, $nickname]);
        if ($stmtCheck->fetch()) {
            responderError('El correo electrónico o nickname ya están registrados.', 409);
        }
    } catch (Exception $e) {
        responderError('Error al consultar usuarios existentes: ' . $e->getMessage(), 500);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = "INSERT INTO usuarios (nombre_completo, fecha_nacimiento, nivel_educativo, telefono, horas_programando, email, nickname, password_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$nombre, $fechaNac, $educacion, $telefono, $horasVal, $email, $nickname, $hash]);
        responderExito('Usuario registrado correctamente. Ya puedes iniciar sesión.');
    } catch (Exception $e) {
        responderError('Ocurrió un error al registrar el usuario en la base de datos: ' . $e->getMessage(), 500);
    }
}

function accionLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderError('Método no permitido. Usa POST.', 405);
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        responderError('Por favor ingresa correo y contraseña.', 400);
    }

    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT id, nombre_completo, nickname, password_hash FROM usuarios WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        responderError('Error al consultar usuario: ' . $e->getMessage(), 500);
    }

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario_id']       = $user['id'];
        $_SESSION['usuario_nombre']   = $user['nombre_completo'];
        $_SESSION['usuario_nickname'] = $user['nickname'];

        // Token firmado en cookie para resiliencia serverless
        $token = generarTokenUsuario($user['id'], $user['nickname'], $user['nombre_completo']);
        setcookie('app_auth_token', $token, [
            'expires'  => time() + (86400 * 7),
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        responderExito('Inicio de sesión exitoso.', [
            'id'       => (int)$user['id'],
            'nombre'   => $user['nombre_completo'],
            'nickname' => $user['nickname']
        ]);
    } else {
        responderError('Correo o contraseña incorrectos.', 401);
    }
}

function accionLogout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    setcookie('app_auth_token', '', time() - 42000, '/');
    @session_destroy();
    responderExito('Sesión cerrada correctamente.');
}

function accionCheckSession() {
    $user = obtenerUsuarioActual();
    if ($user) {
        responderExito('Sesión activa.', $user);
    } else {
        responderError('No autenticado. Inicia sesión.', 401);
    }
}

function accionCrearTarjeta() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderError('Método no permitido. Usa POST.', 405);
    }

    $usuarioId = requiereAuth();

    $inputJSON = $_POST['datos_tarjeta'] ?? file_get_contents('php://input');
    $nuevaTarjeta = json_decode($inputJSON, true);

    if (!$nuevaTarjeta || empty($nuevaTarjeta['name'])) {
        responderError('Datos inválidos o incompletos.', 400);
    }

    $nuevaTarjeta['id']         = uniqid('user_');
    $nuevaTarjeta['createdAt']  = date('Y-m-d H:i:s');
    $nuevaTarjeta['usuario_id'] = (int)$usuarioId;

    guardarTarjetaBD($nuevaTarjeta);

    responderExito('Tarjeta guardada correctamente en el servidor.', $nuevaTarjeta);
}

function accionListarTarjetas() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        responderError('Método no permitido. Usa GET.', 405);
    }

    $usuarioId = requiereAuth();
    $tarjetas = obtenerTarjetasBD($usuarioId);

    responderExito('Tarjetas obtenidas correctamente.', $tarjetas);
}

/**
 * ----------------------------------------------------
 * Router
 * ----------------------------------------------------
 */
$action = $_GET['action'] ?? $_POST['action'] ?? null;

switch ($action) {
    case 'crear_tarjeta':
        accionCrearTarjeta();
        break;
    case 'listar_tarjetas':
        accionListarTarjetas();
        break;
    case 'registrar_usuario':
        accionRegistrarUsuario();
        break;
    case 'login':
        accionLogin();
        break;
    case 'logout':
        accionLogout();
        break;
    case 'check_session':
        accionCheckSession();
        break;
    default:
        responderError('Acción no reconocida o no especificada.', 404);
}
