# Registro de cambios — ModemStores

Este archivo documenta cada cambio realizado en el proyecto, con su fecha y detalle.
Formato de fecha: AAAA-MM-DD.

---

## [2026-06-09] Puesta en marcha del proyecto

### Base de datos
- Se completó el esquema `modemstores.sql`: se agregaron las 6 tablas que faltaban
  (`servicios`, `planes`, `cuentas_stock`, `pedidos`, `recargas`, `movimientos_saldo`),
  con sus llaves foráneas. Antes solo existía `usuarios` y la app se caía.
- Se creó la base `modemstores` en MySQL y se importó el esquema completo.

### Correcciones
- `login.php`: se eliminó un bloque `if` roto y duplicado (restos de un merge).

### Archivos que estaban vacíos y se completaron
- `mis-pedidos.php`: historial de pedidos del cliente.
- `recarga.php`: formulario de solicitud de recarga.
- `ajax/login.php`, `ajax/registro.php`, `ajax/agregar-stock.php`: endpoints JSON.
- `includes/footer.php`: cierre del layout de `header.php`.
- `assets/js/main.js`: toggle del menú móvil y buscador.
- `assets/css/main.css`: estilos base del sidebar/layout.

### Entorno
- El proyecto se movió de `Escritorio\proyecto1` a `C:\xampp\htdocs\proyecto1`
  (ubicación que sirve Apache). Se accede en http://localhost/proyecto1.

### Accesos de prueba
- Admin: `admin@modemstores.com` / `password`
- Cliente: `perrinizzotopereza@gmail.com` / `cliente123`

---

## [2026-06-09] Fase 0 — Fundamentos

Base técnica para las funcionalidades nuevas (billetera, notificaciones, seguridad).

### Base de datos (`db/upgrade-2026-06-09.sql`)
- Nueva tabla `configuracion` (clave/valor) para guardar ajustes editables por el admin
  (credenciales de Mercado Pago, nombre del sitio, WhatsApp de soporte).
- Nueva tabla `notificaciones` (campanita del admin: recargas y compras).
- `pedidos`: se agregaron `duracion_dias`, `cred_usuario`, `cred_password`,
  `fecha_entrega`, `fecha_vencimiento`; el `estado` ahora admite
  `pendiente/entregado/vencido/cancelado`.
- `recargas`: se agregaron `mp_preference_id` y `mp_payment_id` (referencias de Mercado Pago).

### Código
- Nuevo `includes/seguridad.php`: helpers `csrfToken()`, `csrfCheck()`, `getConfig()`,
  `setConfig()`.
- `includes/config.php`: cookie de sesión endurecida (HttpOnly + SameSite=Lax).
- `includes/auth.php`: regeneración del ID de sesión al iniciar sesión (anti session-fixation).

---

## [2026-06-09] Fase 4 — Admin embebido en el dashboard

Antes, los botones de administración abrían cada página en una **pestaña nueva** del
navegador. Ahora se muestran dentro del propio dashboard.

### Cambios
- `dashboard.php`: los 5 enlaces de administración (Dashboard admin, Servicios & Planes,
  Stock de cuentas, Gestionar pedidos, Usuarios) pasaron de `<a target="_blank">` a
  botones que cargan la página en un **iframe embebido** (`#panel-admin`) mediante la
  nueva función JS `showAdmin()`. Se agregó el CSS `.admin-embed` (iframe a pantalla
  completa dentro del área de contenido).
- Nueva página `admin/pedidos.php` (**Gestionar pedidos**): lista de pedidos con filtros
  por estado, **entrega manual de credenciales** (usuario + contraseña) que marca el
  pedido como `entregado` y calcula `fecha_vencimiento` según la duración del plan, y
  opción de cancelar+reembolsar pedidos pendientes. Marca atendidas las notificaciones.
- Nueva página `admin/stock.php` (**Stock de cuentas**): alta/baja/listado de cuentas
  precargadas (opcional; la entrega normal es manual).
- Formularios de estas páginas protegidos con token CSRF (`csrfField()` / `csrfRequire()`).

---

## [2026-06-09] Fase 2 — Cambiar contraseña (cliente y admin)

Tanto el cliente como el administrador pueden cambiar su propia contraseña.

