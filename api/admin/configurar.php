<?php
/* POST api/admin/configurar.php → crea la PRIMERA cuenta del panel.
   Solo funciona si no existe ninguna cuenta y con la clave de instalación de config.php */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

exigir_metodo('POST');
exigir_mismo_origen();

if (intentos_recientes('instalacion', ip_cliente(), 15) >= 5) {
    throw new ErrorApp('Demasiados intentos. Espera 15 minutos.', 429);
}

$d = leer_json();
$clave = (string) ($d['clave_instalacion'] ?? '');
if (str_starts_with($CONFIG['clave_instalacion'], 'CAMBIA_ESTO')) {
    throw new ErrorApp('Primero cambia "clave_instalacion" en includes/config.php.', 403);
}
if (!hash_equals($CONFIG['clave_instalacion'], $clave)) {
    registrar_intento('instalacion', ip_cliente());
    throw new ErrorApp('Clave de instalación incorrecta.', 403);
}

$nombre = texto($d['nombre'] ?? '', 80);
$email = mb_strtolower(texto($d['email'] ?? '', 120));
$password = (string) ($d['password'] ?? '');
if (mb_strlen($nombre) < 2) throw new ErrorApp('Indica tu nombre.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErrorApp('El email no es válido.');
validar_password($password);

$usuario = transaccion(function (PDO $pdo) use ($nombre, $email, $password) {
    // Bloquea la tabla para que nadie más pueda crear una cuenta a la vez
    if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios FOR UPDATE')->fetchColumn() > 0) {
        throw new ErrorApp('El panel ya está configurado. Inicia sesión.', 409);
    }
    $pdo->prepare('INSERT INTO usuarios (nombre, email, password_hash) VALUES (?, ?, ?)')
        ->execute([$nombre, $email, cifrar_password($password)]);
    return ['id' => (int) $pdo->lastInsertId(), 'nombre' => $nombre, 'email' => $email];
});

abrir_sesion($usuario);
responder(201, ['usuario' => ['nombre' => $nombre, 'email' => $email], 'csrf' => token_csrf()]);
