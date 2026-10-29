<?php
/* GET api/catalogo.php → categorías, servicios, equipo y horario */
declare(strict_types=1);
require_once __DIR__ . '/../includes/agenda.php';

exigir_metodo('GET');
responder(200, obtener_catalogo());
