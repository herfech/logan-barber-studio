<?php
/* =========================================================
   Logan Barber Studio · Sesiones del panel de administración
   ---------------------------------------------------------
   - Cookie de sesión HttpOnly + SameSite=Strict (+ Secure en HTTPS)
   - Nuevo identificador de sesión al entrar (evita secuestro de sesión)
   - Token CSRF obligatorio en todas las acciones que modifican datos
   - Cierre automático por inactividad
   ========================================================= */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('logan_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Devuelve el usuario conectado o null. Renueva el tiempo de inactividad. */
function usuario_actual(): ?array
{
    global $CONFIG;
    iniciar_sesion();
    if (empty($_SESSION['usuario_id'])) return null;

    $limite = ($CONFIG['sesion']['inactividad_min'] ?? 120) * 60;
    if (time() - ($_SESSION['ultima_actividad'] ?? 0) > $limite) {
        cerrar_sesion();
        return null;
    }
    $_SESSION['ultima_actividad'] = time();

    $st = db()->prepare('SELECT id, nombre, email FROM usuarios WHERE id = ? AND activo = 1');
    $st->execute([$_SESSION['usuario_id']]);
    $usuario = $st->fetch() ?: null;
    if (!$usuario) cerrar_sesion();
    return $usuario;
}

function token_csrf(): string
{
    iniciar_sesion();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** Para todos los endpoints del panel. En POST exige además el token CSRF. */
function exigir_admin(): array
{
    $usuario = usuario_actual();
    if (!$usuario) throw new ErrorApp('Sesión no válida o caducada. Vuelve a entrar.', 401);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        exigir_mismo_origen();
        $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($enviado) || !hash_equals(token_csrf(), $enviado)) {
            throw new ErrorApp('Token de seguridad no válido. Recarga la página.', 403);
        }
    }
    return $usuario;
}

function abrir_sesion(array $usuario): void
{
    iniciar_sesion();
    session_regenerate_id(true);
    $_SESSION = [
        'usuario_id'       => (int) $usuario['id'],
        'ultima_actividad' => time(),
        'csrf'             => bin2hex(random_bytes(32)),
    ];
    db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([$usuario['id']]);
}

function cerrar_sesion(): void
{
    iniciar_sesion();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_destroy();
}

/** Requisitos mínimos de contraseña. */
function validar_password(string $password): void
{
    if (mb_strlen($password) < 10) throw new ErrorApp('La contraseña debe tener al menos 10 caracteres.');
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new ErrorApp('La contraseña debe combinar letras y números.');
    }
}

function cifrar_password(string $password): string
{
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}
