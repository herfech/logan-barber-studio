<?php
/* =========================================================
   Logan Barber Studio · Configuración LOCAL (XAMPP)
   ---------------------------------------------------------
   ⚠ Contiene contraseñas: no lo compartas ni lo subas a GitHub.
     Para Hostinger crearás otro con los datos de hPanel.
   ========================================================= */

return [
    // --- Base de datos MySQL (XAMPP) ---
    'db' => [
        'host'     => 'localhost',
        'nombre'   => 'logan',   // la base de datos que creaste en phpMyAdmin
        'usuario'  => 'root',    // usuario por defecto de XAMPP
        'clave'    => '',        // en XAMPP va vacía
        'puerto'   => 3306,
    ],

    // --- Zona horaria del salón ---
    'zona_horaria' => 'Africa/Malabo',  // Guinea Ecuatorial (UTC+1)

    // --- Clave de instalación (solo para crear la primera cuenta del panel) ---
    'clave_instalacion' => 'logan-instalacion-2026',

    // --- Secreto interno (generado aleatoriamente) ---
    'secreto' => '097e8cbfc26350ab1657c1c6a302f616bb4041d9fab32279b59da57d94870159',

    // --- Prefijo telefónico del país ---
    'prefijo_telefono' => '+240',

    // --- Reglas de reserva ---
    'reservas' => [
        'paso_min'          => 30,
        'dias_reservables'  => 60,
        'margen_hoy_min'    => 30,
        'max_por_hora_ip'   => 5,
    ],

    // --- Panel de administración ---
    'sesion' => [
        'inactividad_min' => 120,
        'max_intentos'    => 5,
        'bloqueo_min'     => 15,
    ],

    // En tu ordenador muestra los errores para depurar. Pon false al publicar.
    'depuracion' => true,
];
