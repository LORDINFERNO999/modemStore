<?php
// telegram-webhook.php
// ─────────────────────────────────────────────────────────────────
// Recibe los toques de los botones "✅ Aprobar / ❌ Rechazar" que se
// envían al Telegram del admin con cada recarga, y aplica la acción.
//
// Telegram llama a este archivo automáticamente (webhook). Para activarlo,
// abre una vez: /telegram-setwebhook.php (como admin).
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seguridad.php';  // getConfig
require_once __DIR__ . '/includes/funciones.php';  // registrarMovimiento, procesarRecargaTelegram
require_once __DIR__ . '/includes/whatsapp.php';   // telegramApi, telegramConfig

// ── Seguridad: Telegram envía un token secreto en cada petición ──
$secretRecibido = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$secretEsperado = (string) getConfig('telegram_webhook_secret', '');
if ($secretEsperado === '' || !hash_equals($secretEsperado, $secretRecibido)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
if (!is_array($update)) { http_response_code(200); exit; }

$cfg    = telegramConfig();
$admins = $cfg['chats'];

// Solo nos interesan los toques de botones (callback_query)
if (!isset($update['callback_query'])) { http_response_code(200); exit; }

$cb        = $update['callback_query'];
$cbId      = $cb['id'] ?? '';
$fromId    = (string)($cb['from']['id'] ?? '');
$quien     = $cb['from']['first_name'] ?? 'admin';
$data      = (string)($cb['data'] ?? '');
$message   = $cb['message'] ?? [];
$chatId    = (string)($message['chat']['id'] ?? '');
$messageId = (int)($message['message_id'] ?? 0);

// Solo los administradores autorizados (chat_id en la config) pueden aprobar
if (!in_array($fromId, $admins, true)) {
    telegramApi($cfg['token'], 'answerCallbackQuery', [
        'callback_query_id' => $cbId,
        'text'              => 'No estás autorizado para esta acción.',
        'show_alert'        => true,
    ]);
    http_response_code(200);
    exit;
}

// Acción: rec_ap_<id>  |  rec_re_<id>
if (preg_match('/^rec_(ap|re)_(\d+)$/', $data, $m)) {
    $res = procesarRecargaTelegram((int)$m[2], $m[1]);

    // Aviso inmediato (globo) en el chat
    telegramApi($cfg['token'], 'answerCallbackQuery', [
        'callback_query_id' => $cbId,
        'text'              => $res['msg'],
    ]);

    // Actualiza el mensaje: quita los botones y muestra el resultado final
    $caption = $res['caption'] . "\n<i>por " . htmlspecialchars($quien, ENT_NOQUOTES, 'UTF-8') . " · " . date('d/m/Y H:i') . "</i>";
    $sinBotones = json_encode(['inline_keyboard' => []]);

    if (isset($message['photo'])) {
        telegramApi($cfg['token'], 'editMessageCaption', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => $sinBotones,
        ]);
    } else {
        telegramApi($cfg['token'], 'editMessageText', [
            'chat_id'                  => $chatId,
            'message_id'               => $messageId,
            'text'                     => $caption,
            'parse_mode'               => 'HTML',
            'reply_markup'             => $sinBotones,
            'disable_web_page_preview' => true,
        ]);
    }
} else {
    telegramApi($cfg['token'], 'answerCallbackQuery', ['callback_query_id' => $cbId]);
}

http_response_code(200);
