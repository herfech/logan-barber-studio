<?php
/* GET api/admin/resumen.php → indicadores del panel */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/agenda.php';

exigir_metodo('GET');
exigir_admin();
responder(200, resumen_panel());
