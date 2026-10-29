<?php
/* POST api/admin/login.php → inicia sesión con email y contraseña */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

exigir_metodo('POST');
exigir_mismo_origen();

$d = leer_json();
$email = mb_strtolower(texto($d['email'] ?? '', 120));
$password = (string) ($d['password'] ?? '');

$max = (int) $CONFIG['sesion']['max_intentos'];
$min = (int) $CONFIG['sesion']['bloqueo_min'];
if (intentos_recientes('login_ip', ip_cliente(), $min) >= $max * 2 || intentos_recientes('login_email', $email, $min) >= $max) {
    throw new ErrorApp("Demasiados intentos fallidos. Espera {$min} minutos.", 429);
}

$st = db()->prepare('SELECT id, nombre, email, password_hash FROM usuarios WHERE email = ? AND activo = 1');
$st->execute([$email]);
$usuario = $st->fetch();

// Se comprueba siempre una contraseña (aunque el email no exista) para no revelar qué emails son válidos
$hash = $usuario['password_hash'] ?? cifrar_password('contraseña-ficticia');
if (!password_verify($password, $hash) || !$usuario) {
    registrar_intento('login_ip', ip_cliente());
    registrar_intento('login_email', $email);
    throw new ErrorApp('Email o contraseña incorrectos.', 401);
}

// Actualiza el cifrado si PHP ofrece uno mejor
if (password_needs_rehash($usuario['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([cifrar_password($password), $usuario['id']]);
}

limpiar_intentos('login_email', $email);
abrir_sesion($usuario);
responder(200, ['usuario' => ['nombre' => $usuario['nombre'], 'email' => $usuario['email']], 'csrf' => token_csrf()]);
