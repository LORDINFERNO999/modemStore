<?php
// includes/imagenes.php
// ─────────────────────────────────────────────────────────────────
// Optimiza imágenes con GD: las redimensiona a un ancho máximo y las
// recomprime, manteniendo el formato y la transparencia (PNG/WebP).
// Reduce muchísimo el peso (ej: 10 MB → ~150 KB) sin afectar cómo se ven
// en las tarjetas/logos de la tienda.
// ─────────────────────────────────────────────────────────────────

/**
 * Optimiza una imagen en su misma ruta (la sobrescribe).
 * @param string $ruta      Ruta absoluta del archivo.
 * @param int    $maxAncho  Ancho máximo en píxeles (se reescala si es mayor).
 * @param int    $calidad   Calidad para JPEG/WebP (0-100).
 * @return bool  true si se optimizó.
 */
function optimizarImagen(string $ruta, int $maxAncho = 600, int $calidad = 82): bool {
    if (!extension_loaded('gd') || !is_file($ruta)) return false;

    $info = @getimagesize($ruta);
    if (!$info) return false; // no es imagen válida (ej: mp4)
    [$ancho, $alto] = $info;
    $tipo = $info[2];
    if ($ancho < 1 || $alto < 1) return false;

    switch ($tipo) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($ruta); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($ruta);  break;
        case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($ruta); break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif($ruta);  break;
        default: return false;
    }
    if (!$img) return false;

    // Nuevo tamaño (solo si excede el máximo; si no, se recomprime igual)
    if ($ancho > $maxAncho) {
        $nAncho = $maxAncho;
        $nAlto  = (int) round($alto * $maxAncho / $ancho);
    } else {
        $nAncho = $ancho;
        $nAlto  = $alto;
    }

    $dst = imagecreatetruecolor($nAncho, $nAlto);

    // Preservar transparencia en PNG/GIF
    if ($tipo === IMAGETYPE_PNG || $tipo === IMAGETYPE_GIF) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparente = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nAncho, $nAlto, $transparente);
    }

    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nAncho, $nAlto, $ancho, $alto);

    switch ($tipo) {
        case IMAGETYPE_PNG:  $ok = imagepng($dst, $ruta, 8);          break; // 0-9 compresión
        case IMAGETYPE_WEBP: $ok = imagewebp($dst, $ruta, $calidad);  break;
        case IMAGETYPE_GIF:  $ok = imagegif($dst, $ruta);             break;
        default:             $ok = imagejpeg($dst, $ruta, $calidad);  break;
    }

    imagedestroy($img);
    imagedestroy($dst);
    return (bool) $ok;
}
