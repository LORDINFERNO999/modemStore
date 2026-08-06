<?php
/**
 * Bootstrap central de la aplicación.
 *
 * Carga la configuración con los secretos (config.php) e inicia la sesión
 * con parámetros de cookie seguros ANTES de cualquier salida.
 *
 * Todos los puntos de entrada (páginas y servicios) terminan incluyendo
 * este archivo de forma indirecta a través de core/Database.php, por lo que
 * la sesión siempre queda iniciada sin tener que repetir session_start()
 * en cada archivo.
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    // Detecta HTTPS (incluye el caso detrás de proxy/Load Balancer de Hostinger)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    // Endurecer el manejo de sesiones
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // no accesible desde JavaScript
        'secure'   => $https,    // solo por HTTPS cuando esté disponible
        'samesite' => 'Strict',  // máxima protección CSRF (app de uso interno)
    ]);

    session_start();
}
