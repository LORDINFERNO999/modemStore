<?php
// admin/servicios.php
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
require_once '../includes/imagenes.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';
// Tab activo para redirigir después de POST
$activeTab = $_GET['tab'] ?? 'servicios';

function subirImagen(string $campo, ?string &$error = null): ?string {
    if (empty($_FILES[$campo]['name'])) return null;
    $file = $_FILES[$campo];
    $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            $error = "La imagen '{$file['name']}' supera el límite de subida permitido por el servidor";
        else
            $error = "Error al subir '{$file['name']}' (código PHP: $err)";
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) { $error = "Formato no permitido para '{$file['name']}'"; return null; }
    if ($file['size'] > 15 * 1024 * 1024) { $pesoMb = round($file['size']/1024/1024,2); $error = "'{$file['name']}' pesa {$pesoMb}MB. Límite: 15MB"; return null; }
    $nombre  = uniqid('srv_') . '.' . $ext;
    $destino = __DIR__ . '/../assets/img/' . $nombre;
    if (!move_uploaded_file($file['tmp_name'], $destino)) { $error = "No se pudo guardar '{$file['name']}'. Revisa permisos de assets/img/"; return null; }
    optimizarImagen($destino, 600, 82); // comprime para que no pese de más
    return $nombre;
}

// Helper: redirect preservando tab y mensaje
function redir(string $tab, string $msg, string $tipo = 'ok'): void {
    $q = http_build_query(['tab' => $tab, 'msg' => $msg, 'tipo' => $tipo]);
    header("Location: servicios.php?$q");
    exit;
}

