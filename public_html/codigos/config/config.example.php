<?php
/**
 * Plantilla de configuración de VerifyCodes.
 *
 * 1. Copia este archivo como  config/config.php
 * 2. Rellena tus credenciales reales de MySQL y tu clave de cifrado.
 *
 * IMPORTANTE: config/config.php está en .gitignore y NUNCA debe subirse
 * al repositorio, porque contiene secretos.
 *
 * NOTA: la sesión y las cookies seguras se inician en config/init.php
 * (con detección automática de HTTPS y SameSite=Strict), así que aquí
 * NO se llama a session_start().
 */

// --- Base de datos ---
// Valores por defecto para XAMPP/WAMP/Laragon en localhost.
// Al subir a Hostinger, cambia estos 4 valores por los de tu hosting.
define('DB_HOST', 'localhost');
define('DB_NAME', 'verifycodes');
define('DB_USER', 'root');
define('DB_PASS', ''); // en local, root normalmente no tiene contraseña

// --- Cifrado de contraseñas IMAP ---
// Cadena secreta y larga. Se deriva internamente a 32 bytes (SHA-256).
// Cámbiala por una propia. Si la cambias después, las contraseñas IMAP
// ya guardadas dejarán de poder descifrarse y habrá que volver a cargarlas.
define('ENCRYPTION_KEY', 'CAMBIA_ESTA_CLAVE_POR_UNA_LARGA_Y_SECRETA');

// --- Parámetros de negocio ---
define('IMAP_SEARCH_WINDOW_MINUTES', 15);      // ventana por defecto: correos de los últimos N minutos
define('IMAP_SEARCH_WINDOW_MINUTES_MAX', 120); // tope de ventana que un usuario puede pedir desde la UI
define('CACHE_TTL_SECONDS', 20);               // no repetir consulta IMAP antes de esto
define('CODE_VALID_SECONDS', 300);             // tiempo de validez típico de un código (5 min)

// --- Zona horaria ---
date_default_timezone_set('America/Bogota');

// --- Errores ---
// En PRODUCCIÓN (Hostinger) pon display_errors en '0' para no mostrar
// errores a los usuarios. En local déjalo en '1' mientras pruebas.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
