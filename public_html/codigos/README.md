# VerifyCodes

Plataforma para consultar códigos de verificación (Netflix, Disney+, Amazon, Max, Spotify)
sin exponer el correo completo, con control de permisos por usuario.

## Instalación en Hostinger

1. Sube todo el contenido a tu hosting. Idealmente:
   - `public/` y `admin/` van dentro de `public_html/`
   - `config/`, `core/`, `services/`, `sql/` van FUERA de `public_html/` (un nivel arriba)
     y ajusta los `require_once` si cambias las rutas relativas.
   - Si tu plan no permite sacar carpetas de `public_html/`, usa el `.htaccess` incluido
     que bloquea el acceso directo a esas carpetas.

2. Crea la base de datos:
   - Importa `sql/schema.sql` desde phpMyAdmin (Hostinger lo incluye).
   - Esto crea las tablas y un usuario admin de ejemplo:
     usuario: `admin` / contraseña: `admin123` (CÁMBIALA de inmediato desde el panel de usuarios).

3. Configura la aplicación:
   - Copia `config/config.example.php` a `config/config.php`.
   - DB_HOST, DB_NAME, DB_USER, DB_PASS con tus credenciales de MySQL de Hostinger.
   - ENCRYPTION_KEY: cambia por una clave propia (cualquier cadena, se deriva internamente a 32 bytes).
   - CACHE_TTL_SECONDS, CODE_VALID_SECONDS e IMAP_SEARCH_WINDOW_MINUTES ya traen valores por defecto razonables.
   - `config/config.php` está en `.gitignore` y nunca se sube al repositorio (contiene secretos).
     La sesión y las cookies seguras se inicializan automáticamente en `config/init.php`.

4. Habilita la extensión `imap` de PHP:
   - Panel de Hostinger → Avanzado → Configuración de PHP → activa "imap".

5. Cuentas de Gmail:
   - Activa IMAP en la configuración de cada cuenta de Gmail.
   - Usa una "Contraseña de aplicación" de Google (no la contraseña normal),
     ya que Gmail requiere 2FA + App Password para acceso IMAP de terceros.

6. Primer uso:
   - Entra a `/admin/mailboxes.php` y agrega las cuentas de correo con su tipo de servicio.
   - Entra a `/admin/users.php` y crea usuarios operativos.
   - Entra a `/admin/assign.php` y asigna qué cuentas puede ver cada usuario.
   - Los usuarios entran por `/login.php` y ya pueden consultar sus códigos desde `/dashboard.php`.

## Notas de arquitectura

- El endpoint `public/ajax/get_code.php` es el único punto que toca IMAP en tiempo real,
  y solo lo hace si el caché (tabla `code_cache`, TTL configurable) ya venció.
- `services/CodeExtractor.php` centraliza las reglas por servicio (remitente, asunto,
  patrón de código, palabras clave permitidas/bloqueadas). Agregar un nuevo servicio
  de streaming es solo agregar una entrada nueva ahí.
- `services/ImapService.php` usa la extensión nativa `imap` de PHP (sin Composer),
  busca con `SINCE` para no recorrer todo el buzón, y solo descarga el cuerpo de texto
  plano de los mensajes que ya pasaron el filtro de remitente/asunto.
- Las contraseñas IMAP se guardan cifradas (AES-256-CBC) en `core/Encryption.php`,
  nunca en texto plano.
