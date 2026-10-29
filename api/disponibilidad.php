<?php
/* GET api/disponibilidad.php?fecha=YYYY-MM-DD&servicio=ID&profesional=ID|any → horas del día y si están libres */
declare(strict_types=1);
require_once __DIR__ . '/../includes/agenda.php';

exigir_metodo('GET');
responder(200, obtener_disponibilidad($_GET));
