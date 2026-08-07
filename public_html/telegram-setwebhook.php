<?php
// telegram-setwebhook.php
// ─────────────────────────────────────────────────────────────────
// Activa (o reactiva) el webhook del bot de Telegram apuntando a
// telegram-webhook.php de este mismo sitio. Ábrelo UNA vez como admin.
//
// Requiere en la tabla `configuracion`:
//   telegram_token           = token del bot
//   telegram_webhook_secret  = una clave secreta cualquiera (la validamos en el webhook)
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/whatsapp.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

$cfg    = telegramConfig();
$secret = (string) getConfig('telegram_webhook_secret', '');

echo "=== ACTIVAR WEBHOOK DE TELEGRAM ===\n\n";

if (!$cfg['token']) { echo "❌ Falta 'telegram_token' en la tabla configuracion.\n"; exit; }
if ($secret === '') { echo "❌ Falta 'telegram_webhook_secret' en la tabla configuracion.\n"; exit; }

$webhookUrl = SITE_URL . '/telegram-webhook.php';

$r = telegramApi($cfg['token'], 'setWebhook', [
    'url'          => $webhookUrl,
    'secret_token' => $secret,
    'allowed_updates' => json_encode(['callback_query', 'message']),
]);

echo "URL del webhook: $webhookUrl\n\n";

if (is_array($r) && !empty($r['ok'])) {
    echo "✅ Webhook activado correctamente.\n";
    echo "Descripción: " . ($r['description'] ?? '') . "\n\n";
    echo "Ya puedes aprobar/rechazar recargas desde los botones de Telegram.\n";
    echo "⚠️ Por seguridad, borra este archivo (telegram-setwebhook.php) del servidor.\n";
} else {
    echo "❌ No se pudo activar. Respuesta de Telegram:\n";
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
