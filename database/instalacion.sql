-- =========================================================
--  Logan Barber Studio · Base de datos (MySQL / MariaDB)
-- ---------------------------------------------------------
--  Cómo instalar (Hostinger):
--    1. hPanel → Bases de datos → crear una base de datos MySQL
--    2. Abrir phpMyAdmin → seleccionar esa base de datos
--    3. Pestaña "Importar" → elegir este archivo → Continuar
--
--  ⚠ Importarlo de nuevo BORRA todos los datos (reservas incluidas).
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS limites;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS reservas;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS horarios;
DROP TABLE IF EXISTS profesional_categoria;
DROP TABLE IF EXISTS profesionales;
DROP TABLE IF EXISTS servicios;
DROP TABLE IF EXISTS categorias;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
--  CATÁLOGO
-- ---------------------------------------------------------

CREATE TABLE categorias (
  id      VARCHAR(40)  NOT NULL,
  nombre  VARCHAR(80)  NOT NULL,
  icono   VARCHAR(40)  NOT NULL DEFAULT 'bi-scissors',
  orden   SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicios (
  id            VARCHAR(40)    NOT NULL,
  categoria_id  VARCHAR(40)    NOT NULL,
  nombre        VARCHAR(100)   NOT NULL,
  descripcion   VARCHAR(255)   NOT NULL DEFAULT '',
  precio        INT UNSIGNED   NOT NULL,                -- francos CFA (sin decimales)
  duracion_min  SMALLINT       NOT NULL,
  activo        TINYINT(1)     NOT NULL DEFAULT 1,
  orden         SMALLINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_servicios_categoria (categoria_id),
  CONSTRAINT fk_servicios_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
  CONSTRAINT chk_servicios_precio   CHECK (precio >= 0),
  CONSTRAINT chk_servicios_duracion CHECK (duracion_min > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profesionales (
  id      VARCHAR(40)   NOT NULL,
  nombre  VARCHAR(80)   NOT NULL,
  rol     VARCHAR(100)  NOT NULL DEFAULT '',
  bio     VARCHAR(255)  NOT NULL DEFAULT '',
  color   VARCHAR(7)    NOT NULL DEFAULT '#57534E',
  foto    VARCHAR(255)  NULL,
  instagram VARCHAR(60) NULL,                      -- usuario de Instagram, sin @
  activo  TINYINT(1)    NOT NULL DEFAULT 1,
  orden   SMALLINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profesional_categoria (
  profesional_id  VARCHAR(40) NOT NULL,
  categoria_id    VARCHAR(40) NOT NULL,
  PRIMARY KEY (profesional_id, categoria_id),
  CONSTRAINT fk_pc_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales (id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_categoria   FOREIGN KEY (categoria_id)   REFERENCES categorias (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un registro por día abierto. Si un día no tiene fila, el salón está cerrado.
CREATE TABLE horarios (
  dia_semana  TINYINT  NOT NULL,   -- 0 = domingo … 6 = sábado
  apertura    TIME     NOT NULL,
  cierre      TIME     NOT NULL,
  PRIMARY KEY (dia_semana),
  CONSTRAINT chk_horarios_dia CHECK (dia_semana BETWEEN 0 AND 6),
  CONSTRAINT chk_horarios_rango CHECK (cierre > apertura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
--  CLIENTES Y RESERVAS
-- ---------------------------------------------------------

CREATE TABLE clientes (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre     VARCHAR(80)   NOT NULL,
  telefono   VARCHAR(20)   NOT NULL,
  email      VARCHAR(120)  NULL,
  creado_en  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clientes_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservas (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  codigo          CHAR(9)       NOT NULL,                 -- ej. LB-7KQ2MX
  cliente_id      INT UNSIGNED  NOT NULL,
  servicio_id     VARCHAR(40)   NOT NULL,
  profesional_id  VARCHAR(40)   NOT NULL,
  fecha           DATE          NOT NULL,
  hora_inicio     TIME          NOT NULL,
  hora_fin        TIME          NOT NULL,
  precio          INT UNSIGNED  NOT NULL,                 -- FCFA, precio en el momento de reservar
  notas           VARCHAR(500)  NULL,
  estado          ENUM('confirmada','completada','cancelada','no_asistio') NOT NULL DEFAULT 'confirmada',
  creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservas_codigo (codigo),
  KEY idx_reservas_agenda (fecha, profesional_id, estado),
  CONSTRAINT fk_reservas_cliente     FOREIGN KEY (cliente_id)     REFERENCES clientes (id),
  CONSTRAINT fk_reservas_servicio    FOREIGN KEY (servicio_id)    REFERENCES servicios (id),
  CONSTRAINT fk_reservas_profesional FOREIGN KEY (profesional_id) REFERENCES profesionales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
--  SEGURIDAD
-- ---------------------------------------------------------

-- Cuentas del panel. Las contraseñas se guardan cifradas (password_hash), nunca en texto plano.
-- La primera cuenta se crea desde admin.html con la "clave de instalación" de includes/config.php
CREATE TABLE usuarios (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(80)   NOT NULL,
  email          VARCHAR(120)  NOT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  activo         TINYINT(1)    NOT NULL DEFAULT 1,
  ultimo_acceso  DATETIME      NULL,
  creado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de intentos para frenar ataques de fuerza bruta y spam de reservas.
-- La IP se guarda cifrada (hash), no en claro, por privacidad.
CREATE TABLE limites (
  id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  accion     VARCHAR(30)      NOT NULL,
  clave      CHAR(64)         NOT NULL,
  creado_en  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_limites (accion, clave, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
--  DATOS INICIALES · Precios en francos CFA (XAF / FCFA)
--  Son orientativos: ajústalos desde el panel (Servicios y precios).
-- ---------------------------------------------------------

INSERT INTO categorias (id, nombre, icono, orden) VALUES
  ('barberia', 'Barbería',                  'bi-person-badge', 0),
  ('trenzas',  'Trenzas y peinados afro',   'bi-stars',        1),
  ('corte',    'Corte y peinado',           'bi-scissors',     2),
  ('color',    'Color y tratamientos',      'bi-droplet-half', 3),
  ('estetica', 'Estética y belleza',        'bi-flower2',      4);

INSERT INTO servicios (id, categoria_id, nombre, descripcion, precio, duracion_min, orden) VALUES
  -- Barbería
  ('corte-caballero', 'barberia', 'Corte caballero',          'Tijera o máquina, lavado y acabado con producto.',                5000,  30,   0),
  ('degradado',       'barberia', 'Degradado (fade)',         'Degradado a medida con transición limpia y perfilado a navaja.',  6000,  45,   1),
  ('diseno',          'barberia', 'Corte con diseño',         'Rayas y dibujos a navaja sobre el degradado.',                    7000,  45,   2),
  ('barba',           'barberia', 'Arreglo de barba',         'Toalla caliente, perfilado a navaja y aceite hidratante.',        3000,  30,   3),
  ('corte-barba',     'barberia', 'Corte + barba',            'El combo completo para salir impecable.',                         8000,  60,   4),
  ('corte-infantil',  'barberia', 'Corte infantil',           'Para peques hasta 10 años, con paciencia y sin dramas.',          3000,  30,   5),
  ('sesion-vip',      'barberia', 'Sesión VIP',               'Corte, barba y facial en la zona VIP, con bebida incluida.',     20000,  90,   6),
  -- Trenzas y peinados afro
  ('trenzas-pegadas', 'trenzas',  'Trenzas pegadas',          'Cornrows rectas o con diseño, limpias y duraderas.',             10000, 120,   7),
  ('box-braids',      'trenzas',  'Box braids',               'Trenzas sueltas de largo medio. Extensiones no incluidas.',      25000, 240,   8),
  ('twists',          'trenzas',  'Twists',                   'Torcidos de dos hebras con tu pelo natural o con extensiones.',  20000, 180,   9),
  ('rastas',          'trenzas',  'Mantenimiento de rastas',  'Retwist de raíz, limpieza e hidratación del cuero cabelludo.',   15000, 120,  10),
  -- Corte y peinado
  ('corte-mujer',     'corte',    'Corte + lavado + peinado', 'Diagnóstico, lavado, corte a tu medida y acabado.',               8000,  60, 11),
  ('lavado-peinado',  'corte',    'Lavado y peinado',         'Brushing, planchado o definición de rizos.',                      5000,  45, 12),
  ('alisado',         'corte',    'Alisado (relaxer)',        'Alisado químico con protección del cuero cabelludo.',            15000,  90, 13),
  ('peinado-evento',  'corte',    'Peinado de evento',        'Bodas, graduaciones y celebraciones. Prueba incluida.',           15000,  60, 14),
  -- Color y tratamientos
  ('tinte',           'color',    'Tinte completo',           'Color uniforme o cobertura de canas.',                           15000,  90, 15),
  ('mechas',          'color',    'Mechas',                   'Mechas o reflejos para dar luz y dimensión.',                    25000, 150, 16),
  ('hidratacion',     'color',    'Hidratación profunda',     'Mascarilla intensiva con vapor para cabello seco o castigado.',   8000,  45, 17),
  ('keratina',        'color',    'Tratamiento de keratina',  'Controla el encrespamiento y aporta brillo durante meses.',      35000, 180, 18),
  -- Estética y belleza
  ('limpieza-facial', 'estetica', 'Limpieza facial',          'Limpieza profunda, exfoliación y mascarilla para una piel fresca.', 8000,  45, 19),
  ('facial-completo', 'estetica', 'Tratamiento facial completo', 'Limpieza, vapor, mascarilla y masaje facial relajante.',       12000,  60, 20);

INSERT INTO profesionales (id, nombre, rol, bio, color, instagram, orden) VALUES
  ('logan',       'Logan',          'Fundador · Barbero', 'Fundador de Logan Barber Studio. Un buen corte no es un lujo: es tu mejor presentación.', '#1C1917', 'loganthe_barber', 0),
  ('prince',      'Prince',         'Barbero', 'Desvanecimientos frescos y líneas afiladas.', '#B5654A', 'p.r.i.n.c.e_barber_254', 1),
  ('babs',        'Babs',           'Barbero', 'Desvanecimientos frescos y líneas afiladas.', '#9C7F66', 'babs_debarber',          2),
  ('averguay',    'Averguay',       'Barbero', 'Desvanecimientos frescos y líneas afiladas.', '#57534E', 'averguay_barber',        3),
  ('doctordepelo','Doctor de Pelo', 'Barbero', 'Desvanecimientos frescos y líneas afiladas.', '#6B5A4C', 'doctordepelo',           4),
  ('pedromario',  'Pedro Mario',    'Barbero', 'Desvanecimientos frescos y líneas afiladas.', '#C8A165', 'pedromariocriss',        5);

-- Todos hacen barbería. De momento Logan cubre el resto de categorías:
-- cambia las asignaciones cuando se sepa quién hace trenzas, color y estética.
INSERT INTO profesional_categoria (profesional_id, categoria_id) VALUES
  ('logan', 'barberia'), ('logan', 'trenzas'), ('logan', 'corte'), ('logan', 'color'), ('logan', 'estetica'),
  ('prince', 'barberia'), ('babs', 'barberia'), ('averguay', 'barberia'), ('doctordepelo', 'barberia'), ('pedromario', 'barberia');

-- 0 = domingo … 6 = sábado. Domingo cerrado.
INSERT INTO horarios (dia_semana, apertura, cierre) VALUES
  (1, '09:00', '20:00'),
  (2, '09:00', '20:00'),
  (3, '09:00', '20:00'),
  (4, '09:00', '20:00'),
  (5, '09:00', '20:00'),
  (6, '09:00', '19:00');
