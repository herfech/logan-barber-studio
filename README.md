# Logan Barber Studio · Web y sistema de reservas

Web de la barbería y peluquería **Logan Barber Studio** (Malabo, Guinea Ecuatorial), con reservas online, base de datos y panel de gestión para el equipo.

- **Moneda:** francos CFA (XAF), mostrados como `12.000 FCFA`
- **Zona horaria:** `Africa/Malabo` (UTC+1)
- **Teléfonos:** a los números locales de 9 cifras se les añade `+240` automáticamente

- **Web:** HTML, CSS (Bootstrap 5) y JavaScript
- **Servidor:** PHP 8.1 o superior
- **Base de datos:** MySQL / MariaDB

Funciona en cualquier plan de hosting de Hostinger, incluido el más básico.

---

## 1. Estructura del proyecto

```
logan-barber-studio/
├── index.html              ← Web pública
├── admin.html              ← Panel del equipo
├── .htaccess               ← Seguridad del servidor (HTTPS, bloqueos, cabeceras)
├── .gitignore
├── README.md
├── css/
│   ├── styles.css          ← Estilos de la marca
│   └── admin.css           ← Estilos del panel
├── js/
│   ├── script.js           ← Lógica de la web (servicios, galería, reservas)
│   └── admin.js            ← Lógica del panel
├── assets/img/             ← Fotos (hero, galería, equipo)
├── api/                    ← Puntos de entrada PHP que llama el JavaScript
│   ├── catalogo.php
│   ├── disponibilidad.php
│   ├── reservas.php
│   └── admin/
│       ├── estado.php  configurar.php  login.php  logout.php  cuenta.php
│       └── resumen.php reservas.php    servicios.php
├── includes/               ← Código interno (bloqueado desde el navegador)
│   ├── config.example.php  ← Plantilla de configuración
│   ├── config.php          ← TU configuración (la creas tú; no se comparte)
│   ├── bootstrap.php       ← Conexión a MySQL, errores, utilidades de seguridad
│   ├── auth.php            ← Sesiones del panel, CSRF, contraseñas
│   └── agenda.php          ← Todas las consultas y la lógica de reservas
└── database/               ← (bloqueada desde el navegador)
    └── instalacion.sql     ← Tablas + datos iniciales
```

## 2. Publicar en Hostinger (paso a paso)

### a) Crear la base de datos
1. **hPanel → Bases de datos → Bases de datos MySQL.**
2. Crea una base de datos y un usuario con una contraseña segura. Apunta los tres datos: nombre de la base de datos, usuario y contraseña.

### b) Importar las tablas
1. En la misma página, pulsa **phpMyAdmin** junto a tu base de datos.
2. Pestaña **Importar** → elige `database/instalacion.sql` → **Continuar**.
3. Verás 9 tablas creadas, con los servicios, el equipo y el horario ya cargados.

### c) Configurar
1. Copia `includes/config.example.php` y llámalo `includes/config.php`.
2. Rellena `nombre`, `usuario` y `clave` con los datos del paso a). La zona horaria (`Africa/Malabo`) y el prefijo (`+240`) ya vienen puestos.
3. Cambia `clave_instalacion` por una frase secreta. La usarás una sola vez.
4. Cambia `secreto` por 40 o más caracteres aleatorios.

### d) Subir los archivos
1. **hPanel → Archivos → Administrador de archivos → `public_html`.**
2. Sube **el contenido** de la carpeta `logan-barber-studio`, no la carpeta en sí, para que `index.html` quede directamente dentro de `public_html`.
   - Truco: sube el `.zip` y usa la opción **Extraer** del administrador de archivos.
3. Comprueba que se han subido los archivos ocultos `.htaccess` (raíz, `includes/` y `database/`).

### e) Activar HTTPS
**hPanel → Seguridad → SSL** → instala el certificado gratuito. El `.htaccess` ya redirige todo a `https://`.

### f) Crear tu cuenta del panel
1. Abre `https://tudominio.com/admin`.
2. La primera vez te pedirá la **clave de instalación**, tu nombre, email y contraseña.
3. Listo. A partir de ahí entras con email y contraseña. La clave de instalación ya no sirve para nada más.

## 3. Probar en tu ordenador (opcional)

Instala **XAMPP** (Windows/Mac) o **Laragon** (Windows). Ambos traen Apache, PHP y MySQL.

1. Copia la carpeta en `htdocs` (XAMPP) o `www` (Laragon).
2. Abre `http://localhost/phpmyadmin`, crea la base de datos `logan` e importa `database/instalacion.sql`.
3. Crea `includes/config.php`. En XAMPP el usuario suele ser `root` y la contraseña va vacía.
4. Abre `http://localhost/logan-barber-studio/`.

> La web **no funciona con doble clic** en `index.html`: necesita PHP para leer la base de datos.