### Cambios
- Nuevo endpoint `ajax/cambiar-password.php`: valida el token CSRF, verifica la contraseña
  actual con `password_verify`, exige mínimo 6 caracteres, impide repetir la actual y
  guarda el nuevo hash.
- Nuevo `includes/modal-password.php`: modal reutilizable (HTML + CSS + JS) con la función
  global `abrirCambioPassword()`. Envía el token CSRF y llama al endpoint vía `fetch`.
- `dashboard.php` (cliente): botón "Cambiar contraseña" en el menú lateral + include del modal.
- `admin/index.php` (admin): botón "🔑 Cambiar contraseña" en la barra superior + include del modal.

### Verificado
- Petición sin token CSRF → HTTP 403.
- Contraseña actual incorrecta → rechazada.
- Cambio correcto → contraseña actualizada (probado y revertido en pruebas).

---

## [2026-06-09] Fase 3 — Admin: ver y administrar usuarios / contraseñas

`admin/usuarios.php` ya listaba y editaba usuarios. Se reforzó y se agregó reseteo directo
de contraseña.

### Cambios en `admin/usuarios.php`
- Nuevo botón "🔑 Clave" por usuario → modal de **resetear contraseña** (acción
  `resetear_password`), con generador de contraseña aleatoria.
- Protección **CSRF** en toda la página: `csrfRequire()` en el handler POST y `csrfField()`
  en los 4 formularios (crear, editar, ajustar saldo, desactivar) y en el de reseteo.

### Verificado
- Reseteo sin token CSRF → HTTP 403.
- Reseteo con token → contraseña actualizada (probado con el cliente y revertido a `cliente123`).

---

## [2026-06-09] Fase 5 — Seguridad

Repaso de seguridad transversal. Detalle completo y recomendaciones en `SECURIDAD.md`.

### Cambios
- **CSRF** en todos los formularios/endpoints que cambian datos: `login.php`, `registro.php`,
  `recarga.php`, `ajax/comprar.php`, `ajax/recargar.php`, `ajax/agregar-stock.php`,
  `admin/index.php`, `admin/servicios.php` (+ los ya protegidos en fases previas).
- **Anti fuerza-bruta** en `login.php`: 5 intentos fallidos → bloqueo de 5 min (helpers
  `loginThrottleCheck/RegisterFail/ResetFails` en `includes/seguridad.php`).
