<?php
/* POST api/admin/logout.php → cierra la sesión */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

exigir_metodo('POST');
exigir_admin();
cerrar_sesion();
responder(200, ['ok' => true]);