// Leer msg de GET (post-redirect-get)
if (isset($_GET['msg'])) {
    $msg     = htmlspecialchars($_GET['msg']);
    $msgTipo = ($_GET['tipo'] ?? 'ok') === 'err' ? 'err' : 'ok';
    $activeTab = $_GET['tab'] ?? 'servicios';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();
    $accion = $_POST['accion'] ?? '';

    // ── BULK SERVICIOS ──────────────────────────────────────────
    if ($accion === 'bulk_servicios') {
        $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $estado = ($_POST['bulk_estado'] ?? '') === 'activo' ? 'activo' : 'inactivo';
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE servicios SET estado=? WHERE id IN ($ph)")
                ->execute(array_merge([$estado], array_values($ids)));
            redir('servicios', count($ids) . ' servicio(s) ' . ($estado === 'activo' ? 'activados ✓' : 'desactivados ✓'));
        } else { redir('servicios', 'Selecciona al menos un servicio', 'err'); }
    }

    // ── BULK PLANES ─────────────────────────────────────────────
    if ($accion === 'bulk_planes') {
        $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $estado = ($_POST['bulk_estado'] ?? '') === 'activo' ? 'activo' : 'inactivo';
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE planes SET estado=? WHERE id IN ($ph)")
                ->execute(array_merge([$estado], array_values($ids)));
            redir('planes', count($ids) . ' plan(es) ' . ($estado === 'activo' ? 'activados ✓' : 'desactivados ✓'));
        } else { redir('planes', 'Selecciona al menos un plan', 'err'); }
    }

    if ($accion === 'crear_servicio') {
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $color  = trim($_POST['color'] ?? '#7c6dfa');
        $estadoInicial = ($_POST['estado_inicial'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo';
        $dupCheck = $pdo->prepare("SELECT id FROM servicios WHERE nombre = ?");
        $dupCheck->execute([$nombre]);
        if ($nombre && $dupCheck->fetch()) {
            redir('servicios', "Ya existe un servicio llamado '$nombre'.", 'err');
        } else {
            $imgErr = null; $img = subirImagen('imagen', $imgErr);
            if (!$img && !empty($_POST['imagen_existente'])) $img = basename($_POST['imagen_existente']);
            $imgCircErr = null; $imgCirc = subirImagen('imagen_circulo', $imgCircErr);
            if (!$imgCirc && !empty($_POST['imagen_circulo_existente'])) $imgCirc = basename($_POST['imagen_circulo_existente']);
            if ($nombre) {
                $pdo->prepare("INSERT INTO servicios (nombre,descripcion,imagen,color,imagen_circulo,estado) VALUES (?,?,?,?,?,?)")
                    ->execute([$nombre,$desc,$img,$color,$imgCirc,$estadoInicial]);
                $errores = array_filter([$imgErr, $imgCircErr]);
                $msgText = $errores ? "Servicio '$nombre' creado, pero: " . implode(' / ', $errores) : "Servicio '$nombre' creado ✓";
                redir('servicios', $msgText, $errores ? 'err' : 'ok');
            } else { redir('servicios', 'Nombre obligatorio', 'err'); }
        }
    }

    if ($accion === 'crear_servicio_bulk') {
        $items = json_decode($_POST['bulk_data'] ?? '[]', true);
        $creados = 0;
        foreach ($items as $item) {
            $nombre = trim($item['nombre'] ?? '');
            if (!$nombre) continue;
            $existe = $pdo->prepare("SELECT id FROM servicios WHERE nombre = ?");
            $existe->execute([$nombre]);
            if ($existe->fetch()) continue;
            $pdo->prepare("INSERT INTO servicios (nombre,descripcion,imagen,color) VALUES (?,?,?,?)")
                ->execute([$nombre, $item['desc'] ?? '', basename($item['imagen'] ?? ''), $item['color'] ?? '#7c6dfa']);
            $creados++;
        }
        if (!empty($_POST['bulk_planes'])) {
            $planesData = json_decode($_POST['bulk_planes'], true);
            foreach ($planesData as $pd) {
                $srvNombre = trim($pd['servicio'] ?? '');
                $srvRow = $pdo->prepare("SELECT id FROM servicios WHERE nombre = ?");
                $srvRow->execute([$srvNombre]);
                $srv = $srvRow->fetch();
                if (!$srv) continue;
                $existe = $pdo->prepare("SELECT id FROM planes WHERE nombre=? AND servicio_id=?");
                $existe->execute([$pd['nombre'], $srv['id']]);
                if ($existe->fetch()) continue;
                $pdo->prepare("INSERT INTO planes (nombre,descripcion,servicio_id,precio,duracion_dias) VALUES (?,?,?,?,?)")
                    ->execute([$pd['nombre'], $pd['desc'] ?? '', $srv['id'], (int)$pd['precio'], (int)($pd['dias'] ?? 30)]);
            }
        }
        redir('importar', "$creados servicios nuevos importados ✓");
    }

    if ($accion === 'editar_servicio') {
        $id = (int)$_POST['id']; $nombre = trim($_POST['nombre'] ?? '');
        $desc = trim($_POST['descripcion'] ?? ''); $color = trim($_POST['color'] ?? '#7c6dfa'); $estado = $_POST['estado'] ?? 'activo';
        $dupCheck = $pdo->prepare("SELECT id FROM servicios WHERE nombre = ? AND id != ?");
        $dupCheck->execute([$nombre, $id]);
        if ($dupCheck->fetch()) {
            redir('servicios', "Ya existe otro servicio llamado '$nombre'. No se realizaron cambios.", 'err');
        } else {
            $imgErr = null; $img = subirImagen('imagen', $imgErr);
            if (!$img && !empty($_POST['imagen_existente'])) $img = basename($_POST['imagen_existente']);
            if ($img) $pdo->prepare("UPDATE servicios SET nombre=?,descripcion=?,imagen=?,color=?,estado=? WHERE id=?")->execute([$nombre,$desc,$img,$color,$estado,$id]);
            else       $pdo->prepare("UPDATE servicios SET nombre=?,descripcion=?,color=?,estado=? WHERE id=?")->execute([$nombre,$desc,$color,$estado,$id]);
            $imgCircErr = null; $imgCirc = subirImagen('imagen_circulo', $imgCircErr);
            if (!$imgCirc && !empty($_POST['imagen_circulo_existente'])) $imgCirc = basename($_POST['imagen_circulo_existente']);
            if (!empty($_POST['imagen_circulo_quitar'])) $pdo->prepare("UPDATE servicios SET imagen_circulo=NULL WHERE id=?")->execute([$id]);
            elseif ($imgCirc) $pdo->prepare("UPDATE servicios SET imagen_circulo=? WHERE id=?")->execute([$imgCirc,$id]);
            $errores = array_filter([$imgErr, $imgCircErr]);
            $msgText = $errores ? "Servicio actualizado, pero: " . implode(' / ', $errores) : "Servicio actualizado ✓";
            redir('servicios', $msgText, $errores ? 'err' : 'ok');
        }
    }

    if ($accion === 'desactivar_servicio') {
        $pdo->prepare("UPDATE servicios SET estado='inactivo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('servicios', 'Servicio desactivado');
    }

    if ($accion === 'activar_servicio') {
        $pdo->prepare("UPDATE servicios SET estado='activo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('servicios', 'Servicio activado ✓');
    }

    if ($accion === 'eliminar_servicio') {
        $id = (int)$_POST['id'];
        // Desactivar planes asociados también
        $pdo->prepare("UPDATE planes SET estado='inactivo' WHERE servicio_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM servicios WHERE id=?")->execute([$id]);
        redir('servicios', 'Servicio eliminado permanentemente');
    }

    if ($accion === 'crear_plan') {
        $nombre = trim($_POST['nombre'] ?? ''); $desc = trim($_POST['descripcion'] ?? '');
        $servId = (int)$_POST['servicio_id']; $precio = (float)str_replace(['.','$',' '], '', $_POST['precio'] ?? '0');
        $duracion = (int)($_POST['duracion_dias'] ?? 30);
        $precioRevRaw = trim((string)($_POST['precio_revendedor'] ?? ''));
        $precioRev = ($precioRevRaw === '') ? null : (float)str_replace(['.','$',' '], '', $precioRevRaw);
        if ($precioRev !== null && $precioRev <= 0) $precioRev = null;
        $imgErr = null; $imgPlan = subirImagen('imagen', $imgErr);
        if (!$imgPlan && !empty($_POST['imagen_existente'])) $imgPlan = basename($_POST['imagen_existente']);
        if ($nombre && $servId && $precio >= 1000) {
            $pdo->prepare("INSERT INTO planes (nombre,descripcion,servicio_id,precio,duracion_dias,precio_revendedor,imagen) VALUES (?,?,?,?,?,?,?)")
                ->execute([$nombre,$desc,$servId,$precio,$duracion,$precioRev,$imgPlan]);
            $msgText = $imgErr ? "Plan '$nombre' creado, pero la imagen no se pudo subir: $imgErr" : "Plan '$nombre' creado ✓";
            redir('planes', $msgText, $imgErr ? 'err' : 'ok');
        } else { redir('planes', 'Completa todos los campos (precio mínimo $1.000)', 'err'); }
    }

    if ($accion === 'editar_plan') {
        $id = (int)$_POST['id']; $nombre = trim($_POST['nombre'] ?? ''); $desc = trim($_POST['descripcion'] ?? '');
        $precio = (float)str_replace(['.','$',' '], '', $_POST['precio'] ?? '0');
        $duracion = (int)($_POST['duracion_dias'] ?? 30); $estado = $_POST['estado'] ?? 'activo';
        $precioRevRaw = trim((string)($_POST['precio_revendedor'] ?? ''));
        $precioRev = ($precioRevRaw === '') ? null : (float)str_replace(['.','$',' '], '', $precioRevRaw);
        if ($precioRev !== null && $precioRev <= 0) $precioRev = null;

        // Verificar nombre duplicado en el mismo servicio
        $planActual = $pdo->prepare("SELECT servicio_id FROM planes WHERE id=?");
        $planActual->execute([$id]);
        $planRow = $planActual->fetch();
        if ($planRow) {
            $dupCheck = $pdo->prepare("SELECT id FROM planes WHERE nombre=? AND servicio_id=? AND id != ?");
            $dupCheck->execute([$nombre, $planRow['servicio_id'], $id]);
            if ($dupCheck->fetch()) {
                redir('planes', "Ya existe otro plan llamado '$nombre' en ese servicio. No se realizaron cambios.", 'err');
            }
        }

        $imgErr = null; $imgPlan = subirImagen('imagen', $imgErr);
        if (!$imgPlan && !empty($_POST['imagen_existente'])) $imgPlan = basename($_POST['imagen_existente']);
        if (!empty($_POST['imagen_quitar']))
            $pdo->prepare("UPDATE planes SET nombre=?,descripcion=?,precio=?,duracion_dias=?,estado=?,precio_revendedor=?,imagen=NULL WHERE id=?")->execute([$nombre,$desc,$precio,$duracion,$estado,$precioRev,$id]);
        elseif ($imgPlan)
            $pdo->prepare("UPDATE planes SET nombre=?,descripcion=?,precio=?,duracion_dias=?,estado=?,precio_revendedor=?,imagen=? WHERE id=?")->execute([$nombre,$desc,$precio,$duracion,$estado,$precioRev,$imgPlan,$id]);
        else
            $pdo->prepare("UPDATE planes SET nombre=?,descripcion=?,precio=?,duracion_dias=?,estado=?,precio_revendedor=? WHERE id=?")->execute([$nombre,$desc,$precio,$duracion,$estado,$precioRev,$id]);
        $msgText = $imgErr ? "Plan actualizado, pero la imagen no se pudo subir: $imgErr" : "Plan actualizado ✓";
        redir('planes', $msgText, $imgErr ? 'err' : 'ok');
    }

    if ($accion === 'cambiar_precio') {
        $id = (int)$_POST['id']; $precio = (float)str_replace(['.','$',' '], '', $_POST['precio_nuevo'] ?? '0');
        if ($id && $precio >= 1000) { $pdo->prepare("UPDATE planes SET precio=? WHERE id=?")->execute([$precio,$id]); redir('planes', 'Precio actualizado ✓'); }
        else { redir('planes', 'Precio inválido (mínimo $1.000)', 'err'); }
    }

    if ($accion === 'cambiar_precio_rev') {
        $id = (int)$_POST['id']; $raw = trim((string)($_POST['precio_rev_nuevo'] ?? ''));
        $precioRev = ($raw === '') ? null : (float)str_replace(['.','$',' '], '', $raw);
        if ($precioRev !== null && $precioRev <= 0) $precioRev = null;
        if ($id) { $pdo->prepare("UPDATE planes SET precio_revendedor=? WHERE id=?")->execute([$precioRev,$id]); redir('planes', 'Precio revendedor actualizado ✓'); }
    }

    if ($accion === 'desactivar_plan') {
        $pdo->prepare("UPDATE planes SET estado='inactivo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('planes', 'Plan desactivado');
    }

    if ($accion === 'activar_plan') {
        $pdo->prepare("UPDATE planes SET estado='activo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('planes', 'Plan activado ✓');
    }

    if ($accion === 'eliminar_plan') {
        $pdo->prepare("DELETE FROM planes WHERE id=?")->execute([(int)$_POST['id']]);
        redir('planes', 'Plan eliminado permanentemente');
    }
}

$servicios = $pdo->query("SELECT * FROM servicios ORDER BY nombre")->fetchAll();
$planes    = $pdo->query("SELECT p.*, s.nombre as servicio_nombre, s.color, s.imagen as servicio_imagen FROM planes p JOIN servicios s ON p.servicio_id = s.id ORDER BY s.nombre, p.precio")->fetchAll();
$imgDir = __DIR__ . '/../assets/img/'; $allImgs = [];
if (is_dir($imgDir)) { foreach (scandir($imgDir) as $f) { $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION)); if (in_array($ext, ['png','jpg','jpeg','webp','gif'])) $allImgs[] = $f; } }
sort($allImgs);
$serviciosExistentes = array_map(fn($s) => strtolower($s['nombre']), $servicios);
$nuevasCatalogo = [
    ['nombre'=>'Apple TV+','imagen'=>'APPLE PANTALLA.png','color'=>'#555555','desc'=>'Contenido original Apple','planes'=>[['nombre'=>'Apple TV+ Pantalla','desc'=>'1 pantalla','precio'=>12900,'dias'=>30],['nombre'=>'Apple TV+ Completo','desc'=>'Acceso completo','precio'=>22900,'dias'=>30]]],
    ['nombre'=>'Canva','imagen'=>'CANVA.png','color'=>'#7D2AE8','desc'=>'Diseño gráfico online','planes'=>[['nombre'=>'Canva 1 Mes','desc'=>'Plan mensual','precio'=>15000,'dias'=>30],['nombre'=>'Canva 1 Año','desc'=>'Plan anual','precio'=>69000,'dias'=>365]]],
    ['nombre'=>'Crunchyroll','imagen'=>'crunchyroll.png','color'=>'#F47521','desc'=>'Anime y manga online','planes'=>[['nombre'=>'Crunchyroll Pantalla','desc'=>'1 pantalla','precio'=>9900,'dias'=>30],['nombre'=>'Crunchyroll Completo','desc'=>'Acceso completo','precio'=>18900,'dias'=>30]]],
    ['nombre'=>'Deezer','imagen'=>'deezer.png','color'=>'#A238FF','desc'=>'Música en streaming','planes'=>[['nombre'=>'Deezer 1 Día','desc'=>'Acceso por 1 día','precio'=>2500,'dias'=>1],['nombre'=>'Deezer Premium','desc'=>'Premium mensual','precio'=>12900,'dias'=>30]]],
    ['nombre'=>'DGO','imagen'=>'DGO.png','color'=>'#E4001D','desc'=>'DirecTV GO','planes'=>[['nombre'=>'DGO 1 Día','desc'=>'Acceso 1 día','precio'=>3500,'dias'=>1],['nombre'=>'DGO Normal','desc'=>'Plan estándar','precio'=>19900,'dias'=>30]]],
    ['nombre'=>'Duolingo','imagen'=>'DUOLINGO.png','color'=>'#58CC02','desc'=>'Aprende idiomas','planes'=>[['nombre'=>'Duolingo Plus','desc'=>'Sin anuncios','precio'=>12900,'dias'=>30]]],
    ['nombre'=>'Xbox Game Pass','imagen'=>'GAME.png','color'=>'#107C10','desc'=>'Videojuegos','planes'=>[['nombre'=>'Game Pass Mensual','desc'=>'Acceso mensual','precio'=>19900,'dias'=>30]]],
    ['nombre'=>'IPTV','imagen'=>'IPTV.png','color'=>'#1A73E8','desc'=>'Canales en vivo','planes'=>[['nombre'=>'IPTV Completa','desc'=>'Todos los canales','precio'=>29900,'dias'=>30]]],
    ['nombre'=>'Max','imagen'=>'max.png','color'=>'#002BE7','desc'=>'HBO Max','planes'=>[['nombre'=>'Max Estándar','desc'=>'HD','precio'=>22900,'dias'=>30]]],
    ['nombre'=>'YouTube Premium','imagen'=>'YOUTUBE.png','color'=>'#FF0000','desc'=>'YouTube sin anuncios','planes'=>[['nombre'=>'YouTube Premium','desc'=>'Sin anuncios mensual','precio'=>19900,'dias'=>30]]],
    ['nombre'=>'ChatGPT Plus','imagen'=>'chatgpt.png','color'=>'#10A37F','desc'=>'GPT-4 ilimitado','planes'=>[['nombre'=>'ChatGPT Plus','desc'=>'GPT-4 ilimitado','precio'=>79000,'dias'=>30]]],
];
foreach ($nuevasCatalogo as &$nc) { $nc['ya_existe'] = in_array(strtolower($nc['nombre']), $serviciosExistentes); }
unset($nc);
$pendientes = array_filter($nuevasCatalogo, fn($n) => !$n['ya_existe']);
$pendientesCount = count($pendientes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Servicios &amp; Planes — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;--border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);--accent:#7c6dfa;--accent2:#f472b6;--text:#ffffff;--text2:#a3a3a3;--text3:#555555;--ok:#1db954;--warn:#f59e0b;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-0.3px;}
.nav-links{display:flex;gap:4px;}
.nav-links a{color:var(--text2);font-size:13px;text-decoration:none;padding:7px 14px;border-radius:var(--r);transition:all .2s;font-weight:500;}
.nav-links a:hover,.nav-links a.active{background:var(--s2);color:var(--text);}
.container{max-width:1400px;margin:0 auto;padding:28px 24px 60px;}
.flash{border-radius:var(--r);padding:12px 16px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-weight:500;}
.flash.ok{background:rgba(29,185,84,0.08);border:1px solid rgba(29,185,84,0.25);color:var(--ok);}
.flash.err{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);color:var(--err);}
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:5px;width:fit-content;margin-bottom:28px;}
.tab{padding:9px 22px;border-radius:var(--r);cursor:pointer;font-size:13px;font-weight:600;color:var(--text2);transition:all .2s;border:none;background:none;position:relative;}
.tab:hover{color:var(--text);}
.tab.active{background:var(--accent);color:#fff;}
.tab-badge{position:absolute;top:-4px;right:-4px;background:var(--warn);color:#000;font-size:10px;font-weight:800;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.panel{display:none;}
.panel.active{display:block;animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.grid2{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rxl);padding:22px;}
.card-title{font-size:14px;font-weight:700;letter-spacing:-0.2px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;}
.form-group input:focus,.form-group select:focus{border-color:rgba(255,255,255,0.25);}
.form-group input[type="color"]{padding:4px 6px;height:38px;cursor:pointer;}
.form-group input[type="file"]{padding:8px;font-size:12px;cursor:pointer;color:var(--text2);}
.form-group select option{background:#1e1e1e;}
.form-hint{font-size:11px;color:var(--text3);margin-top:4px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;letter-spacing:-0.1px;}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,109,250,.3);}
.btn-sm{padding:5px 11px;border-radius:6px;cursor:pointer;font-family:'Inter',sans-serif;font-size:11px;font-weight:700;border:none;transition:all .15s;white-space:nowrap;}
.btn-edit{background:rgba(124,109,250,.15);color:var(--accent);}
.btn-edit:hover{background:rgba(124,109,250,.28);}
.btn-deact{background:rgba(245,158,11,.15);color:var(--warn);}
.btn-deact:hover{background:rgba(245,158,11,.28);}
.btn-del{background:rgba(239,68,68,.15);color:var(--err);}
.btn-del:hover{background:rgba(239,68,68,.28);}
.btn-ok{background:rgba(29,185,84,.15);color:var(--ok);}
.btn-ok:hover{background:rgba(29,185,84,.28);}
.btn-warn{background:rgba(245,158,11,.15);color:var(--warn);}
.btn-warn:hover{background:rgba(245,158,11,.28);}
table{width:100%;border-collapse:collapse;}
th{background:var(--s2);padding:9px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);font-weight:700;}
th:first-child{border-radius:var(--r) 0 0 var(--r);}th:last-child{border-radius:0 var(--r) var(--r) 0;}
td{padding:11px 12px;border-top:1px solid var(--border);vertical-align:middle;}
tr:hover td{background:rgba(255,255,255,0.015);}
tr.row-selected td{background:rgba(124,109,250,0.06);}
.srv-img{width:32px;height:32px;object-fit:contain;border-radius:7px;background:var(--s2);padding:3px;}
.badge{padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.badge-ok{background:rgba(29,185,84,.12);color:var(--ok);}
.badge-no{background:rgba(239,68,68,.12);color:var(--err);}
.badge-propia{background:rgba(124,109,250,.12);color:var(--accent);}
.badge-heredada{background:rgba(255,255,255,.06);color:var(--text3);}
.color-dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0;}
.precio-wrap{display:flex;align-items:center;gap:7px;}
.precio-val{font-weight:800;color:var(--accent);font-size:13px;white-space:nowrap;}
.precio-rev{font-size:11px;color:var(--warn);font-weight:700;white-space:nowrap;}
.precio-rev-wrap{display:flex;align-items:center;gap:6px;margin-top:4px;}
.btn-edit-price{background:rgba(124,109,250,.12);border:1px solid rgba(124,109,250,.2);color:var(--accent);border-radius:5px;padding:2px 7px;font-size:10px;cursor:pointer;font-weight:700;transition:all .15s;font-family:'Inter',sans-serif;}
.btn-edit-price:hover{background:rgba(124,109,250,.25);}
.precio-form{display:none;align-items:center;gap:6px;}
.precio-form input{width:95px;padding:5px 8px;background:var(--s2);border:2px solid var(--accent);border-radius:7px;color:var(--text);font-size:13px;font-family:'Inter',sans-serif;font-weight:700;outline:none;}

/* ── BULK ACTION BAR ── */
.bulk-bar{display:none;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:rgba(124,109,250,0.08);border:1px solid rgba(124,109,250,0.25);border-radius:var(--r);margin-bottom:12px;flex-wrap:wrap;}
.bulk-bar.visible{display:flex;}
.bulk-bar-info{font-size:13px;font-weight:600;color:var(--accent);}
.bulk-bar-actions{display:flex;gap:8px;align-items:center;}
.btn-bulk-act{padding:7px 16px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px;font-weight:700;border:none;transition:all .15s;}
.btn-bulk-act.activate{background:rgba(29,185,84,.15);color:var(--ok);border:1px solid rgba(29,185,84,.3);}
.btn-bulk-act.activate:hover{background:rgba(29,185,84,.28);}
.btn-bulk-act.deactivate{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.3);}
.btn-bulk-act.deactivate:hover{background:rgba(245,158,11,.28);}
.btn-bulk-act.cancel{background:var(--s2);color:var(--text2);border:1px solid var(--border);}
.btn-bulk-act.cancel:hover{color:var(--text);}
.row-check{width:15px;height:15px;cursor:pointer;accent-color:var(--accent);}
th .row-check{margin:0;}