## 4. Cómo se gestiona la información

### Tablas

| Tabla | Qué guarda | Quién la modifica |
|---|---|---|
| `categorias` | Barbería, Trenzas y peinados afro, Corte y peinado, Color y tratamientos, Estética y belleza | phpMyAdmin |
| `servicios` | Nombre, descripción, precio en FCFA (número entero), duración, visible | **Panel** (precio, duración, visible) |
| `profesionales` | Equipo: nombre, especialidad, bio, color, foto | phpMyAdmin |
| `profesional_categoria` | Qué categorías atiende cada profesional | phpMyAdmin |
| `horarios` | Apertura y cierre por día (sin fila = cerrado) | phpMyAdmin |
| `clientes` | Nombre, teléfono (único) y email | **Web** (al reservar) |
| `reservas` | Cliente, servicio, profesional, fecha, hora, precio, estado | **Web** (crea) y **Panel** (estado) |
| `usuarios` | Cuentas del panel con contraseña cifrada | **Panel** |
| `limites` | Intentos recientes (anti fuerza bruta / spam), IP cifrada | Automático (se limpia solo) |

**Estados de una reserva:** `confirmada` → `completada` / `no_asistio` / `cancelada`. Al cancelar una cita, su hueco queda libre automáticamente.

### Flujo de una reserva
1. La web carga el catálogo → `api/catalogo.php`.
2. El cliente elige servicio, profesional y día → `api/disponibilidad.php` calcula los huecos según el horario, la duración y las citas existentes.
3. Al confirmar → `api/reservas.php` bloquea la agenda de esos profesionales, **vuelve a comprobar** el hueco y guarda. Aunque varias personas reserven a la vez, solo una se queda con la hora.
4. Con "Sin preferencia" se asigna el primer profesional libre.
5. El cliente recibe un **código** (ej. `LB-7KQ2MX`) y puede añadir la cita a su calendario.

### Panel (`/admin`)
- Indicadores: citas de hoy, próximas, clientes y facturación del mes.
- **Agenda** con filtros, cambio de estado y **exportación a Excel (CSV)**.
- **Servicios y precios**: cambiar precio, duración y ocultar o mostrar servicios.
- **Cambiar contraseña** desde el menú de usuario.

## 5. Seguridad incluida

| Protección | Cómo |
|---|---|
| Inyección SQL | Consultas preparadas reales con PDO en todas las consultas |
| Contraseñas | Cifradas con Argon2id / bcrypt (`password_hash`); mínimo 10 caracteres con letras y números |
| Robo de sesión | Cookie `HttpOnly` + `SameSite=Strict` + `Secure` en HTTPS; nueva sesión al entrar; cierre por inactividad (2 h) |
| CSRF (acciones falsas desde otra web) | Token secreto obligatorio en cada cambio + comprobación del origen |
| Fuerza bruta | Bloqueo de 15 min tras 5 intentos fallidos por email (10 por IP) |
| Enumeración de usuarios | Mismo mensaje y mismo tiempo de respuesta si el email no existe |
| Spam de reservas | Campo trampa invisible para bots + máximo de reservas por IP y hora |
| Doble reserva | Bloqueo de filas (`SELECT … FOR UPDATE`) dentro de una transacción |
| XSS | Todo dato se escapa antes de mostrarse; Content-Security-Policy estricta |
| Archivos internos | `includes/`, `database/`, `.sql`, `.md` y archivos ocultos bloqueados por `.htaccess` |
| Otras cabeceras | HTTPS forzado, HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Exportación CSV | Neutraliza fórmulas maliciosas al abrir en Excel |
| Errores | Nunca se muestran detalles técnicos al público; se guardan en el log del servidor |
| Privacidad | Las IP solo se guardan cifradas y se borran a las 24 h |

## 6. Tareas habituales

| Quiero… | Cómo |
|---|---|
| Cambiar un precio o duración | Panel → Servicios y precios |
| Cambiar horario, equipo o añadir servicios | phpMyAdmin → tabla correspondiente → Editar / Insertar |
| Poner la foto de un profesional | Sube la foto a `assets/img/` y escribe su ruta (`assets/img/logan.jpg`) en la columna `foto` de `profesionales` |
| Cambiar dirección, teléfono, redes o textos | `index.html` y `SALON` en `js/script.js` |
| Cambiar colores o tipografías | `css/styles.css` → bloque `:root` |
| Copia de seguridad | phpMyAdmin → **Exportar** (o las copias automáticas de Hostinger) |
| Olvidé la contraseña del panel | phpMyAdmin → tabla `usuarios` → borra la fila → vuelve a `/admin` y créala con la clave de instalación |

⚠️ No vuelvas a importar `instalacion.sql` en una web en uso: **borra todas las reservas**.
