<?php
// test-wpp.php — Diagnóstico de notificaciones por TELEGRAM
// ⚠️ BÓRRALO del servidor después de probar.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/whatsapp.php';
requireAdmin(); // Solo el admin puede ver esto

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO DE NOTIFICACIONES (TELEGRAM) ===\n\n";

$token  = trim((string)getConfig('telegram_token', ''));
$chatId = trim((string)getConfig('telegram_chat_id', ''));

echo "telegram_token   : " . ($token !== '' ? substr($token, 0, 12) . '...' : '(VACÍO)') . "\n";
echo "telegram_chat_id : " . ($chatId !== '' ? $chatId : '(VACÍO)') . "\n\n";

echo "allow_url_fopen  : " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "cURL disponible  : " . (function_exists('curl_init') ? 'SÍ' : 'NO') . "\n\n";

if ($token === '' || $chatId === '') {
    echo "❌ Falta el token o el chat_id en la tabla configuracion.\n";
    echo "   Guarda estas dos claves y vuelve a probar:\n";
    echo "     telegram_token   = (token de @BotFather)\n";
    echo "     telegram_chat_id = (chat_id del destinatario)\n";
    exit;
}

// Mensaje de prueba usando la MISMA función que usa el sistema real
$ok = enviarWhatsApp(
    "✅ *PRUEBA ModemStore*\n"
  . "━━━━━━━━━━━━━━━━━━\n"
  . "Si ves este mensaje, las notificaciones por Telegram *funcionan correctamente*. 🎉\n"
  . "🗓️ " . date('d/m/Y H:i:s')
);

echo "=== RESULTADO ===\n";
if ($ok) {
    echo "✅ Mensaje ENVIADO. Revisa el Telegram del destinatario.\n";
} else {
    echo "❌ No se pudo enviar. Revisa el error_log del servidor.\n";
    echo "   Causas comunes:\n";
    echo "   - El destinatario no le ha dado 'Iniciar' al bot.\n";
    echo "   - El token o el chat_id están mal.\n";
    echo "   - El servidor bloquea las conexiones salientes (firewall).\n";
}

echo "\n=== FIN ===\n";
echo "⚠️ Recuerda BORRAR este archivo (test-wpp.php) del servidor cuando termines.\n";
