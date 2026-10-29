<?php
/* GET  api/admin/servicios.php                              → todos los servicios
   POST api/admin/servicios.php {id, precio, duracion_min, activo} → actualiza un servicio */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/agenda.php';

exigir_metodo('GET', 'POST');
exigir_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, listar_servicios_admin());
}
responder(200, actualizar_servicio(leer_json()));
