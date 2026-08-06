# Informe de seguridad — ModemStores

Fecha: 2026-06-09

## ✅ Lo que se implementó (Fase 5)

### Protección CSRF (token anti falsificación de peticiones)
Todos los formularios y endpoints que cambian datos exigen un token de seguridad ligado a
la sesión. Sin token válido → **HTTP 403** / mensaje de error.
- Páginas: `login.php`, `registro.php`, `recarga.php`.
- Endpoints AJAX: `ajax/comprar.php`, `ajax/recargar.php`, `ajax/cambiar-password.php`,
  `ajax/agregar-stock.php`.
- Admin: `admin/index.php` (recargas), `admin/servicios.php`, `admin/usuarios.php`,
  `admin/pedidos.php`, `admin/stock.php`.
- Helpers en `includes/seguridad.php`: `csrfToken()`, `csrfField()`, `csrfCheck()`,
  `csrfRequire()`.

### Sesión endurecida
- Cookie de sesión `HttpOnly` (no accesible por JavaScript) + `SameSite=Lax` + `Secure`
  automático cuando haya HTTPS.
- `session_regenerate_id()` al iniciar sesión (anti *session fixation*).
- **Cierre automático por inactividad** a las 2 horas.

### Anti fuerza-bruta en login
- Tras **5 intentos fallidos** se bloquea el login **5 minutos** (por sesión).

### Cabeceras de seguridad (en `includes/config.php`)
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN` (permite el panel admin embebido, bloquea clickjacking externo)
- `Referrer-Policy: strict-origin-when-cross-origin`

### Archivos sensibles protegidos (`.htaccess`)
- No se pueden descargar por web los `.sql` (incluye `modemstores.sql` con los hashes),
  ni el contenido de `db/` ni de `includes/`.

### Ya existente y correcto
- Contraseñas con **bcrypt** (`password_hash` / `password_verify`).
- Todas las consultas usan **sentencias preparadas** (PDO) → sin inyección SQL.
- Salida escapada con `htmlspecialchars` en las vistas.

---

## ⚠️ Recomendaciones pendientes (para hacerla aún más segura)

1. **Contraseña a MySQL `root`** — hoy está vacía. Asignar una y actualizarla en
   `includes/config.php` (`DB_PASS`).
2. **Cambiar la contraseña del admin** — actualmente es `password` (muy débil). Hacerlo
   desde Admin → Usuarios → 🔑 Clave.
3. **HTTPS en producción** — para que `Secure`/`SameSite` protejan de verdad y no viajen
   credenciales en texto plano.
4. **Eliminar endpoints duplicados sin uso**: `ajax/login.php` y `ajax/registro.php`
   (las páginas `login.php`/`registro.php` ya hacen ese trabajo con CSRF).
5. **`display_errors = Off` en producción** — para no filtrar rutas ni detalles internos.
6. **Backups automáticos** de la base de datos.
7. **(Opcional, a futuro) Content-Security-Policy (CSP)** — requiere mover los scripts/estilos
   inline a archivos externos; mitiga XSS de forma fuerte.
8. **Rate-limit por IP** (además de por sesión) si se expone a internet, p. ej. con un
   contador en base de datos o un WAF.