/* Import section */
.import-hero{background:linear-gradient(135deg,#0f0a2a,#1a0d1a);border:1px solid rgba(124,109,250,0.25);border-radius:var(--rxl);padding:24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.ih-text h2{font-size:18px;font-weight:800;letter-spacing:-0.3px;}
.ih-text p{font-size:13px;color:var(--text2);margin-top:4px;}
.ih-count{font-size:42px;font-weight:900;letter-spacing:-2px;color:var(--accent);background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap;}
.ih-sub{font-size:12px;color:var(--text3);text-align:center;}
.import-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px;}
.import-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:16px;display:flex;align-items:center;gap:12px;transition:border-color .2s;position:relative;}
.import-item.selected{border-color:rgba(124,109,250,0.5);background:rgba(124,109,250,0.04);}
.import-item.ya-existe{opacity:0.4;pointer-events:none;}
.import-item input[type="checkbox"]{width:16px;height:16px;cursor:pointer;flex-shrink:0;accent-color:var(--accent);}
.ii-img{width:40px;height:40px;border-radius:8px;background:var(--s2);padding:5px;object-fit:contain;flex-shrink:0;}
.ii-info{flex:1;min-width:0;}
.ii-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ii-meta{font-size:11px;color:var(--text3);margin-top:2px;}
.ii-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.ii-badge-existe{position:absolute;top:8px;right:10px;font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:rgba(29,185,84,.12);color:var(--ok);}
.import-bar{background:rgba(22,22,22,0.95);border:1px solid var(--border);border-radius:var(--rl);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;position:sticky;bottom:20px;backdrop-filter:blur(12px);}
.ib-info{font-size:13px;color:var(--text2);}
.ib-info b{color:var(--text);}
.btn-import-all{padding:11px 28px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-import-all:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,109,250,.35);}
.btn-import-all:disabled{opacity:0.4;cursor:not-allowed;transform:none;}
.btn-sel-all{padding:9px 16px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text2);font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-sel-all:hover{border-color:var(--border2);color:var(--text);}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(8px);padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rxl);padding:26px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;position:relative;animation:fadeUp .25s ease;}
.modal-close{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;background:var(--s2);border:none;color:var(--text2);cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.modal-close:hover{background:var(--s3);color:var(--text);}
.modal-title{font-size:17px;font-weight:800;margin-bottom:20px;letter-spacing:-0.3px;}
.img-preview{width:52px;height:52px;object-fit:contain;border-radius:var(--r);background:var(--s2);padding:5px;}
.img-select-wrap{position:relative;}
.img-selected-preview{display:flex;align-items:center;gap:10px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);cursor:pointer;transition:border-color .2s;margin-bottom:8px;}
.img-selected-preview:hover{border-color:rgba(255,255,255,0.2);}
.img-selected-preview img{width:36px;height:36px;object-fit:contain;border-radius:6px;background:var(--s3);padding:3px;}
.img-selected-preview span{font-size:12px;color:var(--text2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.img-selected-preview .toggle-arrow{font-size:10px;color:var(--text3);}
.img-dropdown{display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:var(--s2);border:1px solid var(--border2);border-radius:var(--r);max-height:220px;overflow-y:auto;box-shadow:0 12px 30px rgba(0,0,0,.5);}
.img-dropdown.open{display:block;}
.img-option{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;transition:background .1s;}
.img-option:hover{background:var(--s3);}
.img-option img{width:28px;height:28px;object-fit:contain;background:var(--s3);border-radius:5px;padding:2px;flex-shrink:0;}
.img-option span{font-size:12px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.img-search{padding:8px 12px;background:var(--s3);border:none;border-bottom:1px solid var(--border);color:var(--text);font-family:'Inter',sans-serif;font-size:12px;width:100%;outline:none;position:sticky;top:0;}
.img-search::placeholder{color:var(--text3);}

/* Upload overlay */
.upload-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:2000;display:none;flex-direction:column;align-items:center;justify-content:center;gap:16px;backdrop-filter:blur(6px);}
.upload-overlay.show{display:flex;}
.upload-spinner{width:48px;height:48px;border:4px solid rgba(124,109,250,.2);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.upload-overlay p{color:var(--text2);font-size:14px;font-weight:600;}

/* Confirm modal (replaces native confirm()) */
.confirm-modal{max-width:380px;text-align:center;padding:28px 26px;}
.confirm-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;}
.confirm-icon.danger{background:rgba(239,68,68,.12);}
.confirm-icon.warn{background:rgba(245,158,11,.12);}
.confirm-icon.ok{background:rgba(29,185,84,.12);}
.confirm-title{font-size:16px;font-weight:800;margin-bottom:8px;letter-spacing:-0.2px;}
.confirm-text{font-size:13px;color:var(--text2);line-height:1.55;margin-bottom:22px;}
.confirm-actions{display:flex;gap:10px;}
.btn-confirm{flex:1;padding:11px;border-radius:var(--r);border:none;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-confirm-cancel{background:var(--s2);color:var(--text2);border:1px solid var(--border);}
.btn-confirm-cancel:hover{color:var(--text);border-color:var(--border2);}
.btn-confirm-ok{color:#fff;}
.btn-confirm-ok.danger{background:linear-gradient(135deg,var(--err),#ff7a7a);}
.btn-confirm-ok.danger:hover{box-shadow:0 8px 20px rgba(239,68,68,.3);transform:translateY(-1px);}
.btn-confirm-ok.warn{background:linear-gradient(135deg,var(--warn),#ffb84d);}
.btn-confirm-ok.warn:hover{box-shadow:0 8px 20px rgba(245,158,11,.3);transform:translateY(-1px);}
.btn-confirm-ok.ok{background:linear-gradient(135deg,var(--ok),#2ee876);}
.btn-confirm-ok.ok:hover{box-shadow:0 8px 20px rgba(29,185,84,.3);transform:translateY(-1px);}

/* Mobile */
.mobile-cards{display:none;}
@media(max-width:1100px){.grid2{grid-template-columns:1fr;}}
@media(max-width:700px){.form-row{grid-template-columns:1fr;}.import-grid{grid-template-columns:1fr;}}
@media(max-width:600px){
  nav{padding:0 12px;height:50px;}.nav-links{display:none;}
  .container{padding:16px 12px 60px;}
  .tabs{flex-wrap:wrap;width:100%;gap:2px;padding:4px;}.tab{padding:8px 14px;font-size:12px;flex:1;text-align:center;}
  .grid2{grid-template-columns:1fr;}.card{padding:16px;border-radius:14px;}.card-title{font-size:13px;}
  .form-row{grid-template-columns:1fr;}
  .panel table{display:none;}.mobile-cards{display:flex;flex-direction:column;gap:10px;}
  .m-card{background:var(--s2);border:1px solid var(--border);border-radius:12px;padding:14px;}
  .m-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;gap:8px;}
  .m-card-img{width:36px;height:36px;border-radius:8px;object-fit:contain;background:var(--s3);padding:3px;flex-shrink:0;}
  .m-card-title{font-size:14px;font-weight:700;}.m-card-sub{font-size:11px;color:var(--text3);margin-top:2px;}
  .m-card-price{font-size:18px;font-weight:800;color:var(--accent);white-space:nowrap;margin-left:8px;text-align:right;}
  .m-card-price small{display:block;font-size:11px;color:var(--warn);font-weight:700;margin-top:2px;}
  .m-card-meta{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;font-size:11px;color:var(--text2);align-items:center;}
  .m-card-actions{display:flex;gap:6px;margin-top:10px;}
  .m-card-actions .btn-sm{flex:1;padding:10px;text-align:center;font-size:12px;border-radius:8px;}
  .m-srv-card{background:var(--s2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;align-items:flex-start;gap:12px;}
  .m-srv-name{font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .m-srv-file{font-size:11px;color:var(--text3);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;}
  .m-srv-actions{display:flex;gap:6px;margin-top:10px;}
  .m-srv-actions .btn-sm{flex:1;padding:9px;text-align:center;font-size:12px;border-radius:8px;}
  .import-hero{flex-direction:column;text-align:center;padding:18px;}
  .import-bar{flex-direction:column;gap:10px;position:static;}
  .bulk-bar{flex-direction:column;gap:8px;text-align:center;}
  .bulk-bar-actions{flex-wrap:wrap;justify-content:center;}
}
</style>
</head>
<body>

<!-- Upload overlay -->
<div class="upload-overlay" id="uploadOverlay">
  <div class="upload-spinner"></div>
  <p>Subiendo imagen, por favor espera&hellip;</p>
</div>

<!-- Confirm modal (reemplaza confirm() nativo) -->
<div class="modal-overlay" id="modalConfirmar">
  <div class="modal confirm-modal">
    <div class="confirm-icon" id="confirmIcon">&#x26A0;&#xFE0F;</div>
    <div class="confirm-title" id="confirmTitle">Confirmar acción</div>
    <p class="confirm-text" id="confirmText"></p>
    <div class="confirm-actions">
      <button type="button" class="btn-confirm btn-confirm-cancel" id="confirmBtnCancel">Cancelar</button>
      <button type="button" class="btn-confirm btn-confirm-ok" id="confirmBtnOk">Confirmar</button>
    </div>
  </div>
</div>

<nav>
  <div class="nav-logo">&#9881;&#65039; Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php" class="active">Servicios &amp; Planes</a>
    <a href="../index.php">&larr; Ver sitio</a>
    <a href="../logout.php" style="margin-left:auto;color:#ef4444">Salir</a>
  </div>
</nav>
<div class="container">

<?php if ($msg): ?>
<div class="flash <?= $msgTipo ?>"><?= $msgTipo==='ok' ? '&#x2713;' : '&#x26A0;' ?> <?= $msg ?></div>
<?php endif; ?>

<div class="tabs">
  <button class="tab <?= $activeTab==='importar'?'active':'' ?>" onclick="showTab('importar',this)" id="tab-importar">
    &#x1F680; Importar plataformas
    <?php if ($pendientesCount > 0): ?><span class="tab-badge"><?= $pendientesCount ?></span><?php endif; ?>
  </button>
  <button class="tab <?= $activeTab==='servicios'?'active':'' ?>" onclick="showTab('servicios',this)" id="tab-servicios">
    &#x1F3AC; Servicios (<?= count($servicios) ?>)
  </button>
  <button class="tab <?= $activeTab==='planes'?'active':'' ?>" onclick="showTab('planes',this)" id="tab-planes">
    &#x1F4CB; Planes &amp; Precios (<?= count($planes) ?>)
  </button>
</div>

<!-- ══ IMPORTAR ══ -->
<div class="panel <?= $activeTab==='importar'?'active':'' ?>" id="panel-importar">
  <div class="import-hero">
    <div class="ih-text"><h2>Importar nuevas plataformas</h2><p>Selecciona las plataformas que quieres agregar a la tienda con sus planes y precios precargados.</p></div>
    <div style="text-align:center"><div class="ih-count"><?= $pendientesCount ?></div><div class="ih-sub">plataformas disponibles</div></div>
  </div>
  <form method="POST" id="formImport">
    <?= csrfField() ?>
    <input type="hidden" name="accion" value="crear_servicio_bulk">
    <input type="hidden" name="bulk_data" id="bulk_data_input">
    <input type="hidden" name="bulk_planes" id="bulk_planes_input">
    <div class="import-grid" id="importGrid">
      <?php foreach ($nuevasCatalogo as $nc): ?>
      <label class="import-item <?= $nc['ya_existe']?'ya-existe':'' ?>" id="item-<?= htmlspecialchars(preg_replace('/\W/','_',$nc['nombre'])) ?>">
        <input type="checkbox" class="import-check" value="<?= htmlspecialchars($nc['nombre']) ?>"
               data-nombre="<?= htmlspecialchars($nc['nombre']) ?>" data-imagen="<?= htmlspecialchars($nc['imagen']) ?>"
               data-color="<?= htmlspecialchars($nc['color']) ?>" data-desc="<?= htmlspecialchars($nc['desc']) ?>"
               data-planes='<?= htmlspecialchars(json_encode($nc['planes'])) ?>'
               <?= $nc['ya_existe']?'disabled checked':'' ?>>
        <img class="ii-img" src="../assets/img/<?= htmlspecialchars($nc['imagen']) ?>" alt="" onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 40 40\'><rect fill=\'%23222\' width=\'40\' height=\'40\' rx=\'8\'/></svg>'">
        <div class="ii-info">
          <div style="display:flex;align-items:center;gap:6px"><span class="ii-dot" style="background:<?= htmlspecialchars($nc['color']) ?>"></span><span class="ii-name"><?= htmlspecialchars($nc['nombre']) ?></span></div>
          <div class="ii-meta"><?= count($nc['planes']) ?> plan<?= count($nc['planes'])>1?'es':'' ?> &middot; <?= htmlspecialchars($nc['desc']) ?></div>
        </div>
        <?php if ($nc['ya_existe']): ?><span class="ii-badge-existe">&#x2713; Agregado</span><?php endif; ?>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="import-bar">
      <div class="ib-info"><span id="selectedCount">0</span> plataformas seleccionadas &middot; <span id="planCount">0</span> planes en total</div>
      <div style="display:flex;gap:8px;align-items:center">
        <button type="button" class="btn-sel-all" onclick="selAll()">Seleccionar todas</button>
        <button type="submit" class="btn-import-all" id="btnImport" disabled onclick="prepareImport()">&#x2B07; Importar seleccionadas</button>
      </div>
    </div>
  </form>
</div>

<!-- ══ SERVICIOS ══ -->
<div class="panel <?= $activeTab==='servicios'?'active':'' ?>" id="panel-servicios">
  <div class="grid2">
    <div class="card">
      <div class="card-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg> Nuevo Servicio</div>
      <form method="POST" enctype="multipart/form-data" class="upload-form">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="crear_servicio">
        <div class="form-group"><label>Nombre</label><input type="text" name="nombre" placeholder="Ej: Netflix" required></div>
        <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" placeholder="Breve descripción"></div>
        <div class="form-row">
          <div class="form-group"><label>Color de marca</label><input type="color" name="color" value="#7c6dfa"></div>
          <div class="form-group"><label>Estado inicial</label><select name="estado_inicial"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
        </div>
        <div class="form-group">
          <label>Imagen existente</label>
          <div class="img-select-wrap">
            <div class="img-selected-preview" onclick="toggleImgDropdown('crear')">
              <img id="crear_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
              <span id="crear_preview_name">Seleccionar imagen&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
            </div>
            <input type="hidden" name="imagen_existente" id="crear_imagen_existente">
            <div class="img-dropdown" id="dropdown-crear">
              <input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('crear',this.value)">
              <div id="imgs-crear"><?php foreach($allImgs as $img): ?><div class="img-option" onclick="selectImg('crear','<?= htmlspecialchars($img) ?>')"><img src="../assets/img/<?= htmlspecialchars($img) ?>" onerror="this.style.display='none'"><span><?= htmlspecialchars($img) ?></span></div><?php endforeach; ?></div>
            </div>
          </div>
        </div>
        <div class="form-group"><label>— o subir nueva imagen</label><input type="file" name="imagen" accept="image/*" class="has-upload"></div>
        <div class="form-group" style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
          <label>Imagen del círculo / logo (opcional)</label>
          <div class="img-select-wrap">
            <div class="img-selected-preview" onclick="toggleImgDropdown('crearcirc')">
              <img id="crearcirc_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
              <span id="crearcirc_preview_name">Misma que la principal&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
            </div>
            <input type="hidden" name="imagen_circulo_existente" id="crearcirc_imagen_existente">
            <div class="img-dropdown" id="dropdown-crearcirc">
              <input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('crearcirc',this.value)">
              <div id="imgs-crearcirc"><?php foreach($allImgs as $img): ?><div class="img-option" onclick="selectImg('crearcirc','<?= htmlspecialchars($img) ?>')"><img src="../assets/img/<?= htmlspecialchars($img) ?>" onerror="this.style.display='none'"><span><?= htmlspecialchars($img) ?></span></div><?php endforeach; ?></div>
            </div>
          </div>
          <input type="file" name="imagen_circulo" accept="image/*" style="margin-top:8px" class="has-upload">
          <div class="form-hint">Si lo dejas vacío, el círculo usa la imagen principal.</div>
        </div>
        <button type="submit" class="btn-primary">Crear servicio &rarr;</button>
      </form>
    </div>

    <div class="card" style="overflow-x:auto">
      <div class="card-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Servicios registrados</div>

      <!-- Bulk bar servicios -->
      <div class="bulk-bar" id="bulkBarSrv">
        <span class="bulk-bar-info">&#x2713; <span id="bulkSrvCount">0</span> servicio(s) seleccionado(s)</span>
        <div class="bulk-bar-actions">
          <form method="POST" id="formBulkSrv" style="display:contents">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="bulk_servicios">
            <input type="hidden" name="bulk_estado" id="bulkSrvEstado" value="">
            <div id="bulkSrvHiddenIds"></div>
            <button type="button" class="btn-bulk-act activate" onclick="submitBulkSrv('activo')">&#x25B6; Activar</button>
            <button type="button" class="btn-bulk-act deactivate" onclick="submitBulkSrv('inactivo')">&#x23F8; Desactivar</button>
            <button type="button" class="btn-bulk-act cancel" onclick="clearSelSrv()">Cancelar</button>
          </form>
        </div>
      </div>

      <table>
        <thead><tr>
          <th style="width:32px"><input type="checkbox" class="row-check" id="checkAllSrv" onchange="toggleAllSrv(this)"></th>
          <th>Logo</th><th>Servicio</th><th>Estado</th><th>Acciones</th>
        </tr></thead>
        <tbody id="tbodySrv">
          <?php foreach($servicios as $s):
            $srvActivo = $s['estado'] === 'activo';
          ?>
          <tr id="srv-row-<?= $s['id'] ?>">
            <td><input type="checkbox" class="row-check srv-check" value="<?= $s['id'] ?>" onchange="updateBulkSrv()"></td>
            <td><img class="srv-img" src="../assets/img/<?= htmlspecialchars($s['imagen']??'') ?>" onerror="this.style.opacity='.15'"></td>
            <td>
              <div style="display:flex;align-items:center;gap:7px"><span class="color-dot" style="background:<?= htmlspecialchars($s['color']) ?>"></span><b><?= htmlspecialchars($s['nombre']) ?></b></div>
              <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= htmlspecialchars($s['imagen']??'') ?></div>
            </td>
            <td><span class="badge badge-<?= $srvActivo?'ok':'no' ?>"><?= $s['estado'] ?></span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <button class="btn-sm btn-edit" onclick='abrirEditServicio(<?= json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($allImgs) ?>)'>Editar</button>
                <?php if ($srvActivo): ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Desactivar este servicio? Dejará de verse en la tienda.','warn')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="accion" value="desactivar_servicio">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <button type="submit" class="btn-sm btn-deact">Desactivar</button>
                </form>
                <?php else: ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Activar este servicio? Volverá a verse en la tienda.','ok')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="accion" value="activar_servicio">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <button type="submit" class="btn-sm btn-ok">Activar</button>
                </form>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar permanentemente la plataforma «<?= htmlspecialchars(addslashes($s['nombre'])) ?>»? Sus planes se DESACTIVARÁN (no se borran). Esta acción no se puede deshacer.','danger')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="accion" value="eliminar_servicio">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <button type="submit" class="btn-sm btn-del">Eliminar</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Mobile cards servicios -->
      <div class="mobile-cards">
        <?php foreach($servicios as $s):
          $srvActivoM = $s['estado'] === 'activo';
        ?>
        <div class="m-srv-card">
          <input type="checkbox" class="row-check srv-check-m" value="<?= $s['id'] ?>" onchange="updateBulkSrv()" style="margin-top:3px;width:16px;height:16px;accent-color:var(--accent);flex-shrink:0">
          <img class="srv-img" style="width:40px;height:40px;flex-shrink:0" src="../assets/img/<?= htmlspecialchars($s['imagen']??'') ?>" onerror="this.style.opacity='.15'">
          <div style="flex:1;min-width:0">
            <div class="m-srv-name">
              <span class="color-dot" style="background:<?= htmlspecialchars($s['color']) ?>"></span>
              <?= htmlspecialchars($s['nombre']) ?>
              <span class="badge badge-<?= $srvActivoM?'ok':'no' ?>" style="margin-left:auto"><?= $s['estado'] ?></span>
            </div>
            <div class="m-srv-file"><?= htmlspecialchars($s['imagen']??'') ?></div>
            <div class="m-srv-actions">
              <button class="btn-sm btn-edit" onclick='abrirEditServicio(<?= json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($allImgs) ?>)'>&#x270F; Editar</button>
              <?php if ($srvActivoM): ?>
              <form method="POST" onsubmit="return confirmarAccion(this,'¿Desactivar este servicio?','warn')" style="flex:1">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="desactivar_servicio">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn-sm btn-deact" style="width:100%;padding:9px">Desactivar</button>
              </form>
              <?php else: ?>
              <form method="POST" onsubmit="return confirmarAccion(this,'¿Activar este servicio?','ok')" style="flex:1">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="activar_servicio">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn-sm btn-ok" style="width:100%;padding:9px">Activar</button>
              </form>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar la plataforma «<?= htmlspecialchars(addslashes($s['nombre'])) ?>»? Sus planes se desactivarán (no se borran). No se puede deshacer.','danger')" style="flex:1">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="eliminar_servicio">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn-sm btn-del" style="width:100%;padding:9px">Eliminar</button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ PLANES ══ -->
<div class="panel <?= $activeTab==='planes'?'active':'' ?>" id="panel-planes">
  <div class="grid2">
    <div class="card">
      <div class="card-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg> Nuevo Plan</div>
      <form method="POST" enctype="multipart/form-data" class="upload-form">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="crear_plan">
        <div class="form-group"><label>Servicio</label><select name="servicio_id" required><option value="">— Seleccionar —</option><?php foreach($servicios as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Nombre del plan</label><input type="text" name="nombre" placeholder="Ej: Netflix Premium" required></div>
        <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" placeholder="Ej: 4 pantallas, 4K HDR"></div>
        <div class="form-row">
          <div class="form-group"><label>Precio COP</label><input type="number" name="precio" placeholder="44900" min="1000" step="100" required></div>
          <div class="form-group"><label>Duración (días)</label><input type="number" name="duracion_dias" value="30" min="1" max="365"></div>
        </div>
        <div class="form-group">
          <label>Precio revendedor COP (opcional)</label>
          <input type="number" name="precio_revendedor" placeholder="Ej: 11000 (vacío = sin precio especial)" min="0" step="100">
        </div>
        <div class="form-group" style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
          <label>Imagen propia del plan (opcional)</label>
          <div class="img-select-wrap">
            <div class="img-selected-preview" onclick="toggleImgDropdown('planimg')">
              <img id="planimg_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
              <span id="planimg_preview_name">Usar la del servicio&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
            </div>
            <input type="hidden" name="imagen_existente" id="planimg_imagen_existente">
            <div class="img-dropdown" id="dropdown-planimg">
              <input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('planimg',this.value)">
              <div id="imgs-planimg"><?php foreach($allImgs as $img): ?><div class="img-option" onclick="selectImg('planimg','<?= htmlspecialchars($img) ?>')"><img src="../assets/img/<?= htmlspecialchars($img) ?>" onerror="this.style.display='none'"><span><?= htmlspecialchars($img) ?></span></div><?php endforeach; ?></div>
            </div>
          </div>
          <input type="file" name="imagen" accept="image/*" style="margin-top:8px" class="has-upload">
        </div>
        <button type="submit" class="btn-primary">Crear plan &rarr;</button>
      </form>
    </div>

    <div class="card" style="overflow-x:auto">
      <div class="card-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg> Planes — clic en precio para editar</div>

      <!-- Bulk bar planes -->
      <div class="bulk-bar" id="bulkBarPlan">
        <span class="bulk-bar-info">&#x2713; <span id="bulkPlanCount">0</span> plan(es) seleccionado(s)</span>
        <div class="bulk-bar-actions">
          <form method="POST" id="formBulkPlan" style="display:contents">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="bulk_planes">
            <input type="hidden" name="bulk_estado" id="bulkPlanEstado" value="">
            <div id="bulkPlanHiddenIds"></div>
            <button type="button" class="btn-bulk-act activate" onclick="submitBulkPlan('activo')">&#x25B6; Activar</button>
            <button type="button" class="btn-bulk-act deactivate" onclick="submitBulkPlan('inactivo')">&#x23F8; Desactivar</button>
            <button type="button" class="btn-bulk-act cancel" onclick="clearSelPlan()">Cancelar</button>
          </form>
        </div>
      </div>

      <table>
        <thead><tr>
          <th style="width:32px"><input type="checkbox" class="row-check" id="checkAllPlan" onchange="toggleAllPlan(this)"></th>
          <th>Img</th><th>Servicio</th><th>Plan</th><th>Precio COP</th><th>Días</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody id="tbodyPlan">
          <?php foreach($planes as $p):
            $imgPlanActual = $p['imagen'] ?: $p['servicio_imagen'];
            $planActivo = $p['estado'] === 'activo';
          ?>
          <tr id="plan-row-<?= $p['id'] ?>">
            <td><input type="checkbox" class="row-check plan-check" value="<?= $p['id'] ?>" onchange="updateBulkPlan()"></td>
            <td>
              <img class="srv-img" src="../assets/img/<?= htmlspecialchars($imgPlanActual ?? '') ?>" onerror="this.style.opacity='.15'">
              <div style="margin-top:3px"><span class="badge <?= $p['imagen'] ? 'badge-propia' : 'badge-heredada' ?>" style="font-size:8px"><?= $p['imagen'] ? 'propia' : 'servicio' ?></span></div>
            </td>
            <td><div style="display:flex;align-items:center;gap:6px"><span class="color-dot" style="background:<?= htmlspecialchars($p['color']) ?>"></span><?= htmlspecialchars($p['servicio_nombre']) ?></div></td>
            <td><div style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?></div><div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($p['descripcion']??'') ?></div></td>
            <td>
              <div class="precio-wrap" id="wrap-<?= $p['id'] ?>">
                <span class="precio-val">$ <?= number_format($p['precio'],0,'.','.') ?></span>
                <button type="button" class="btn-edit-price" onclick="mostrarPrecio(<?= $p['id'] ?>,<?= (int)$p['precio'] ?>)">&#x270F; Editar</button>
              </div>
              <form class="precio-form" id="form-precio-<?= $p['id'] ?>" method="POST">
                <?= csrfField() ?><input type="hidden" name="accion" value="cambiar_precio"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <input type="number" name="precio_nuevo" id="inp-<?= $p['id'] ?>" min="1000" step="100">
                <button type="submit" class="btn-sm btn-ok">&#x2713;</button>
                <button type="button" class="btn-sm btn-del" onclick="ocultarPrecio(<?= $p['id'] ?>)">&#x2715;</button>
              </form>
              <div class="precio-rev-wrap" id="wraprev-<?= $p['id'] ?>">
                <span class="precio-rev">&#x2B50; Revend.:
                  <?php if ($p['precio_revendedor'] !== null && (float)$p['precio_revendedor'] > 0): ?>$ <?= number_format($p['precio_revendedor'],0,'.','.') ?><?php else: ?><span style="color:var(--text3);font-weight:500">sin precio</span><?php endif; ?>
                </span>
                <button type="button" class="btn-edit-price" style="border-color:rgba(245,158,11,.3);color:var(--warn);background:rgba(245,158,11,.1)" onclick="mostrarPrecioRev(<?= $p['id'] ?>,<?= (int)($p['precio_revendedor'] ?? 0) ?>)">&#x270F;</button>
              </div>
              <form class="precio-form" id="form-rev-<?= $p['id'] ?>" method="POST">
                <?= csrfField() ?><input type="hidden" name="accion" value="cambiar_precio_rev"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <input type="number" name="precio_rev_nuevo" id="inprev-<?= $p['id'] ?>" min="0" step="100" placeholder="0 = ninguno">
                <button type="submit" class="btn-sm btn-ok">&#x2713;</button>
                <button type="button" class="btn-sm btn-del" onclick="ocultarPrecioRev(<?= $p['id'] ?>)">&#x2715;</button>
              </form>
            </td>
            <td style="color:var(--text2)"><?= $p['duracion_dias'] ?>d</td>
            <td><span class="badge badge-<?= $planActivo?'ok':'no' ?>"><?= $p['estado'] ?></span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <button type="button" class="btn-sm btn-edit" onclick='abrirEditPlan(<?= json_encode($p,JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($allImgs) ?>)'>Editar</button>
                <?php if ($planActivo): ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Desactivar este plan?','warn')" style="display:inline">
                  <?= csrfField() ?><input type="hidden" name="accion" value="desactivar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn-sm btn-deact">Desact.</button>
                </form>
                <?php else: ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Activar este plan?','ok')" style="display:inline">
                  <?= csrfField() ?><input type="hidden" name="accion" value="activar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn-sm btn-ok">Activar</button>
                </form>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar permanentemente el plan «<?= htmlspecialchars(addslashes($p['nombre'])) ?>»? No se puede deshacer.','danger')" style="display:inline">
                  <?= csrfField() ?><input type="hidden" name="accion" value="eliminar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn-sm btn-del">&#x2715;</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Mobile cards planes -->
      <div class="mobile-cards">
        <?php foreach($planes as $p):
          $imgPlanActualM = $p['imagen'] ?: $p['servicio_imagen'];
          $planActivoM = $p['estado'] === 'activo';
        ?>
        <div class="m-card">
          <div class="m-card-header">
            <input type="checkbox" class="row-check plan-check-m" value="<?= $p['id'] ?>" onchange="updateBulkPlan()" style="margin-top:4px;width:16px;height:16px;accent-color:var(--accent);flex-shrink:0">
            <img class="m-card-img" src="../assets/img/<?= htmlspecialchars($imgPlanActualM ?? '') ?>" onerror="this.style.opacity='.15'">
            <div style="flex:1;min-width:0">
              <div class="m-card-title"><?= htmlspecialchars($p['nombre']) ?></div>
              <div class="m-card-sub"><span class="color-dot" style="background:<?= htmlspecialchars($p['color']) ?>;display:inline-block;margin-right:4px"></span><?= htmlspecialchars($p['servicio_nombre']) ?></div>
            </div>
            <div class="m-card-price">$<?= number_format($p['precio'],0,'.','.') ?><?php if ($p['precio_revendedor'] !== null && (float)$p['precio_revendedor'] > 0): ?><small>&#x2B50; $<?= number_format($p['precio_revendedor'],0,'.','.') ?></small><?php endif; ?></div>
          </div>
          <div class="m-card-meta">
            <span>&#x1F4C5; <?= $p['duracion_dias'] ?> días</span>
            <span class="badge badge-<?= $planActivoM?'ok':'no' ?>"><?= $p['estado'] ?></span>
          </div>
          <div class="m-card-actions">
            <button type="button" class="btn-sm btn-edit" onclick='abrirEditPlan(<?= json_encode($p,JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($allImgs) ?>)'>&#x270F; Editar</button>
            <?php if ($planActivoM): ?>
            <form method="POST" onsubmit="return confirmarAccion(this,'¿Desactivar este plan?','warn')" style="flex:1">
              <?= csrfField() ?><input type="hidden" name="accion" value="desactivar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-sm btn-deact" style="width:100%;padding:10px">Desact.</button>
            </form>
            <?php else: ?>
            <form method="POST" onsubmit="return confirmarAccion(this,'¿Activar este plan?','ok')" style="flex:1">
              <?= csrfField() ?><input type="hidden" name="accion" value="activar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-sm btn-ok" style="width:100%;padding:10px">Activar</button>
            </form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar permanentemente «<?= htmlspecialchars(addslashes($p['nombre'])) ?>»?','danger')" style="flex:1">
              <?= csrfField() ?><input type="hidden" name="accion" value="eliminar_plan"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-sm btn-del" style="width:100%;padding:10px">Eliminar</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

</div><!-- /container -->

<!-- MODAL EDITAR SERVICIO -->
<div class="modal-overlay" id="modalServicio">
  <div class="modal">
    <button class="modal-close" onclick="cerrarModal('modalServicio')">&#x2715;</button>
    <div class="modal-title">Editar Servicio</div>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="editar_servicio">
      <input type="hidden" name="id" id="es_id">
      <div class="form-group"><label>Nombre</label><input type="text" name="nombre" id="es_nombre" required></div>
      <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" id="es_desc"></div>
      <div class="form-row">
        <div class="form-group"><label>Color</label><input type="color" name="color" id="es_color"></div>
        <div class="form-group"><label>Estado</label><select name="estado" id="es_estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
      </div>
      <div class="form-group">
        <label>Cambiar imagen</label>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px"><img id="es_preview" class="img-preview" src="" alt=""><span style="font-size:12px;color:var(--text3)">Imagen actual</span></div>
        <div class="img-select-wrap" style="margin-bottom:8px">
          <div class="img-selected-preview" onclick="toggleImgDropdown('edit')">
            <img id="edit_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
            <span id="edit_preview_name">Seleccionar imagen existente&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
          </div>
          <input type="hidden" name="imagen_existente" id="edit_imagen_existente">
          <div class="img-dropdown" id="dropdown-edit"><input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('edit',this.value)"><div id="imgs-edit"></div></div>
        </div>
        <input type="file" name="imagen" accept="image/*" class="has-upload" onchange="previewFile(this,'es_preview')">
      </div>
      <div class="form-group" style="border-top:1px solid var(--border);padding-top:14px">
        <label>Imagen del círculo / logo (opcional)</label>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px"><img id="escirc_preview" class="img-preview" src="" alt=""><span style="font-size:12px;color:var(--text3)">Círculo actual</span></div>
        <div class="img-select-wrap" style="margin-bottom:8px">
          <div class="img-selected-preview" onclick="toggleImgDropdown('editcirc')">
            <img id="editcirc_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
            <span id="editcirc_preview_name">Seleccionar imagen del círculo&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
          </div>
          <input type="hidden" name="imagen_circulo_existente" id="editcirc_imagen_existente">
          <div class="img-dropdown" id="dropdown-editcirc"><input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('editcirc',this.value)"><div id="imgs-editcirc"></div></div>
        </div>
        <input type="file" name="imagen_circulo" accept="image/*" class="has-upload" onchange="previewFile(this,'escirc_preview')">
        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--text2);cursor:pointer">
          <input type="checkbox" name="imagen_circulo_quitar" value="1" style="width:16px;height:16px;accent-color:var(--accent)"> Quitar imagen del círculo (usar la principal)
        </label>
      </div>
      <button type="submit" class="btn-primary">Guardar cambios &rarr;</button>
    </form>
  </div>
</div>

<!-- MODAL EDITAR PLAN -->
<div class="modal-overlay" id="modalPlan">
  <div class="modal">
    <button class="modal-close" onclick="cerrarModal('modalPlan')">&#x2715;</button>
    <div class="modal-title">Editar Plan</div>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="editar_plan">
      <input type="hidden" name="id" id="ep_id">
      <div class="form-group"><label>Nombre</label><input type="text" name="nombre" id="ep_nombre" required></div>
      <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" id="ep_desc"></div>
      <div class="form-row">
        <div class="form-group"><label>Precio COP</label><input type="number" name="precio" id="ep_precio" min="1000" step="100"></div>
        <div class="form-group"><label>Duración (días)</label><input type="number" name="duracion_dias" id="ep_dias" min="1"></div>
      </div>
      <div class="form-group"><label>Precio revendedor COP (opcional)</label><input type="number" name="precio_revendedor" id="ep_precio_rev" min="0" step="100" placeholder="Vacío = sin precio especial"></div>
      <div class="form-group" style="border-top:1px solid var(--border);padding-top:14px">
        <label>Imagen propia del plan (opcional)</label>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px"><img id="epimg_current_preview" class="img-preview" src="" alt=""><span style="font-size:12px;color:var(--text3)" id="epimg_current_label">Imagen actual</span></div>
        <div class="img-select-wrap" style="margin-bottom:8px">
          <div class="img-selected-preview" onclick="toggleImgDropdown('epimg')">
            <img id="epimg_preview_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
            <span id="epimg_preview_name">Seleccionar imagen existente&hellip;</span><span class="toggle-arrow">&#x25BE;</span>
          </div>
          <input type="hidden" name="imagen_existente" id="epimg_imagen_existente">
          <div class="img-dropdown" id="dropdown-epimg"><input class="img-search" type="text" placeholder="Buscar imagen&hellip;" oninput="filtrarImgs('epimg',this.value)"><div id="imgs-epimg"></div></div>
        </div>
        <input type="file" name="imagen" accept="image/*" class="has-upload" onchange="previewFile(this,'epimg_current_preview')">
        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--text2);cursor:pointer">
          <input type="checkbox" name="imagen_quitar" value="1" id="ep_imagen_quitar" style="width:16px;height:16px;accent-color:var(--accent)"> Quitar imagen propia (volver a usar la del servicio)
        </label>
      </div>
      <div class="form-group"><label>Estado</label><select name="estado" id="ep_estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
      <button type="submit" class="btn-primary">Guardar cambios &rarr;</button>
    </form>
  </div>
</div>

<script>
// ── Tabs ──
function showTab(tab,btn){
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.getElementById('panel-'+tab).classList.add('active');
  btn.classList.add('active');
}
function cerrarModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');});});

// ── Confirm modal genérico (reemplaza confirm() nativo) ──
let pendingConfirmForm = null;
let pendingConfirmCallback = null;
const ICONS = { danger: '&#x1F5D1;&#xFE0F;', warn: '&#x23F8;&#xFE0F;', ok: '&#x2705;' };
const TITLES = { danger: 'Eliminar', warn: 'Desactivar', ok: 'Activar' };
function confirmarAccion(form, mensaje, tipo){
  pendingConfirmForm = form;
  pendingConfirmCallback = null;
  abrirConfirmModal(mensaje, tipo);
  return false; // bloquea el submit nativo
}
function pedirConfirmacion(mensaje, tipo, callback){
  pendingConfirmForm = null;
  pendingConfirmCallback = callback;
  abrirConfirmModal(mensaje, tipo);
}
function abrirConfirmModal(mensaje, tipo){
  document.getElementById('confirmText').textContent = mensaje;
  document.getElementById('confirmTitle').textContent = TITLES[tipo] || 'Confirmar acción';
  document.getElementById('confirmIcon').className = 'confirm-icon ' + tipo;
  document.getElementById('confirmIcon').innerHTML = ICONS[tipo] || '&#x26A0;&#xFE0F;';
  const btnOk = document.getElementById('confirmBtnOk');
  btnOk.className = 'btn-confirm btn-confirm-ok ' + tipo;
  document.getElementById('modalConfirmar').classList.add('open');
}
document.getElementById('confirmBtnOk').addEventListener('click', function(){
  document.getElementById('modalConfirmar').classList.remove('open');
  if (pendingConfirmForm) { pendingConfirmForm.submit(); }
  else if (pendingConfirmCallback) { pendingConfirmCallback(); }
  pendingConfirmForm = null; pendingConfirmCallback = null;
});
document.getElementById('confirmBtnCancel').addEventListener('click', function(){
  document.getElementById('modalConfirmar').classList.remove('open');
  pendingConfirmForm = null; pendingConfirmCallback = null;
});

// ── Upload overlay (solo si hay archivo seleccionado) ──
document.querySelectorAll('.upload-form').forEach(form => {
  form.addEventListener('submit', function() {
    const hasFile = [...this.querySelectorAll('input[type="file"].has-upload')]
      .some(f => f.files && f.files.length > 0);
    if (hasFile) {
      document.getElementById('uploadOverlay').classList.add('show');
    }
  });
});

// ── Bulk Servicios ──
function getSrvChecked() {
  return [...document.querySelectorAll('.srv-check:checked, .srv-check-m:checked')]
    .map(c => c.value).filter((v,i,a) => a.indexOf(v) === i);
}
function updateBulkSrv() {
  const ids = getSrvChecked();
  const bar = document.getElementById('bulkBarSrv');
  document.getElementById('bulkSrvCount').textContent = ids.length;
  bar.classList.toggle('visible', ids.length > 0);
  document.querySelectorAll('.srv-check').forEach(c => {
    const mCb = document.querySelector(`.srv-check-m[value="${c.value}"]`);
    if (mCb) mCb.checked = c.checked;
  });
  document.querySelectorAll('.srv-check-m').forEach(c => {
    const dCb = document.querySelector(`.srv-check[value="${c.value}"]`);
    if (dCb) dCb.checked = c.checked;
  });
  document.querySelectorAll('#tbodySrv tr').forEach(tr => {
    const cb = tr.querySelector('.srv-check');
    if (cb) tr.classList.toggle('row-selected', cb.checked);
  });
}
function toggleAllSrv(master) {
  document.querySelectorAll('.srv-check, .srv-check-m').forEach(c => c.checked = master.checked);
  updateBulkSrv();
}
function clearSelSrv() {
  document.querySelectorAll('.srv-check, .srv-check-m').forEach(c => c.checked = false);
  document.getElementById('checkAllSrv').checked = false;
  updateBulkSrv();
}
function submitBulkSrv(estado) {
  const ids = getSrvChecked();
  if (!ids.length) return;
  const accion = estado === 'activo' ? 'activar' : 'desactivar';
  const tipo = estado === 'activo' ? 'ok' : 'warn';
  pedirConfirmacion(`¿${accion.charAt(0).toUpperCase()+accion.slice(1)} ${ids.length} servicio(s)?`, tipo, () => {
    document.getElementById('bulkSrvEstado').value = estado;
    const container = document.getElementById('bulkSrvHiddenIds');
    container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
    document.getElementById('formBulkSrv').submit();
  });
}

// ── Bulk Planes ──
function getPlanChecked() {
  return [...document.querySelectorAll('.plan-check:checked, .plan-check-m:checked')]
    .map(c => c.value).filter((v,i,a) => a.indexOf(v) === i);
}
function updateBulkPlan() {
  const ids = getPlanChecked();
  const bar = document.getElementById('bulkBarPlan');
  document.getElementById('bulkPlanCount').textContent = ids.length;
  bar.classList.toggle('visible', ids.length > 0);
  document.querySelectorAll('.plan-check').forEach(c => {
    const mCb = document.querySelector(`.plan-check-m[value="${c.value}"]`);
    if (mCb) mCb.checked = c.checked;
  });
  document.querySelectorAll('.plan-check-m').forEach(c => {
    const dCb = document.querySelector(`.plan-check[value="${c.value}"]`);
    if (dCb) dCb.checked = c.checked;
  });
  document.querySelectorAll('#tbodyPlan tr').forEach(tr => {
    const cb = tr.querySelector('.plan-check');
    if (cb) tr.classList.toggle('row-selected', cb.checked);
  });
}
function toggleAllPlan(master) {
  document.querySelectorAll('.plan-check, .plan-check-m').forEach(c => c.checked = master.checked);
  updateBulkPlan();
}
function clearSelPlan() {
  document.querySelectorAll('.plan-check, .plan-check-m').forEach(c => c.checked = false);
  document.getElementById('checkAllPlan').checked = false;
  updateBulkPlan();
}
function submitBulkPlan(estado) {
  const ids = getPlanChecked();
  if (!ids.length) return;
  const accion = estado === 'activo' ? 'activar' : 'desactivar';
  const tipo = estado === 'activo' ? 'ok' : 'warn';
  pedirConfirmacion(`¿${accion.charAt(0).toUpperCase()+accion.slice(1)} ${ids.length} plan(es)?`, tipo, () => {
    document.getElementById('bulkPlanEstado').value = estado;
    const container = document.getElementById('bulkPlanHiddenIds');
    container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
    document.getElementById('formBulkPlan').submit();
  });
}

// ── Precio inline ──
function mostrarPrecio(id,val){document.getElementById('wrap-'+id).style.display='none';const f=document.getElementById('form-precio-'+id);f.style.display='flex';const inp=document.getElementById('inp-'+id);inp.value=val;inp.focus();inp.select();}
function ocultarPrecio(id){document.getElementById('wrap-'+id).style.display='flex';document.getElementById('form-precio-'+id).style.display='none';}
function mostrarPrecioRev(id,val){document.getElementById('wraprev-'+id).style.display='none';const f=document.getElementById('form-rev-'+id);f.style.display='flex';const inp=document.getElementById('inprev-'+id);inp.value=val>0?val:'';inp.focus();inp.select();}
function ocultarPrecioRev(id){document.getElementById('wraprev-'+id).style.display='flex';document.getElementById('form-rev-'+id).style.display='none';}

// ── Modales ──
function abrirEditServicio(s,imgs){
  document.getElementById('es_id').value=s.id;
  document.getElementById('es_nombre').value=s.nombre;
  document.getElementById('es_desc').value=s.descripcion||'';
  document.getElementById('es_color').value=s.color||'#7c6dfa';
  document.getElementById('es_estado').value=s.estado;
  document.getElementById('es_preview').src='../assets/img/'+(s.imagen||'');
  document.getElementById('escirc_preview').src='../assets/img/'+(s.imagen_circulo||s.imagen||'');
  document.getElementById('edit_imagen_existente').value='';
  document.getElementById('edit_preview_name').textContent='Seleccionar imagen existente…';
  document.getElementById('editcirc_imagen_existente').value='';
  document.getElementById('editcirc_preview_name').textContent='Seleccionar imagen del círculo…';
  var cbq=document.querySelector('input[name="imagen_circulo_quitar"]');if(cbq)cbq.checked=false;
  var opts=imgs.map(img=>`<div class="img-option" onclick="selectImg('__CTX__','${img.replace(/'/g,"\\'")}')"><img src="../assets/img/${img}" onerror="this.style.display='none'"><span>${img}</span></div>`).join('');
  document.getElementById('imgs-edit').innerHTML=opts.replace(/__CTX__/g,'edit');
  document.getElementById('imgs-editcirc').innerHTML=opts.replace(/__CTX__/g,'editcirc');
  document.getElementById('modalServicio').classList.add('open');
}
function abrirEditPlan(p,imgs){
  document.getElementById('ep_id').value=p.id;
  document.getElementById('ep_nombre').value=p.nombre;
  document.getElementById('ep_desc').value=p.descripcion||'';
  document.getElementById('ep_precio').value=p.precio;
  document.getElementById('ep_precio_rev').value=(p.precio_revendedor!==null&&p.precio_revendedor!==undefined)?Math.round(p.precio_revendedor):'';
  document.getElementById('ep_dias').value=p.duracion_dias;
  document.getElementById('ep_estado').value=p.estado;
  const imgActual=p.imagen||p.servicio_imagen||'';
  document.getElementById('epimg_current_preview').src='../assets/img/'+imgActual;
  document.getElementById('epimg_current_label').textContent=p.imagen?'Imagen propia actual':'Usando imagen del servicio';
  document.getElementById('epimg_imagen_existente').value='';
  document.getElementById('epimg_preview_name').textContent='Seleccionar imagen existente…';
  document.getElementById('epimg_preview_img').src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>";
  document.getElementById('ep_imagen_quitar').checked=false;
  var opts=imgs.map(img=>`<div class="img-option" onclick="selectImg('epimg','${img.replace(/'/g,"\\'")}')"><img src="../assets/img/${img}" onerror="this.style.display='none'"><span>${img}</span></div>`).join('');
  document.getElementById('imgs-epimg').innerHTML=opts;
  document.getElementById('modalPlan').classList.add('open');
}
function previewFile(input,previewId){if(input.files&&input.files[0]){const r=new FileReader();r.onload=e=>document.getElementById(previewId).src=e.target.result;r.readAsDataURL(input.files[0]);}}
function toggleImgDropdown(ctx){const dd=document.getElementById('dropdown-'+ctx);dd.classList.toggle('open');if(dd.classList.contains('open'))dd.querySelector('.img-search').focus();}
function selectImg(ctx,img){
  document.getElementById(ctx+'_imagen_existente').value=img;
  document.getElementById(ctx+'_preview_img').src='../assets/img/'+img;
  document.getElementById(ctx+'_preview_name').textContent=img;
  document.getElementById('dropdown-'+ctx).classList.remove('open');
  if(ctx==='edit')document.getElementById('es_preview').src='../assets/img/'+img;
  if(ctx==='editcirc')document.getElementById('escirc_preview').src='../assets/img/'+img;
  if(ctx==='epimg')document.getElementById('epimg_current_preview').src='../assets/img/'+img;
}
function filtrarImgs(ctx,q){const container=document.getElementById('imgs-'+ctx);if(!container)return;q=q.toLowerCase();container.querySelectorAll('.img-option').forEach(opt=>{opt.style.display=opt.querySelector('span').textContent.toLowerCase().includes(q)?'':'none';});}
document.addEventListener('click',function(e){document.querySelectorAll('.img-dropdown.open').forEach(dd=>{if(!dd.closest('.img-select-wrap').contains(e.target))dd.classList.remove('open');});});

// ── Importar ──
const checks=document.querySelectorAll('.import-check:not(:disabled)');
function updateImportBar(){
  const sel=[...checks].filter(c=>c.checked);
  const planes=sel.reduce((acc,c)=>acc+JSON.parse(c.dataset.planes||'[]').length,0);
  document.getElementById('selectedCount').textContent=sel.length;
  document.getElementById('planCount').textContent=planes;
  document.getElementById('btnImport').disabled=sel.length===0;
  document.querySelectorAll('.import-item:not(.ya-existe)').forEach(item=>{const cb=item.querySelector('input');item.classList.toggle('selected',cb&&cb.checked);});
}
checks.forEach(c=>c.addEventListener('change',updateImportBar));
function selAll(){const allChecked=[...checks].every(c=>c.checked);checks.forEach(c=>c.checked=!allChecked);updateImportBar();document.querySelector('.btn-sel-all').textContent=allChecked?'Seleccionar todas':'Deseleccionar todas';}
function prepareImport(){
  const sel=[...checks].filter(c=>c.checked);
  const servicios=sel.map(c=>({nombre:c.dataset.nombre,imagen:c.dataset.imagen,color:c.dataset.color,desc:c.dataset.desc}));
  const planes=sel.flatMap(c=>{const ps=JSON.parse(c.dataset.planes||'[]');return ps.map(p=>({...p,servicio:c.dataset.nombre}));});
  document.getElementById('bulk_data_input').value=JSON.stringify(servicios);
  document.getElementById('bulk_planes_input').value=JSON.stringify(planes);
}
updateImportBar();
</script>
</body>
</html>