<?php
/* GET api/admin/estado.php → ¿hay sesión? ¿falta crear la primera cuenta? */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

exigir_metodo('GET');
$configurado = (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
$usuario = $configurado ? usuario_actual() : null;

responder(200, [
    'configurado' => $configurado,
    'usuario'     => $usuario ? ['nombre' => $usuario['nombre'], 'email' => $usuario['email']] : null,
    'csrf'        => $usuario ? token_csrf() : null,
]);
