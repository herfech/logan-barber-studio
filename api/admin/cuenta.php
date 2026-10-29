<?php
/* POST api/admin/cuenta.php {actual, nueva} → cambia la contraseña del usuario conectado */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

exigir_metodo('POST');
$usuario = exigir_admin();

if (intentos_recientes('cambio_clave', (string) $usuario['id'], 15) >= 5) {
    throw new ErrorApp('Demasiados intentos. Espera 15 minutos.', 429);
}

$d = leer_json();
$st = db()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
$st->execute([$usuario['id']]);
if (!password_verify((string) ($d['actual'] ?? ''), (string) $st->fetchColumn())) {
    registrar_intento('cambio_clave', (string) $usuario['id']);
    throw new ErrorApp('La contraseña actual no es correcta.', 403);
}

$nueva = (string) ($d['nueva'] ?? '');
validar_password($nueva);
db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([cifrar_password($nueva), $usuario['id']]);
session_regenerate_id(true);
responder(200, ['ok' => true]);