- **Cabeceras de seguridad** en `includes/config.php`: `X-Content-Type-Options`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`.
- **Timeout de sesión** por inactividad (2 h) en `includes/config.php`.
- **`.htaccess`** que bloquea el acceso web directo a `*.sql`, a `db/` y a `includes/`.
- Nuevo `SECURIDAD.md` con el informe y las recomendaciones pendientes (contraseña a MySQL
  root, HTTPS, cambiar la clave del admin, eliminar `ajax/login.php` y `ajax/registro.php`
  sin uso, etc.).

### Verificado (end-to-end)
- Login sin token → bloqueado; con token → entra (HTTP 302).
- 6 intentos fallidos → bloqueo activado.
- `ajax/comprar.php` sin token → HTTP 403; con token → procesa.
- `modemstores.sql`, `db/*.sql`, `includes/*.php` → HTTP 403 por web; las páginas siguen en 200.

---

## [2026-06-09] Compra por transferencia con QR (reemplaza billetera y Mercado Pago)

Cambio de modelo: se eliminó por completo la **billetera/saldo/recargas**. Ahora el cliente
paga por transferencia (QR), adjunta el comprobante y el admin valida y entrega.

### Base de datos (`db/upgrade-2026-06-09b.sql`)
- `pedidos`: nueva columna `comprobante` (ruta del archivo subido).
- `configuracion`: claves de pago `pago_titular`, `pago_llave`, `pago_banco`, `pago_qr`,
  `pago_instrucciones`.

### Flujo nuevo
- **Compra**: el cliente elige servicio → "Comprar" → modal con **QR + datos + instrucciones**
  y **adjuntar comprobante (obligatorio)** → "Enviar transferencia". Se crea el pedido en
  `pendiente` (`comprarPlan()` reescrita, ya no usa saldo) y se genera una **notificación**.
- **Admin**: campanita 🔔 en el dashboard (polling cada 20 s) avisa de nuevas solicitudes;
  en **Gestionar pedidos** ve el **comprobante**, **Entrega** usuario/contraseña (estado
  `entregado` + `fecha_vencimiento` = hoy + duración) o **Rechaza** el pedido.
- **Cliente**: en "Mis pedidos" ve estado, **días de vigencia que bajan**, las credenciales
  cuando está entregado, y **al vencer se bloquea** (solo ve qué compró).
- **Trazabilidad**: "Gestionar pedidos" muestra resumen con **valores** (total vendido, en
  espera, entregados, vencidos/rechazados).

### Datos de pago (admin)
- Nueva página `admin/pago.php`: subir el **QR** y editar titular/llave/banco/instrucciones.

### Eliminado (billetera, ambos lados)
- Cliente: pestaña Billetera, pill de saldo, panel de billetera y formulario de recarga.
- Admin: sección de Recargas en `admin/index.php`; ajuste de saldo y columna/stat de saldo
  en `admin/usuarios.php`.
- Archivos borrados: `recarga.php`, `ajax/recargar.php`. Función `solicitarRecarga()` eliminada.
- `header.php`: quitado "Recargar saldo" y bloque "Mi saldo"; corregido `tipo`→`rol`.
- Nota: quedan reglas CSS sin uso (`.saldo-pill`, `.wallet-hero`, `.mov-icon`) — invisibles,
  limpieza opcional. Las tablas `recargas`/`movimientos_saldo` se conservan sin uso.

### Archivos nuevos
- `admin/pago.php`, `admin/pedidos.php` (entrega), `admin/stock.php`, `ajax/notificaciones.php`,
  carpeta `assets/comprobantes/`.

### Verificado (end-to-end, con datos de prueba luego eliminados)
- Compra → pedido `pendiente` + notificación (contador 0→1).
- Entrega → `entregado` + credenciales + vencimiento a 7 días.
- Forzar fecha pasada → `vencido` (bloqueo).
- Todas las páginas HTTP 200 sin errores; `recarga.php` → 404.

### Ajustes posteriores (mismo día)
- QR recortado a 610×610 (`qr-pago-crop.jpg`) para que se vea grande sin el margen de la
  tarjeta; efecto hover (zoom) sobre el QR en el modal de compra.
- Bug corregido: el `onerror` de la imagen del modal se autodestruía (`mImg` null) → la
  compra no abría. Ahora `onerror` solo oculta y la imagen se asigna al abrir.
- Modal de compra reorganizado: instrucciones a la izquierda, QR a la derecha; tamaño −15%.
- Validación: si falta el comprobante, avisa primero con mensaje claro y resalta el botón.
- Confirmación de compra ahora es un mensaje **centrado** que dura 10 s (con barra de
  progreso) antes de recargar.
- `db/upgrade-2026-06-09c.sql`: nueva columna `pedidos.nota_admin`. Al **rechazar**, el
  admin escribe un **motivo** (modal con textarea); el cliente lo ve en el estado del pedido
  junto al aviso de volver a comprar desde la Tienda.
- Se agregaron 3 servicios reales de prueba (Netflix, Spotify, Disney+) para validar compras.

---

## [2026-06-09] Identidad visual unificada + responsive móvil

- **Color de marca único**: el dashboard del cliente pasó de rojo (#e50914) a la paleta
  morado/rosa del logo (#7c6dfa / #f472b6). Login, registro y admin ya la usaban.
- **Tipografía unificada a Inter** en todo el sitio (login, registro y admin/index dejaron
  Syne/DM Sans).
- **Logo** grande y recortado (`logo-crop.png`) en dashboard, login, registro e inicio
  (barra + footer); se quitó el texto del nombre. **Favicon** con el logo.
- **Responsive móvil** reforzado en el dashboard: modal con scroll y alto máx 92vh, precio
  y campanita adaptados, grilla de servicios 1–2 columnas, QR centrado en pantallas chicas.
- **Pulido**: botón "Comprar" y precios con gradiente de marca; estados vacíos con ícono
  en círculo de color; **toast** elegante al cambiar la contraseña (cliente y admin).
