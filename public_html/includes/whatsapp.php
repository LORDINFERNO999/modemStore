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

    $token   = trim($cfg['telegram_token'] ?? '');
    $chatRaw = trim($cfg['telegram_chat_id'] ?? '');

    if (!$token || !$chatRaw) {
        error_log("enviarWhatsApp(Telegram): falta configuración (token=" . ($token ? 'presente' : 'vacío') . ", chat_id=" . ($chatRaw ? 'presente' : 'vacío') . ")");
        return false; // No configurado → silencio
    }

    // Soporta varios destinatarios separados por coma: "123456,987654"
    $destinatarios = array_filter(array_map('trim', explode(',', $chatRaw)));

    // Convertir el formato *negrita* (WhatsApp) al HTML de Telegram
    $texto = telegramFormatear($mensaje);

    $algunoOk = false;
    foreach ($destinatarios as $chatId) {
        if (telegramEnviarA($token, $chatId, $texto)) {
            $algunoOk = true;
        }
    }
    return $algunoOk; // true si al menos uno se entregó
}

/**
 * Envía un mensaje ya formateado a un chat_id concreto de Telegram.
 */
function telegramEnviarA(string $token, string $chatId, string $texto): bool {
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
            error_log("enviarWhatsApp(Telegram) cURL error [$chatId]: $err");
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
            error_log("enviarWhatsApp(Telegram) file_get_contents falló [$chatId]");
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

// ═════════════════════════════════════════════════════════════════
// TELEGRAM: aprobación con botones (Aprobar/Rechazar) + foto del comprobante
// ═════════════════════════════════════════════════════════════════

/**
 * Lee la config de Telegram (token + lista de chat_id).
 */
function telegramConfig(): array {
    global $pdo;
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('telegram_token','telegram_chat_id')");
    $cfg = [];
    foreach ($stmt->fetchAll() as $row) $cfg[$row['clave']] = $row['valor'];
    return [
        'token' => trim($cfg['telegram_token'] ?? ''),
        'chats' => array_values(array_filter(array_map('trim', explode(',', $cfg['telegram_chat_id'] ?? '')))),
    ];
}

/**
 * Llama a cualquier método de la API de Telegram y devuelve la respuesta decodificada.
 */
function telegramApi(string $token, string $method, array $params): ?array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp === false) return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($params),
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }
    return null;
}

/**
 * Envía a los administradores una recarga con la FOTO del comprobante y
 * botones ✅ Aprobar / ❌ Rechazar (para aprobar desde el propio chat).
 */
function enviarRecargaTelegram(int $recargaId, string $texto, string $comprobanteUrl = '', bool $esImagen = true, bool $conBotones = true): bool {
    $cfg = telegramConfig();
    if (!$cfg['token'] || !$cfg['chats']) {
        error_log("enviarRecargaTelegram: falta config de Telegram");
        return false;
    }
    $htmlText = telegramFormatear($texto);

    // Con botones (recarga pendiente) o sin ellos (ya aprobada/informativa)
    $markup = $conBotones
        ? json_encode(['inline_keyboard' => [[
            ['text' => '✅ Aprobar',  'callback_data' => "rec_ap_$recargaId"],
            ['text' => '❌ Rechazar', 'callback_data' => "rec_re_$recargaId"],
          ]]])
        : null;

    $algunoOk = false;
    foreach ($cfg['chats'] as $chat) {
        $enviado = false;
        // Si el comprobante es imagen, mandarlo como foto con la colilla visible
        if ($comprobanteUrl !== '' && $esImagen) {
            $params = [
                'chat_id'    => $chat,
                'photo'      => $comprobanteUrl,
                'caption'    => $htmlText,
                'parse_mode' => 'HTML',
            ];
            if ($markup !== null) $params['reply_markup'] = $markup;
            $r = telegramApi($cfg['token'], 'sendPhoto', $params);
            $enviado = is_array($r) && !empty($r['ok']);
        }
        // Si no es imagen (PDF) o falló la foto, mandar texto con link al comprobante
        if (!$enviado) {
            $t = $htmlText;
            if ($comprobanteUrl !== '') $t .= "\n\n📎 Ver comprobante: " . $comprobanteUrl;
            $params = [
                'chat_id'                  => $chat,
                'text'                     => $t,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => false,
            ];
            if ($markup !== null) $params['reply_markup'] = $markup;
            $r = telegramApi($cfg['token'], 'sendMessage', $params);
            $enviado = is_array($r) && !empty($r['ok']);
        }
        if ($enviado) $algunoOk = true;
    }
    return $algunoOk;
}
