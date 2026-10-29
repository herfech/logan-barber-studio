<?php
/* =========================================================
   Logan Barber Studio · Arranque común de la API
   ---------------------------------------------------------
   Lo incluye cada archivo de /api. Se encarga de:
   - Cargar la configuración
   - Conectar con MySQL (PDO)
   - Responder siempre en JSON, también ante errores
   - Funciones auxiliares de seguridad
   ========================================================= */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Se necesita PHP 8.1 o superior.');
}

/* ---------- Configuración ---------- */
$rutaConfig = __DIR__ . '/config.php';
if (!is_file($rutaConfig)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'Falta includes/config.php. Copia config.example.php como config.php y rellénalo.']));
}
$CONFIG = require $rutaConfig;

date_default_timezone_set($CONFIG['zona_horaria'] ?? 'Africa/Malabo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ---------- Respuestas JSON y errores ---------- */
final class ErrorApp extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $estado = 400)
    {
        parent::__construct($mensaje);
    }
}

function responder(int $estado, mixed $datos): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) use ($CONFIG): void {
    if ($e instanceof ErrorApp) {
        responder($e->estado, ['error' => $e->getMessage()]);
    }
    error_log('[Logan API] ' . $e);
    $mensaje = !empty($CONFIG['depuracion']) ? $e->getMessage() : 'Error interno del servidor.';
    responder(500, ['error' => $mensaje]);
});

set_error_handler(function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
    throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

/* ---------- Conexión a la base de datos ---------- */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;

    $c = $CONFIG['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['puerto'] ?? 3306, $c['nombre']);
    try {
        $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // consultas preparadas reales (anti inyección SQL)
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        error_log('[Logan API] Conexión MySQL: ' . $e->getMessage());
        throw new ErrorApp('No se puede conectar con la base de datos. Revisa includes/config.php.', 503);
    }
    // Misma zona horaria en MySQL que en PHP
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}

function transaccion(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $resultado = $fn($pdo);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ---------- Peticiones ---------- */
function exigir_metodo(string ...$metodos): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $metodos, true)) {
        header('Allow: ' . implode(', ', $metodos));
        throw new ErrorApp('Método no permitido.', 405);
    }
}

/** Lee el cuerpo JSON de la petición (máx. 10 KB). */
function leer_json(): array
{
    $cuerpo = file_get_contents('php://input', false, null, 0, 10_241);
    if (strlen((string) $cuerpo) > 10_240) throw new ErrorApp('Petición demasiado grande.', 413);
    if ($cuerpo === '' || $cuerpo === false) return [];
    $datos = json_decode($cuerpo, true);
    if (!is_array($datos)) throw new ErrorApp('Datos no válidos.');
    return $datos;
}

/** Rechaza peticiones que modifican datos si vienen de otra web (protección CSRF básica). */
function exigir_mismo_origen(): void
{
    $origen = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origen === '') return; // algunos navegadores no lo envían en mismo origen
    $hostOrigen = parse_url($origen, PHP_URL_HOST);
    $hostPropio = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!$hostOrigen || strcasecmp($hostOrigen, (string) $hostPropio) !== 0) {
        throw new ErrorApp('Origen de la petición no permitido.', 403);
    }
}

function es_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/* ---------- Límite de intentos (anti fuerza bruta / spam) ---------- */
function huella(string $valor): string
{
    global $CONFIG;
    return hash_hmac('sha256', $valor, $CONFIG['secreto']);
}

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Cuenta los intentos recientes de una acción para una clave (IP, email…). */
function intentos_recientes(string $accion, string $clave, int $minutos): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM limites WHERE accion = ? AND clave = ? AND creado_en > (NOW() - INTERVAL ? MINUTE)');
    $st->execute([$accion, huella($clave), $minutos]);
    return (int) $st->fetchColumn();
}

function registrar_intento(string $accion, string $clave): void
{
    db()->prepare('INSERT INTO limites (accion, clave) VALUES (?, ?)')->execute([$accion, huella($clave)]);
    // Limpieza ocasional de registros antiguos
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM limites WHERE creado_en < (NOW() - INTERVAL 1 DAY)');
    }
}

function limpiar_intentos(string $accion, string $clave): void
{
    db()->prepare('DELETE FROM limites WHERE accion = ? AND clave = ?')->execute([$accion, huella($clave)]);
}

/* ---------- Utilidades de texto ---------- */
function texto(mixed $valor, int $max): string
{
    $valor = trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
    return mb_substr($valor, 0, $max);
}
