<?php
// includes/whatsapp.php
// ─────────────────────────────────────────────────────────────────
// Helper de notificaciones vía TELEGRAM Bot API (gratuito e ilimitado).
//
// Aunque el archivo y la función se llaman "WhatsApp" por compatibilidad
// con el resto del código, internamente envían por Telegram.
//
// CONFIGURACIÓN (una sola vez, en la tabla `configuracion`):
//   1. Crea un bot con @BotFather en Telegram → obtienes un TOKEN.
//   2. La persona que recibirá los avisos abre el bot y le da "Iniciar".
//   3. Guarda en la tabla `configuracion`:
//        telegram_token   = 123456789:AA...   (token del bot)
//        telegram_chat_id = 987654321          (chat_id del destinatario)
//
//   Para obtener el chat_id, tras darle "Iniciar" al bot abre:
//     https://api.telegram.org/bot<TOKEN>/getUpdates?offset=-1
//   y busca "chat":{"id": ... }.
//
// FORMATO: los mensajes usan *negrita* estilo WhatsApp; aquí se convierten
// automáticamente al formato HTML de Telegram para que se vean bien.
// ─────────────────────────────────────────────────────────────────

/**
 * Envía una notificación por Telegram.
 * Mantiene el nombre enviarWhatsApp() por compatibilidad con el resto del código.
 * Uso: enviarWhatsApp("🛒 *Nueva compra* de Juan");
 */
function enviarWhatsApp(string $mensaje): bool {
    global $pdo;

    // Leer config
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('telegram_token','telegram_chat_id')");
    $cfg  = [];
    foreach ($stmt->fetchAll() as $row) $cfg[$row['clave']] = $row['valor'];

    $token  = trim($cfg['telegram_token'] ?? '');
    $chatId = trim($cfg['telegram_chat_id'] ?? '');

    if (!$token || !$chatId) {
        error_log("enviarWhatsApp(Telegram): falta configuración (token=" . ($token ? 'presente' : 'vacío') . ", chat_id=" . ($chatId ? 'presente' : 'vacío') . ")");
        return false; // No configurado → silencio
    }

    // Convertir el formato *negrita* (WhatsApp) al HTML de Telegram
    $texto = telegramFormatear($mensaje);

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $params = [
        'chat_id'                  => $chatId,
        'text'                     => $texto,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    // ── Método 1: cURL (funciona aunque allow_url_fopen esté desactivado) ──
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp     = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            error_log("enviarWhatsApp(Telegram) cURL error: $err");
            return false;
        }

        error_log("enviarWhatsApp(Telegram) [cURL] HTTP $httpCode | chat=$chatId | respuesta: " . substr($resp, 0, 400));

        // Telegram responde {"ok":true,...} en éxito
        return ($httpCode >= 200 && $httpCode < 300 && stripos($resp, '"ok":true') !== false);
    }

    // ── Método 2 (respaldo): file_get_contents ──
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($params),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            error_log("enviarWhatsApp(Telegram) file_get_contents falló");
            return false;
        }
        error_log("enviarWhatsApp(Telegram) [fgc] chat=$chatId | respuesta: " . substr($resp, 0, 400));
        return stripos($resp, '"ok":true') !== false;
    }

    error_log("enviarWhatsApp(Telegram): ni cURL ni allow_url_fopen disponibles");
    return false;
}

/**
 * Convierte el texto con formato *negrita* (estilo WhatsApp) al HTML que
 * entiende Telegram, escapando antes los caracteres especiales de HTML.
 */
function telegramFormatear(string $mensaje): string {
    // 1) Escapar caracteres especiales de HTML (< > &) para no romper el parseo
    $texto = htmlspecialchars($mensaje, ENT_NOQUOTES, 'UTF-8');
    // 2) Convertir *negrita* → <b>negrita</b>
    $texto = preg_replace('/\*(.+?)\*/s', '<b>$1</b>', $texto);
    // 3) Convertir _cursiva_ → <i>cursiva</i> (opcional, por si se usa)
    $texto = preg_replace('/(?<!\w)_(.+?)_(?!\w)/s', '<i>$1</i>', $texto);
    return $texto;
}
