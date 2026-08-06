<?php
// includes/whatsapp.php
// ─────────────────────────────────────────────────────────────────
// Helper para enviar mensajes WhatsApp via CallMeBot (gratuito).
//
// CONFIGURACIÓN (una sola vez):
//   1. Desde WhatsApp envía: "I allow callmebot to send me messages"
//      al número +34 644 38 55 49 (CallMeBot)
//   2. Recibirás tu API key por WhatsApp en segundos.
//   3. En la tabla `configuracion` guarda:
//        whatsapp_admin  = 573001234567   (tu número SIN el +)
//        whatsapp_apikey = tu_api_key
// ─────────────────────────────────────────────────────────────────

function enviarWhatsApp(string $mensaje): bool {
    global $pdo;

    // Leer config
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('whatsapp_admin','whatsapp_apikey')");
    $cfg  = [];
    foreach ($stmt->fetchAll() as $row) $cfg[$row['clave']] = $row['valor'];

    $numero = trim($cfg['whatsapp_admin'] ?? '');
    $apikey = trim($cfg['whatsapp_apikey'] ?? '');

    if (!$numero || !$apikey) return false; // No configurado → silencio

    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $numero,
        'text'   => $mensaje,
        'apikey' => $apikey,
    ]);

    // ── Método 1: cURL (funciona aunque allow_url_fopen esté desactivado) ──
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log("enviarWhatsApp cURL error: $err");
            return false;
        }
        return true;
    }

    // ── Método 2 (respaldo): file_get_contents ──
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'       => 15,
            'ignore_errors' => true,
            'method'        => 'GET',
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            error_log("enviarWhatsApp file_get_contents falló");
            return false;
        }
        return true;
    }

    error_log("enviarWhatsApp: ni cURL ni allow_url_fopen disponibles");
    return false;
}