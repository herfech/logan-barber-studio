<?php
/* =========================================================
   Logan Barber Studio · Configuración
   ---------------------------------------------------------
   1. Copia este archivo como  includes/config.php
   2. Rellena los datos de tu base de datos de Hostinger
      (hPanel → Bases de datos → MySQL)
   3. Cambia las dos claves secretas por textos largos y aleatorios

   ⚠ config.php contiene contraseñas: no lo compartas ni lo subas
     a GitHub (ya está en .gitignore).
   ========================================================= */

return [
    // --- Base de datos MySQL ---
    'db' => [
        'host'     => 'localhost',          // En Hostinger suele ser "localhost"
        'nombre'   => 'u123456789_logan',   // Nombre de la base de datos
        'usuario'  => 'u123456789_logan',   // Usuario de la base de datos
        'clave'    => 'CAMBIA_ESTA_CLAVE',  // Contraseña de la base de datos
        'puerto'   => 3306,
    ],

    // --- Zona horaria del salón (afecta a horarios y disponibilidad) ---
    'zona_horaria' => 'Africa/Malabo',  // Guinea Ecuatorial (UTC+1)

    // --- Clave de instalación ---
    // Solo se usa UNA vez, para crear la primera cuenta del panel en admin.html.
    // Cuando ya exista una cuenta, esta clave deja de funcionar.
    'clave_instalacion' => 'CAMBIA_ESTO_por_una_frase_larga_y_secreta',

    // --- Secreto interno ---
    // Se usa para cifrar las IP en el registro anti-spam. Pon 40+ caracteres aleatorios.
    'secreto' => 'CAMBIA_ESTO_por_40_o_mas_caracteres_aleatorios_1234567890',

    // --- Prefijo telefónico del país: se añade a los números de 9 cifras ---
    'prefijo_telefono' => '+240',

    // --- Reglas de reserva ---
    'reservas' => [
        'paso_min'          => 30,  // minutos entre franjas horarias
        'dias_reservables'  => 60,  // hasta cuántos días vista se puede reservar
        'margen_hoy_min'    => 30,  // antelación mínima para reservar hoy
        'max_por_hora_ip'   => 5,   // reservas máximas por IP y hora (anti-spam)
    ],

    // --- Panel de administración ---
    'sesion' => [
        'inactividad_min' => 120,   // cierra la sesión tras 2 h sin actividad
        'max_intentos'    => 5,     // intentos de acceso fallidos permitidos…
        'bloqueo_min'     => 15,    // …en este número de minutos
    ],

    // Muestra errores detallados. Déjalo en false en la web publicada.
    'depuracion' => false,
];
