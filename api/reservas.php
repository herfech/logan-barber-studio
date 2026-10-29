<?php
/* POST api/reservas.php → crea una reserva */
declare(strict_types=1);
require_once __DIR__ . '/../includes/agenda.php';

exigir_metodo('POST');
exigir_mismo_origen();

$datos = leer_json();

// Trampa anti-bots: campo oculto que una persona nunca rellena
if (!empty($datos['web'])) {
    throw new ErrorApp('No se ha podido completar la reserva.', 400);
}

// Límite de reservas por IP y hora
$maximo = (int) $CONFIG['reservas']['max_por_hora_ip'];
if (intentos_recientes('reserva', ip_cliente(), 60) >= $maximo) {
    throw new ErrorApp('Has hecho demasiadas reservas en poco tiempo. Llámanos si necesitas más citas.', 429);
}

$reserva = crear_reserva($datos);
registrar_intento('reserva', ip_cliente());
responder(201, $reserva);
