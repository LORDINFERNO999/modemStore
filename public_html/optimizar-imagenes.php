<?php
// optimizar-imagenes.php
// ─────────────────────────────────────────────────────────────────
// Comprime TODAS las imágenes de assets/img/ (una sola vez).
// Ábrelo como admin: https://modemstores.com/optimizar-imagenes.php
// Se puede volver a abrir sin problema: salta las que ya están livianas.
// ⚠️ BÓRRALO del servidor cuando termines.
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seguridad.php';
require_once __DIR__ . '/includes/imagenes.php';
requireAdmin();

@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/assets/img/';
$maxAncho = 600;    // ancho máximo
$umbralKB = 300;    // solo optimiza las que pesen más de esto (para re-ejecutar rápido)

echo "=== OPTIMIZADOR DE IMÁGENES ===\n\n";
if (!is_dir($dir)) { echo "No existe la carpeta assets/img/\n"; exit; }

$archivos = glob($dir . '*');
$totalAntes = 0; $totalDespues = 0; $optimizadas = 0; $saltadas = 0; $errores = 0;

foreach ($archivos as $ruta) {
    if (!is_file($ruta)) continue;
    $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) { continue; } // ignora mp4, etc.

    $pesoAntes = filesize($ruta);
    if ($pesoAntes <= $umbralKB * 1024) { $saltadas++; continue; } // ya está liviana

    $totalAntes += $pesoAntes;
    $ok = optimizarImagen($ruta, $maxAncho, 82);
    clearstatcache(true, $ruta);
    $pesoDespues = filesize($ruta);
    $totalDespues += $pesoDespues;

    if ($ok) {
        $optimizadas++;
        printf("✓ %-34s %6s → %6s\n", basename($ruta), fmtKB($pesoAntes), fmtKB($pesoDespues));
    } else {
        $errores++;
        printf("✗ %-34s (no se pudo)\n", basename($ruta));
    }
    // Enviar salida progresivamente
    @ob_flush(); @flush();
}

echo "\n=== RESUMEN ===\n";
echo "Optimizadas: $optimizadas  |  Ya livianas (saltadas): $saltadas  |  Errores: $errores\n";
echo "Antes:  " . fmtKB($totalAntes) . "\n";
echo "Ahora:  " . fmtKB($totalDespues) . "\n";
if ($totalAntes > 0) {
    echo "Ahorro: " . round(100 - ($totalDespues * 100 / $totalAntes)) . "%\n";
}
echo "\nSi quedaron muchas por procesar y se cortó, vuelve a abrir esta página.\n";
echo "⚠️ Cuando termines, BORRA este archivo (optimizar-imagenes.php) del servidor.\n";

function fmtKB(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
    return round($bytes / 1024) . 'KB';
}
