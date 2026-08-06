<?php
// test-wpp.php — Diagnóstico de notificaciones WhatsApp (CallMeBot)
// ⚠️ BÓRRALO del servidor después de probar.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seguridad.php';
requireAdmin(); // Solo el admin puede ver esto

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO WHATSAPP (CallMeBot) ===\n\n";

$numero = trim((string)getConfig('whatsapp_admin', ''));
$apikey = trim((string)getConfig('whatsapp_apikey', ''));

echo "whatsapp_admin  : " . ($numero !== '' ? $numero : '(VACÍO)') . "\n";
echo "whatsapp_apikey : " . ($apikey !== '' ? $apikey : '(VACÍO)') . "\n\n";

echo "allow_url_fopen : " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "cURL disponible : " . (function_exists('curl_init') ? 'SÍ' : 'NO') . "\n\n";

if ($numero === '' || $apikey === '') {
    echo "❌ Falta el número o la apikey en la tabla configuracion.\n";
    exit;
}

$msg = "Prueba ModemStores " . date('H:i:s');
$url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
    'phone'  => $numero,
    'text'   => $msg,
    'apikey' => $apikey,
]);

echo "URL de prueba:\n$url\n\n";
echo "=== RESPUESTA DE CALLMEBOT ===\n";

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP code: $code\n";
    if ($err) echo "cURL error: $err\n";
    echo "\n" . ($resp !== false ? $resp : '(sin respuesta)') . "\n";
} elseif (ini_get('allow_url_fopen')) {
    $resp = @file_get_contents($url);
    echo ($resp !== false ? $resp : '(bloqueado / sin respuesta)') . "\n";
} else {
    echo "❌ El servidor no permite ni cURL ni allow_url_fopen. Contacta a Hostinger.\n";
}

echo "\n=== FIN ===\n";
echo "Si arriba dice 'Message queued' o 'You will receive it', revisa tu WhatsApp.\n";
echo "⚠️ Recuerda BORRAR este archivo (test-wpp.php) del servidor cuando termines.\n";
