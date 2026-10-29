<?php
/* =========================================================
   Logan Barber Studio · Lógica de negocio y consultas
   ---------------------------------------------------------
   Todas las consultas a la base de datos están aquí.
   Los archivos de /api solo validan la petición y llaman a
   estas funciones.
   ========================================================= */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ESTADOS_RESERVA = ['confirmada', 'completada', 'cancelada', 'no_asistio'];

/* ---------- Utilidades de fecha y hora ---------- */
function a_minutos(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function a_hhmm(int $minutos): string
{
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

function validar_fecha(mixed $fecha): string
{
    global $CONFIG;
    $fecha = (string) $fecha;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) throw new ErrorApp('La fecha no es válida.');

    $hoy = new DateTimeImmutable('today');
    $dias = (int) $CONFIG['reservas']['dias_reservables'];
    if ($d < $hoy) throw new ErrorApp('No se puede reservar en una fecha pasada.');
    if ($d > $hoy->modify("+{$dias} days")) throw new ErrorApp("Solo se puede reservar con {$dias} días de antelación como máximo.");
    return $fecha;
}

function generar_codigo(): string
{
    $letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin caracteres confusos (0/O, 1/I)
    $codigo = 'LB-';
    for ($i = 0; $i < 6; $i++) $codigo .= $letras[random_int(0, strlen($letras) - 1)];
    return $codigo;
}

/* =========================================================
   PARTE PÚBLICA
   ========================================================= */

/** Categorías, servicios, equipo y horario para la web. */
function obtener_catalogo(): array
{
    global $CONFIG;
    $pdo = db();

    $categorias = $pdo->query('SELECT id, nombre, icono FROM categorias ORDER BY orden')->fetchAll();
    $servicios = $pdo->query('SELECT id, categoria_id AS cat, nombre, descripcion, precio, duracion_min AS duracion
                              FROM servicios WHERE activo = 1 ORDER BY orden')->fetchAll();
    $profesionales = $pdo->query('SELECT id, nombre, rol, bio, color, foto, instagram FROM profesionales WHERE activo = 1 ORDER BY orden')->fetchAll();
    $relaciones = $pdo->query('SELECT profesional_id, categoria_id FROM profesional_categoria')->fetchAll();
    $horarios = $pdo->query("SELECT dia_semana, TIME_FORMAT(apertura, '%H:%i') AS apertura, TIME_FORMAT(cierre, '%H:%i') AS cierre
                             FROM horarios ORDER BY dia_semana")->fetchAll();

    foreach ($profesionales as &$p) {
        $p['categorias'] = array_values(array_map(
            fn($r) => $r['categoria_id'],
            array_filter($relaciones, fn($r) => $r['profesional_id'] === $p['id'])
        ));
    }
    foreach ($servicios as &$s) {
        $s['precio'] = (int) $s['precio'];
        $s['duracion'] = (int) $s['duracion'];
    }
    foreach ($horarios as &$h) $h['dia_semana'] = (int) $h['dia_semana'];

    return [
        'categorias'       => $categorias,
        'servicios'        => $servicios,
        'profesionales'    => $profesionales,
        'horarios'         => $horarios,
        'paso_min'         => (int) $CONFIG['reservas']['paso_min'],
        'dias_reservables' => (int) $CONFIG['reservas']['dias_reservables'],
    ];
}

function obtener_servicio(mixed $id): array
{
    $st = db()->prepare('SELECT id, categoria_id, nombre, precio, duracion_min FROM servicios WHERE id = ? AND activo = 1');
    $st->execute([(string) $id]);
    $servicio = $st->fetch();
    if (!$servicio) throw new ErrorApp('El servicio elegido no existe o ya no está disponible.');
    return $servicio;
}

/** Profesionales activos que pueden hacer el servicio. $bloquear = true reserva las filas durante la transacción. */
function profesionales_para(array $servicio, mixed $profesionalId, bool $bloquear = false): array
{
    $sql = 'SELECT p.id, p.nombre FROM profesionales p
            JOIN profesional_categoria pc ON pc.profesional_id = p.id
            WHERE p.activo = 1 AND pc.categoria_id = ?';
    $params = [$servicio['categoria_id']];
    if ($profesionalId && $profesionalId !== 'any') {
        $sql .= ' AND p.id = ?';
        $params[] = (string) $profesionalId;
    }
    $sql .= ' ORDER BY p.orden' . ($bloquear ? ' FOR UPDATE' : '');

    $st = db()->prepare($sql);
    $st->execute($params);
    $lista = $st->fetchAll();
    if (!$lista) throw new ErrorApp('No hay ningún profesional disponible para este servicio.');
    return $lista;
}

/** Franjas de un día con la lista de profesionales libres en cada una. */
function calcular_franjas(string $fecha, array $servicio, array $profesionales): array
{
    global $CONFIG;
    $pdo = db();
    $dia = (int) (new DateTimeImmutable($fecha))->format('w');

    $st = $pdo->prepare("SELECT TIME_FORMAT(apertura, '%H:%i') AS apertura, TIME_FORMAT(cierre, '%H:%i') AS cierre FROM horarios WHERE dia_semana = ?");
    $st->execute([$dia]);
    $horario = $st->fetch();
    if (!$horario) return [];

    $st = $pdo->prepare("SELECT profesional_id, TIME_FORMAT(hora_inicio, '%H:%i') AS inicio, TIME_FORMAT(hora_fin, '%H:%i') AS fin
                         FROM reservas WHERE fecha = ? AND estado <> 'cancelada'");
    $st->execute([$fecha]);
    $ocupadas = $st->fetchAll();

    $paso = (int) $CONFIG['reservas']['paso_min'];
    $duracion = (int) $servicio['duracion_min'];
    $minimoHoy = -1;
    if ($fecha === date('Y-m-d')) {
        $minimoHoy = (int) date('G') * 60 + (int) date('i') + (int) $CONFIG['reservas']['margen_hoy_min'];
    }

    $franjas = [];
    $ultimo = a_minutos($horario['cierre']) - $duracion; // la cita debe acabar antes del cierre
    for ($t = a_minutos($horario['apertura']); $t <= $ultimo; $t += $paso) {
        $fin = $t + $duracion;
        $libres = [];
        if ($t > $minimoHoy) {
            foreach ($profesionales as $p) {
                $choca = false;
                foreach ($ocupadas as $r) {
                    if ($r['profesional_id'] === $p['id'] && $t < a_minutos($r['fin']) && $fin > a_minutos($r['inicio'])) {
                        $choca = true;
                        break;
                    }
                }
                if (!$choca) $libres[] = $p;
            }
        }
        $franjas[] = ['hora' => a_hhmm($t), 'libres' => $libres];
    }
    return $franjas;
}

function obtener_disponibilidad(array $q): array
{
    $fecha = validar_fecha($q['fecha'] ?? '');
    $servicio = obtener_servicio($q['servicio'] ?? '');
    $profesionales = profesionales_para($servicio, $q['profesional'] ?? 'any');
    return array_map(
        fn($f) => ['hora' => $f['hora'], 'libre' => count($f['libres']) > 0],
        calcular_franjas($fecha, $servicio, $profesionales)
    );
}

/** Crea la reserva. La disponibilidad se vuelve a comprobar con las filas bloqueadas,
 *  así dos personas nunca pueden quedarse con la misma hora. */
function crear_reserva(array $d): array
{
    $nombre   = texto($d['nombre'] ?? '', 80);
    global $CONFIG;
    $telefono = preg_replace('/[^\d+]/', '', (string) ($d['telefono'] ?? ''));
    // Número local de 9 cifras → se le añade el prefijo del país (+240), útil para WhatsApp
    if (preg_match('/^\d{9}$/', $telefono)) $telefono = ($CONFIG['prefijo_telefono'] ?? '') . $telefono;
    $email    = texto($d['email'] ?? '', 120);
    $notas    = texto($d['notas'] ?? '', 500);
    $hora     = (string) ($d['hora'] ?? '');

    if (mb_strlen($nombre) < 2) throw new ErrorApp('Indica tu nombre.');
    if (!preg_match('/^\+?\d{9,15}$/', $telefono)) throw new ErrorApp('El teléfono no es válido.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErrorApp('El email no es válido.');
    if (empty($d['privacidad'])) throw new ErrorApp('Debes aceptar la política de privacidad.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw new ErrorApp('La hora no es válida.');
    $fecha = validar_fecha($d['fecha'] ?? '');

    return transaccion(function (PDO $pdo) use ($d, $nombre, $telefono, $email, $notas, $hora, $fecha) {
        $servicio = obtener_servicio($d['servicio'] ?? '');
        $candidatos = profesionales_para($servicio, $d['profesional'] ?? 'any', bloquear: true);

        $franja = null;
        foreach (calcular_franjas($fecha, $servicio, $candidatos) as $f) {
            if ($f['hora'] === $hora) { $franja = $f; break; }
        }
        if (!$franja || !$franja['libres']) {
            throw new ErrorApp('Lo sentimos, esa hora acaba de ocuparse. Elige otra, por favor.', 409);
        }
        $profesional = $franja['libres'][0]; // "sin preferencia" → primer profesional libre

        // Cliente: se reutiliza si ya existe ese teléfono
        $st = $pdo->prepare('SELECT id FROM clientes WHERE telefono = ?');
        $st->execute([$telefono]);
        $clienteId = $st->fetchColumn();
        if ($clienteId) {
            $pdo->prepare('UPDATE clientes SET nombre = ?, email = COALESCE(NULLIF(?, \'\'), email) WHERE id = ?')
                ->execute([$nombre, $email, $clienteId]);
        } else {
            $pdo->prepare('INSERT INTO clientes (nombre, telefono, email) VALUES (?, ?, NULLIF(?, \'\'))')
                ->execute([$nombre, $telefono, $email]);
            $clienteId = (int) $pdo->lastInsertId();
        }

        $horaFin = a_hhmm(a_minutos($hora) + (int) $servicio['duracion_min']);
        $insert = $pdo->prepare('INSERT INTO reservas (codigo, cliente_id, servicio_id, profesional_id, fecha, hora_inicio, hora_fin, precio, notas)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'))');
        for ($intento = 0; ; $intento++) {
            $codigo = generar_codigo();
            try {
                $insert->execute([$codigo, $clienteId, $servicio['id'], $profesional['id'], $fecha, $hora, $horaFin, $servicio['precio'], $notas]);
                break;
            } catch (PDOException $e) {
                // 23000 = código duplicado (muy improbable): se genera otro
                if ($e->getCode() !== '23000' || $intento >= 3) throw $e;
            }
        }

        return [
            'codigo'      => $codigo,
            'servicio'    => $servicio['nombre'],
            'profesional' => $profesional['nombre'],
            'fecha'       => $fecha,
            'hora_inicio' => $hora,
            'hora_fin'    => $horaFin,
            'duracion'    => (int) $servicio['duracion_min'],
            'precio'      => (int) $servicio['precio'],
        ];
    });
}

/* =========================================================
   PANEL DE ADMINISTRACIÓN
   ========================================================= */

function listar_reservas(array $q): array
{
    $filtros = [];
    $params = [];
    if (!empty($q['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['desde'])) { $filtros[] = 'r.fecha >= ?'; $params[] = $q['desde']; }
    if (!empty($q['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['hasta'])) { $filtros[] = 'r.fecha <= ?'; $params[] = $q['hasta']; }
    if (!empty($q['estado']) && in_array($q['estado'], ESTADOS_RESERVA, true)) { $filtros[] = 'r.estado = ?'; $params[] = $q['estado']; }

    $st = db()->prepare("
        SELECT r.id, r.codigo, DATE_FORMAT(r.fecha, '%Y-%m-%d') AS fecha,
               TIME_FORMAT(r.hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(r.hora_fin, '%H:%i') AS hora_fin,
               r.precio, r.notas, r.estado, r.creado_en,
               c.nombre AS cliente, c.telefono, c.email,
               s.nombre AS servicio, p.nombre AS profesional
        FROM reservas r
        JOIN clientes c      ON c.id = r.cliente_id
        JOIN servicios s     ON s.id = r.servicio_id
        JOIN profesionales p ON p.id = r.profesional_id
        " . ($filtros ? 'WHERE ' . implode(' AND ', $filtros) : '') . "
        ORDER BY r.fecha, r.hora_inicio
        LIMIT 1000");
    $st->execute($params);
    $filas = $st->fetchAll();
    foreach ($filas as &$f) { $f['id'] = (int) $f['id']; $f['precio'] = (int) $f['precio']; }
    return $filas;
}

function cambiar_estado_reserva(mixed $id, mixed $estado): array
{
    if (!in_array($estado, ESTADOS_RESERVA, true)) throw new ErrorApp('Estado no válido.');
    $id = filter_var($id, FILTER_VALIDATE_INT);
    if (!$id) throw new ErrorApp('Reserva no válida.');

    $st = db()->prepare('UPDATE reservas SET estado = ? WHERE id = ?');
    $st->execute([$estado, $id]);
    if (!$st->rowCount()) {
        $existe = db()->prepare('SELECT 1 FROM reservas WHERE id = ?');
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) throw new ErrorApp('Reserva no encontrada.', 404);
    }
    return ['id' => $id, 'estado' => $estado];
}

function listar_servicios_admin(): array
{
    $filas = db()->query('SELECT s.id, s.nombre, s.descripcion, s.precio, s.duracion_min, s.activo, c.nombre AS categoria
                          FROM servicios s JOIN categorias c ON c.id = s.categoria_id
                          ORDER BY c.orden, s.orden')->fetchAll();
    foreach ($filas as &$f) {
        $f['precio'] = (int) $f['precio'];
        $f['duracion_min'] = (int) $f['duracion_min'];
        $f['activo'] = (bool) $f['activo'];
    }
    return $filas;
}

function actualizar_servicio(array $d): array
{
    $id = (string) ($d['id'] ?? '');
    $st = db()->prepare('SELECT * FROM servicios WHERE id = ?');
    $st->execute([$id]);
    $actual = $st->fetch();
    if (!$actual) throw new ErrorApp('Servicio no encontrado.', 404);

    $precio   = array_key_exists('precio', $d) ? filter_var($d['precio'], FILTER_VALIDATE_INT) : (int) $actual['precio'];
    $duracion = array_key_exists('duracion_min', $d) ? filter_var($d['duracion_min'], FILTER_VALIDATE_INT) : (int) $actual['duracion_min'];
    $activo   = array_key_exists('activo', $d) ? (bool) $d['activo'] : (bool) $actual['activo'];

    if ($precio === false || $precio < 0 || $precio > 1000000) throw new ErrorApp('Precio no válido: escribe un número entero entre 0 y 1.000.000 FCFA.');
    if ($duracion === false || $duracion < 15 || $duracion > 480 || $duracion % 15) throw new ErrorApp('La duración debe ser múltiplo de 15 min.');

    db()->prepare('UPDATE servicios SET precio = ?, duracion_min = ?, activo = ? WHERE id = ?')
        ->execute([$precio, $duracion, $activo ? 1 : 0, $id]);
    return ['id' => $id, 'precio' => $precio, 'duracion_min' => $duracion, 'activo' => $activo];
}

function resumen_panel(): array
{
    $pdo = db();
    $uno = fn(string $sql) => $pdo->query($sql)->fetchColumn();
    return [
        'hoy'          => (int) $uno("SELECT COUNT(*) FROM reservas WHERE fecha = CURDATE() AND estado = 'confirmada'"),
        'proximas'     => (int) $uno("SELECT COUNT(*) FROM reservas WHERE fecha >= CURDATE() AND estado = 'confirmada'"),
        'clientes'     => (int) $uno('SELECT COUNT(*) FROM clientes'),
        'ingresos_mes' => (int) $uno("SELECT COALESCE(SUM(precio), 0) FROM reservas
                                        WHERE estado = 'completada' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND fecha <= LAST_DAY(CURDATE())"),
    ];
}
