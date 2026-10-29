<?php
/* GET  api/admin/reservas.php?desde=&hasta=&estado= → agenda
   POST api/admin/reservas.php {id, estado}           → cambia el estado de una cita */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/agenda.php';

exigir_metodo('GET', 'POST');
exigir_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, listar_reservas($_GET));
}
$d = leer_json();
responder(200, cambiar_estado_reserva($d['id'] ?? null, $d['estado'] ?? null));
