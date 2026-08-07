<?php
// admin/combos.php
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
requireAdmin();

$activeTab = $_GET['tab'] ?? 'lista';
$msg = ''; $msgTipo = 'ok';

if (isset($_GET['msg'])) {
    $msg     = htmlspecialchars($_GET['msg']);
    $msgTipo = ($_GET['tipo'] ?? 'ok') === 'err' ? 'err' : 'ok';
    $activeTab = $_GET['tab'] ?? 'lista';
}

function redir(string $tab, string $msg, string $tipo = 'ok'): void {
    header('Location: combos.php?' . http_build_query(['tab'=>$tab,'msg'=>$msg,'tipo'=>$tipo]));
    exit;
}

function subirImgCombo(string $campo, ?string &$err = null): ?string {
    if (empty($_FILES[$campo]['name'])) return null;
    $f   = $_FILES[$campo];
    $e   = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($e !== UPLOAD_ERR_OK) { $err = "Error al subir (código $e)"; return null; }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','webp','gif'])) { $err = "Formato no permitido"; return null; }
    if ($f['size'] > 15*1024*1024) { $err = "Imagen demasiado grande (máx 15MB)"; return null; }
    $nombre  = uniqid('combo_').'.'.$ext;
    $destino = __DIR__.'/../assets/img/'.$nombre;
    if (!move_uploaded_file($f['tmp_name'], $destino)) { $err = "No se pudo guardar"; return null; }
    return $nombre;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_combo') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $desc     = trim($_POST['descripcion'] ?? '');
        $precio   = (float) str_replace(['.','$',' '],'',$_POST['precio'] ?? '0');
        $pRevRaw  = trim($_POST['precio_revendedor'] ?? '');
        $pRev     = $pRevRaw === '' ? null : (float) str_replace(['.','$',' '],'',$pRevRaw);
        if ($pRev !== null && $pRev <= 0) $pRev = null;
        $color    = trim($_POST['color'] ?? '#7c6dfa');
        $duracion = (int)($_POST['duracion_dias'] ?? 30);
        $planIds  = array_filter(array_map('intval',(array)($_POST['plan_ids'] ?? [])));

        if (!$nombre)             redir('crear','Nombre obligatorio','err');
        if ($precio < 1000)       redir('crear','Precio mínimo $1.000','err');
        if (count($planIds) < 2)  redir('crear','Selecciona al menos 2 planes','err');

        $imgErr = null;
        $img    = subirImgCombo('imagen', $imgErr);
        if (!$img && !empty($_POST['imagen_existente'])) $img = basename($_POST['imagen_existente']);

        $pdo->prepare("INSERT INTO combos (nombre,descripcion,precio,precio_revendedor,imagen,color,duracion_dias) VALUES (?,?,?,?,?,?,?)")
            ->execute([$nombre,$desc,$precio,$pRev,$img,$color,$duracion]);
        $comboId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("INSERT IGNORE INTO combo_planes (combo_id,plan_id) VALUES (?,?)");
        foreach ($planIds as $pid) $st->execute([$comboId,$pid]);

        redir('lista', $imgErr ? "Combo '$nombre' creado, pero: $imgErr" : "Combo '$nombre' creado ✓", $imgErr ? 'err' : 'ok');
    }

    if ($accion === 'editar_combo') {
        $id       = (int)$_POST['id'];
        $nombre   = trim($_POST['nombre'] ?? '');
        $desc     = trim($_POST['descripcion'] ?? '');
        $precio   = (float) str_replace(['.','$',' '],'',$_POST['precio'] ?? '0');
        $pRevRaw  = trim($_POST['precio_revendedor'] ?? '');
        $pRev     = $pRevRaw === '' ? null : (float) str_replace(['.','$',' '],'',$pRevRaw);
        if ($pRev !== null && $pRev <= 0) $pRev = null;
        $color    = trim($_POST['color'] ?? '#7c6dfa');
        $estado   = $_POST['estado'] ?? 'activo';
        $duracion = (int)($_POST['duracion_dias'] ?? 30);
        $planIds  = array_filter(array_map('intval',(array)($_POST['plan_ids'] ?? [])));

        if (!$nombre)            redir('lista','Nombre obligatorio','err');
        if ($precio < 1000)      redir('lista','Precio mínimo $1.000','err');
        if (count($planIds) < 2) redir('lista','Selecciona al menos 2 planes','err');

        $imgErr = null;
        $img    = subirImgCombo('imagen',$imgErr);
        if (!$img && !empty($_POST['imagen_existente'])) $img = basename($_POST['imagen_existente']);

        if ($img)
            $pdo->prepare("UPDATE combos SET nombre=?,descripcion=?,precio=?,precio_revendedor=?,imagen=?,color=?,estado=?,duracion_dias=? WHERE id=?")
                ->execute([$nombre,$desc,$precio,$pRev,$img,$color,$estado,$duracion,$id]);
        else
            $pdo->prepare("UPDATE combos SET nombre=?,descripcion=?,precio=?,precio_revendedor=?,color=?,estado=?,duracion_dias=? WHERE id=?")
                ->execute([$nombre,$desc,$precio,$pRev,$color,$estado,$duracion,$id]);

        $pdo->prepare("DELETE FROM combo_planes WHERE combo_id=?")->execute([$id]);
        $st = $pdo->prepare("INSERT IGNORE INTO combo_planes (combo_id,plan_id) VALUES (?,?)");
        foreach ($planIds as $pid) $st->execute([$id,$pid]);

        redir('lista', $imgErr ? "Combo actualizado, pero: $imgErr" : "Combo actualizado ✓", $imgErr ? 'err' : 'ok');
    }

    if ($accion === 'activar_combo') {
        $pdo->prepare("UPDATE combos SET estado='activo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('lista','Combo activado ✓');
    }
    if ($accion === 'desactivar_combo') {
        $pdo->prepare("UPDATE combos SET estado='inactivo' WHERE id=?")->execute([(int)$_POST['id']]);
        redir('lista','Combo desactivado');
    }
    if ($accion === 'eliminar_combo') {
        $cid = (int)$_POST['id'];
        // Borrar relaciones primero (evita error de FK)
        $pdo->prepare("DELETE FROM combo_planes WHERE combo_id=?")->execute([$cid]);
        $pdo->prepare("DELETE FROM combos WHERE id=?")->execute([$cid]);
        redir('lista','Combo eliminado ✓');
    }
}

// Datos
$combos = $pdo->query("
    SELECT c.*,
           GROUP_CONCAT(cp.plan_id ORDER BY cp.plan_id) AS plan_ids_csv,
           GROUP_CONCAT(CONCAT(s.nombre,' — ',p.nombre) ORDER BY s.nombre SEPARATOR '|') AS planes_nombres
    FROM combos c
    LEFT JOIN combo_planes cp ON cp.combo_id = c.id
    LEFT JOIN planes p ON cp.plan_id = p.id
    LEFT JOIN servicios s ON p.servicio_id = s.id
    GROUP BY c.id ORDER BY c.nombre
")->fetchAll();

$planesDisp = $pdo->query("
    SELECT p.id,p.nombre,p.precio,s.nombre AS servicio_nombre,s.imagen AS servicio_imagen,s.color
    FROM planes p JOIN servicios s ON p.servicio_id=s.id
    WHERE p.estado='activo' ORDER BY s.nombre,p.nombre
")->fetchAll();

$imgDir = __DIR__.'/../assets/img/'; $allImgs = [];
if (is_dir($imgDir)) foreach (scandir($imgDir) as $f) { $ext=strtolower(pathinfo($f,PATHINFO_EXTENSION)); if(in_array($ext,['png','jpg','jpeg','webp','gif']))$allImgs[]=$f; }
sort($allImgs);

// Combo a editar
$ce = null; $cePlanIds = [];
if (isset($_GET['editar'])) {
    $eid = (int)$_GET['editar'];
    $st  = $pdo->prepare("SELECT * FROM combos WHERE id=?"); $st->execute([$eid]);
    $ce  = $st->fetch();
    if ($ce) {
        $activeTab = 'editar';
        $st2 = $pdo->prepare("SELECT plan_id FROM combo_planes WHERE combo_id=?"); $st2->execute([$eid]);
        $cePlanIds = array_column($st2->fetchAll(),'plan_id');
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Combos — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;--border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);--accent:#7c6dfa;--accent2:#f472b6;--text:#fff;--text2:#a3a3a3;--text3:#555;--ok:#1db954;--warn:#f59e0b;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.nav-links{display:flex;gap:4px;flex-wrap:wrap;}.nav-links a{color:var(--text2);font-size:13px;text-decoration:none;padding:7px 14px;border-radius:var(--r);transition:all .2s;font-weight:500;}
.nav-links a:hover,.nav-links a.active{background:var(--s2);color:var(--text);}
.container{max-width:1300px;margin:0 auto;padding:28px 24px 60px;}
.flash{border-radius:var(--r);padding:12px 16px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-weight:500;}
.flash.ok{background:rgba(29,185,84,.08);border:1px solid rgba(29,185,84,.25);color:var(--ok);}
.flash.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err);}
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:5px;width:fit-content;margin-bottom:28px;flex-wrap:wrap;}
.tab{padding:9px 22px;border-radius:var(--r);cursor:pointer;font-size:13px;font-weight:600;color:var(--text2);transition:all .2s;border:none;background:none;}
.tab:hover{color:var(--text);}.tab.active{background:var(--accent);color:#fff;}
.panel{display:none;}.panel.active{display:block;animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.grid2{display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rxl);padding:22px;}
.card-title{font-size:14px;font-weight:700;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.form-group input,.form-group select{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;}
.form-group input:focus,.form-group select:focus{border-color:rgba(124,109,250,.5);}
.form-group input[type="color"]{padding:4px 6px;height:38px;cursor:pointer;}
.form-group input[type="file"]{padding:8px;font-size:12px;cursor:pointer;color:var(--text2);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,109,250,.3);}
.planes-selector{display:flex;flex-direction:column;gap:6px;max-height:360px;overflow-y:auto;padding-right:4px;}
.planes-selector::-webkit-scrollbar{width:5px;}.planes-selector::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px;}
.pci{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);cursor:pointer;transition:all .15s;user-select:none;}
.pci:hover{border-color:rgba(124,109,250,.4);background:rgba(124,109,250,.05);}
.pci.selected{border-color:rgba(124,109,250,.6);background:rgba(124,109,250,.1);}
.pci input[type="checkbox"]{width:16px;height:16px;accent-color:var(--accent);flex-shrink:0;cursor:pointer;}
.pci-img{width:28px;height:28px;object-fit:contain;border-radius:6px;background:var(--s3);padding:3px;flex-shrink:0;}
.pci-name{font-size:13px;font-weight:600;flex:1;min-width:0;}
.pci-srv{font-size:11px;color:var(--text3);}
.pci-price{font-size:12px;color:var(--accent);font-weight:700;white-space:nowrap;}
.combo-preview{background:var(--s2);border:1px solid var(--border);border-radius:var(--r);padding:12px 14px;margin-top:12px;min-height:46px;}
.preview-tag{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:rgba(124,109,250,.15);border:1px solid rgba(124,109,250,.25);border-radius:20px;font-size:12px;color:var(--accent);font-weight:600;margin:3px;}
.sel-count{font-size:12px;color:var(--text3);margin-top:6px;}
table{width:100%;border-collapse:collapse;}
th{background:var(--s2);padding:9px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);font-weight:700;}
th:first-child{border-radius:var(--r) 0 0 var(--r);}th:last-child{border-radius:0 var(--r) var(--r) 0;}
td{padding:11px 12px;border-top:1px solid var(--border);vertical-align:middle;}
tr:hover td{background:rgba(255,255,255,.015);}
.btn-sm{padding:5px 11px;border-radius:6px;cursor:pointer;font-family:'Inter',sans-serif;font-size:11px;font-weight:700;border:none;transition:all .15s;white-space:nowrap;text-decoration:none;display:inline-block;}
.btn-edit{background:rgba(124,109,250,.15);color:var(--accent);}.btn-edit:hover{background:rgba(124,109,250,.28);}
.btn-deact{background:rgba(245,158,11,.15);color:var(--warn);}.btn-deact:hover{background:rgba(245,158,11,.28);}
.btn-act{background:rgba(29,185,84,.15);color:var(--ok);}.btn-act:hover{background:rgba(29,185,84,.28);}
.btn-del{background:rgba(239,68,68,.15);color:var(--err);}.btn-del:hover{background:rgba(239,68,68,.28);}
.badge{padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;}
.badge-ok{background:rgba(29,185,84,.12);color:var(--ok);}.badge-no{background:rgba(239,68,68,.12);color:var(--err);}
.color-dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0;}
.combo-img{width:38px;height:38px;object-fit:contain;border-radius:8px;background:var(--s2);padding:4px;}
.plan-tag{font-size:10px;padding:2px 8px;border-radius:12px;background:rgba(124,109,250,.1);color:var(--accent);font-weight:600;border:1px solid rgba(124,109,250,.2);display:inline-block;margin:2px;}
.precio-val{font-weight:800;color:var(--accent);font-size:13px;}
.precio-rev{font-size:11px;color:var(--warn);font-weight:700;}
.img-select-wrap{position:relative;}
.isp{display:flex;align-items:center;gap:10px;padding:8px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);cursor:pointer;margin-bottom:8px;}
.isp:hover{border-color:rgba(255,255,255,.2);}
.isp img{width:36px;height:36px;object-fit:contain;border-radius:6px;background:var(--s3);padding:3px;}
.isp span{font-size:12px;color:var(--text2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.img-dd{display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:var(--s2);border:1px solid var(--border2);border-radius:var(--r);max-height:220px;overflow-y:auto;box-shadow:0 12px 30px rgba(0,0,0,.5);}
.img-dd.open{display:block;}
.img-opt{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;}
.img-opt:hover{background:var(--s3);}
.img-opt img{width:28px;height:28px;object-fit:contain;border-radius:5px;background:var(--s3);padding:2px;flex-shrink:0;}
.img-opt span{font-size:12px;color:var(--text2);}
.img-srch{padding:8px 12px;background:var(--s3);border:none;border-bottom:1px solid var(--border);color:var(--text);font-family:'Inter',sans-serif;font-size:12px;width:100%;outline:none;position:sticky;top:0;}
.upload-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:2000;display:none;flex-direction:column;align-items:center;justify-content:center;gap:16px;backdrop-filter:blur(6px);}
.upload-overlay.show{display:flex;}
.upload-spinner{width:48px;height:48px;border:4px solid rgba(124,109,250,.2);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.upload-overlay p{color:var(--text2);font-size:14px;font-weight:600;}
.cmo{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:3000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(10px);padding:20px;}
.cmo.open{display:flex;}
.cmob{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rxl);padding:28px 26px 22px;width:100%;max-width:380px;text-align:center;}
.cmob-icon{font-size:36px;margin-bottom:12px;}.cmob-title{font-size:16px;font-weight:800;margin-bottom:8px;}
.cmob-body{font-size:13px;color:var(--text2);line-height:1.5;margin-bottom:22px;}
.cmob-acts{display:flex;gap:10px;}
.cmob-cancel{flex:1;padding:11px;background:var(--s2);border:1px solid var(--border2);border-radius:var(--r);color:var(--text2);font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;}
.cmob-ok{flex:1;padding:11px;border:none;border-radius:var(--r);font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;}
.cmob-ok.danger{background:rgba(239,68,68,.15);color:var(--err);border:1px solid rgba(239,68,68,.3);}
.cmob-ok.danger:hover{background:rgba(239,68,68,.28);}
.cmob-ok.warn{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.3);}
.cmob-ok.warn:hover{background:rgba(245,158,11,.28);}
.cmob-ok.success{background:rgba(29,185,84,.15);color:var(--ok);border:1px solid rgba(29,185,84,.3);}
.cmob-ok.success:hover{background:rgba(29,185,84,.28);}
.empty-state{text-align:center;padding:60px 20px;color:var(--text3);}
.empty-state .big{font-size:52px;margin-bottom:16px;}.empty-state p{font-size:14px;line-height:1.6;}
.search-bar{width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;margin-bottom:12px;}
.search-bar:focus{border-color:rgba(124,109,250,.5);}
@media(max-width:1100px){.grid2{grid-template-columns:1fr;}}
@media(max-width:600px){
  .form-row{grid-template-columns:1fr;}
  .nav-links{display:none;}
  /* Tabla de combos -> tarjetas en movil */
  .card[style*="overflow-x"]{overflow-x:visible !important;}
  table thead{display:none;}
  table, table tbody, table tr, table td{display:block;width:100%;}
  table tr{border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:12px;background:var(--surface,#161616);}
  table td{border:none;padding:6px 0;display:flex;justify-content:space-between;align-items:center;gap:12px;text-align:right;}
  table td::before{content:attr(data-label);font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);font-weight:700;flex-shrink:0;text-align:left;}
  table td[data-label="Imagen"]{justify-content:flex-start;padding-bottom:8px;}
  table td[data-label="Imagen"]::before{display:none;}
  table td[data-label="Planes"]{flex-direction:column;align-items:flex-end;}
  table td[data-label="Planes"]::before{margin-bottom:4px;}
  table td[data-label="Acciones"]{flex-direction:column;align-items:stretch;border-top:1px solid var(--border);margin-top:6px;padding-top:10px;}
  table td[data-label="Acciones"] > div{justify-content:flex-end;}
}
</style>
</head>
<body>
<div class="upload-overlay" id="uploadOverlay"><div class="upload-spinner"></div><p>Subiendo imagen…</p></div>
<div class="cmo" id="cmo">
  <div class="cmob">
    <div class="cmob-icon" id="cmo-icon"></div>
    <div class="cmob-title" id="cmo-title"></div>
    <div class="cmob-body" id="cmo-body"></div>
    <div class="cmob-acts">
      <button class="cmob-cancel" onclick="cerrarCmo()">Cancelar</button>
      <button class="cmob-ok" id="cmo-ok" onclick="ejecutarCmo()"></button>
    </div>
  </div>
</div>
<nav>
  <div class="nav-logo">⚙️ Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php">Servicios & Planes</a>
    <a href="combos.php" class="active">🎁 Combos</a>
    <a href="stock.php">Stock</a>
    <a href="pago.php">Configuración</a>
    <a href="../logout.php" style="margin-left:auto;color:#ef4444">Salir</a>
  </div>
</nav>
<div class="container">
<?php if ($msg): ?>
<div class="flash <?= $msgTipo ?>"><?= $msgTipo==='ok'?'✓':'⚠' ?> <?= $msg ?></div>
<?php endif; ?>
<div class="tabs">
  <button class="tab <?= $activeTab==='lista'?'active':'' ?>" onclick="showTab('lista',this)">📦 Combos (<?= count($combos) ?>)</button>
  <button class="tab <?= $activeTab==='crear'?'active':'' ?>" onclick="showTab('crear',this)">➕ Nuevo combo</button>
  <?php if ($ce): ?><button class="tab active">✏️ <?= htmlspecialchars($ce['nombre']) ?></button><?php endif; ?>
</div>

<!-- ══ LISTA ══ -->
<div class="panel <?= $activeTab==='lista'?'active':'' ?>" id="panel-lista">
  <?php if (empty($combos)): ?>
  <div class="empty-state">
    <div class="big">🎁</div>
    <p>No hay combos aún.<br>Crea tu primer paquete desde <b>Nuevo combo</b>.</p>
  </div>
  <?php else: ?>
  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Imagen</th><th>Nombre</th><th>Planes incluidos</th><th>Precio</th><th>Días</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($combos as $c):
          $pns = $c['planes_nombres'] ? explode('|',$c['planes_nombres']) : [];
          $nombreSafe = htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8');
        ?>
        <tr>
          <td data-label="Imagen"><?php if ($c['imagen']): ?><img class="combo-img" src="../assets/img/<?= htmlspecialchars($c['imagen']) ?>" onerror="this.style.opacity='.2'"><?php else: ?><div style="width:38px;height:38px;border-radius:8px;background:<?= htmlspecialchars($c['color']) ?>22;display:flex;align-items:center;justify-content:center;font-size:20px">🎁</div><?php endif; ?></td>
          <td data-label="Nombre">
            <div style="display:flex;align-items:center;gap:7px"><span class="color-dot" style="background:<?= htmlspecialchars($c['color']) ?>"></span><b><?= htmlspecialchars($c['nombre']) ?></b></div>
            <?php if ($c['descripcion']): ?><div style="font-size:11px;color:var(--text3);margin-top:2px"><?= htmlspecialchars($c['descripcion']) ?></div><?php endif; ?>
          </td>
          <td data-label="Planes"><?php foreach ($pns as $pn): ?><span class="plan-tag"><?= htmlspecialchars($pn) ?></span><?php endforeach; ?><?php if(empty($pns)): ?><span style="color:var(--text3);font-size:11px">Sin planes</span><?php endif; ?></td>
          <td data-label="Precio"><div class="precio-val">$ <?= number_format($c['precio'],0,'.','.') ?></div><?php if ($c['precio_revendedor']>0): ?><div class="precio-rev">· $ <?= number_format($c['precio_revendedor'],0,'.','.') ?></div><?php endif; ?></td>
          <td data-label="Días" style="color:var(--text2)"><?= $c['duracion_dias'] ?>d</td>
          <td data-label="Estado"><span class="badge badge-<?= $c['estado']==='activo'?'ok':'no' ?>"><?= $c['estado'] ?></span></td>
          <td data-label="Acciones">
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              <a href="combos.php?editar=<?= $c['id'] ?>" class="btn-sm btn-edit">Editar</a>
              <?php if ($c['estado']==='activo'): ?>
              <form method="POST" id="fc-d-<?= $c['id'] ?>" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="desactivar_combo">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="button" class="btn-sm btn-deact" data-action="desactivar" data-id="<?= $c['id'] ?>" data-nombre="<?= $nombreSafe ?>" onclick="confirmarAccion(this)">Desactivar</button>
              </form>
              <?php else: ?>
              <form method="POST" id="fc-a-<?= $c['id'] ?>" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="activar_combo">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="button" class="btn-sm btn-act" data-action="activar" data-id="<?= $c['id'] ?>" data-nombre="<?= $nombreSafe ?>" onclick="confirmarAccion(this)">Activar</button>
              </form>
              <?php endif; ?>
              <form method="POST" id="fc-del-<?= $c['id'] ?>" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="eliminar_combo">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="button" class="btn-sm btn-del" data-action="eliminar" data-id="<?= $c['id'] ?>" data-nombre="<?= $nombreSafe ?>" onclick="confirmarAccion(this)">Eliminar</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ CREAR ══ -->
<div class="panel <?= $activeTab==='crear'?'active':'' ?>" id="panel-crear">
  <div class="grid2">
    <div class="card">
      <div class="card-title">🎁 Datos del combo</div>
      <form method="POST" enctype="multipart/form-data" id="formCrear" class="upload-form">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="crear_combo">
        <div class="form-group"><label>Nombre</label><input type="text" name="nombre" placeholder="Ej: Pack Entretenimiento" required></div>
        <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" placeholder="Ej: Netflix + Spotify + Disney+"></div>
        <div class="form-row">
          <div class="form-group"><label>Precio COP</label><input type="number" name="precio" placeholder="49900" min="1000" step="100" required></div>
          <div class="form-group"><label>Duración (días)</label><input type="number" name="duracion_dias" value="30" min="1" max="365"></div>
        </div>
        <div class="form-group"><label>Precio revendedor COP (opcional)</label><input type="number" name="precio_revendedor" placeholder="Vacío = sin precio especial" min="0" step="100"></div>
        <div class="form-group"><label>Color de acento</label><input type="color" name="color" value="#7c6dfa"></div>
        <div class="form-group">
          <label>Imagen del combo (opcional)</label>
          <div class="img-select-wrap">
            <div class="isp" onclick="toggleDD('crear')">
              <img id="crear_prev_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
              <span id="crear_prev_name">Seleccionar imagen existente…</span><span style="font-size:10px;color:var(--text3)">▾</span>
            </div>
            <input type="hidden" name="imagen_existente" id="crear_imgex">
            <div class="img-dd" id="dd-crear">
              <input class="img-srch" type="text" placeholder="Buscar…" oninput="filtrarDD('crear',this.value)">
              <div id="opts-crear"><?php foreach($allImgs as $im): ?><div class="img-opt" onclick="selImg('crear','<?= htmlspecialchars($im) ?>')"><img src="../assets/img/<?= htmlspecialchars($im) ?>" onerror="this.style.display='none'"><span><?= htmlspecialchars($im) ?></span></div><?php endforeach; ?></div>
            </div>
          </div>
          <input type="file" name="imagen" accept="image/*" class="has-upload" style="margin-top:8px">
        </div>
        <div id="hids-crear"></div>
        <button type="submit" class="btn-primary" onclick="return submitCombo('crear')">Crear combo →</button>
      </form>
    </div>
    <div class="card">
      <div class="card-title">▪ Planes incluidos en el combo <span style="font-size:11px;color:var(--text3);font-weight:500">(mín. 2)</span></div>
      <input class="search-bar" type="text" placeholder="🔍 Buscar servicio o plan…" oninput="filtrarPlanes(this.value,'crear')">
      <div class="planes-selector" id="ps-crear">
        <?php foreach ($planesDisp as $pl): ?>
        <label class="pci" data-srv="<?= htmlspecialchars(strtolower($pl['servicio_nombre'])) ?>" data-nombre="<?= htmlspecialchars(strtolower($pl['nombre'])) ?>">
          <input type="checkbox" class="plan-cb" data-ctx="crear" value="<?= $pl['id'] ?>" onchange="updatePreview('crear')">
          <img class="pci-img" src="../assets/img/<?= htmlspecialchars($pl['servicio_imagen']) ?>" onerror="this.style.opacity='.2'">
          <div style="flex:1;min-width:0"><div class="pci-name"><?= htmlspecialchars($pl['nombre']) ?></div><div class="pci-srv"><?= htmlspecialchars($pl['servicio_nombre']) ?></div></div>
          <div class="pci-price">$<?= number_format($pl['precio'],0,'.','.') ?></div>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="combo-preview" id="prev-crear"><span style="color:var(--text3)">Selecciona al menos 2 planes…</span></div>
      <div class="sel-count" id="cnt-crear">0 seleccionados</div>
    </div>
  </div>
</div>

<!-- ══ EDITAR ══ -->
<?php if ($ce): ?>
<div class="panel active" id="panel-editar">
  <div class="grid2">
    <div class="card">
      <div class="card-title">✏️ Editar combo</div>
      <form method="POST" enctype="multipart/form-data" id="formEditar" class="upload-form">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="editar_combo">
        <input type="hidden" name="id" value="<?= $ce['id'] ?>">
        <div class="form-group"><label>Nombre</label><input type="text" name="nombre" value="<?= htmlspecialchars($ce['nombre']) ?>" required></div>
        <div class="form-group"><label>Descripción</label><input type="text" name="descripcion" value="<?= htmlspecialchars($ce['descripcion']??'') ?>"></div>
        <div class="form-row">
          <div class="form-group"><label>Precio COP</label><input type="number" name="precio" value="<?= (int)$ce['precio'] ?>" min="1000" step="100"></div>
          <div class="form-group"><label>Duración (días)</label><input type="number" name="duracion_dias" value="<?= $ce['duracion_dias'] ?>" min="1" max="365"></div>
        </div>
        <div class="form-group"><label>Precio revendedor (opcional)</label><input type="number" name="precio_revendedor" value="<?= $ce['precio_revendedor']>0?(int)$ce['precio_revendedor']:'' ?>" min="0" step="100" placeholder="Vacío = sin precio especial"></div>
        <div class="form-row">
          <div class="form-group"><label>Color</label><input type="color" name="color" value="<?= htmlspecialchars($ce['color']) ?>"></div>
          <div class="form-group"><label>Estado</label><select name="estado"><option value="activo" <?= $ce['estado']==='activo'?'selected':'' ?>>Activo</option><option value="inactivo" <?= $ce['estado']==='inactivo'?'selected':'' ?>>Inactivo</option></select></div>
        </div>
        <div class="form-group">
          <label>Imagen</label>
          <?php if ($ce['imagen']): ?><div style="display:flex;align-items:center;gap:10px;margin-bottom:8px"><img src="../assets/img/<?= htmlspecialchars($ce['imagen']) ?>" style="width:48px;height:48px;object-fit:contain;border-radius:8px;background:var(--s2);padding:4px"><span style="font-size:12px;color:var(--text3)">Actual</span></div><?php endif; ?>
          <div class="img-select-wrap">
            <div class="isp" onclick="toggleDD('editar')">
              <img id="editar_prev_img" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%23222' width='40' height='40' rx='8'/></svg>" alt="">
              <span id="editar_prev_name">Seleccionar imagen…</span><span style="font-size:10px;color:var(--text3)">▾</span>
            </div>
            <input type="hidden" name="imagen_existente" id="editar_imgex" value="<?= htmlspecialchars($ce['imagen'] ?? '') ?>">
            <div class="img-dd" id="dd-editar">
              <input class="img-srch" type="text" placeholder="Buscar…" oninput="filtrarDD('editar',this.value)">
              <div id="opts-editar"><?php foreach($allImgs as $im): ?><div class="img-opt" onclick="selImg('editar','<?= htmlspecialchars($im) ?>')"><img src="../assets/img/<?= htmlspecialchars($im) ?>" onerror="this.style.display='none'"><span><?= htmlspecialchars($im) ?></span></div><?php endforeach; ?></div>
            </div>
          </div>
          <input type="file" name="imagen" accept="image/*" class="has-upload" style="margin-top:8px">
        </div>
        <div id="hids-editar"></div>
        <button type="submit" class="btn-primary" onclick="return submitCombo('editar')">Guardar cambios →</button>
      </form>
    </div>
    <div class="card">
      <div class="card-title">▪ Planes del combo</div>
      <input class="search-bar" type="text" placeholder="🔍 Buscar…" oninput="filtrarPlanes(this.value,'editar')">
      <div class="planes-selector" id="ps-editar">
        <?php foreach ($planesDisp as $pl): $sel=in_array($pl['id'],$cePlanIds); ?>
        <label class="pci <?= $sel?'selected':'' ?>" data-srv="<?= htmlspecialchars(strtolower($pl['servicio_nombre'])) ?>" data-nombre="<?= htmlspecialchars(strtolower($pl['nombre'])) ?>">
          <input type="checkbox" class="plan-cb" data-ctx="editar" value="<?= $pl['id'] ?>" <?= $sel?'checked':'' ?> onchange="updatePreview('editar')">
          <img class="pci-img" src="../assets/img/<?= htmlspecialchars($pl['servicio_imagen']) ?>" onerror="this.style.opacity='.2'">
          <div style="flex:1;min-width:0"><div class="pci-name"><?= htmlspecialchars($pl['nombre']) ?></div><div class="pci-srv"><?= htmlspecialchars($pl['servicio_nombre']) ?></div></div>
          <div class="pci-price">$<?= number_format($pl['precio'],0,'.','.') ?></div>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="combo-preview" id="prev-editar"><span style="color:var(--text3)">Cargando…</span></div>
      <div class="sel-count" id="cnt-editar">0 seleccionados</div>
    </div>
  </div>
</div>
<?php endif; ?>

</div>
<script>
function showTab(t,btn){document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));document.getElementById('panel-'+t)?.classList.add('active');if(btn)btn.classList.add('active');}
document.querySelectorAll('.upload-form').forEach(f=>f.addEventListener('submit',function(){if([...this.querySelectorAll('input[type="file"].has-upload')].some(i=>i.files&&i.files.length))document.getElementById('uploadOverlay').classList.add('show');}));

let _cmoCb=null;
function confirmarAccion(btn){
  const action=btn.dataset.action, nombre=btn.dataset.nombre, form=btn.closest('form');
  let icon,title,body,confirmText,type;
  if(action==='eliminar'){
    icon='🗑️';title='Eliminar combo';
    body='¿Eliminar permanentemente <b>'+nombre+'</b>?<br><span style="color:var(--err);font-size:11px">No se puede deshacer.</span>';
    confirmText='Eliminar';type='danger';
  }else if(action==='desactivar'){
    icon='⏸️';title='¿Desactivar combo?';
    body='<b>'+nombre+'</b> dejará de mostrarse en la tienda.';
    confirmText='Desactivar';type='warn';
  }else if(action==='activar'){
    icon='▶️';title='¿Activar combo?';
    body='<b>'+nombre+'</b> volverá a la tienda.';
    confirmText='Activar';type='success';
  }
  document.getElementById('cmo-icon').textContent=icon;
  document.getElementById('cmo-title').textContent=title;
  document.getElementById('cmo-body').innerHTML=body;
  const okBtn=document.getElementById('cmo-ok');
  okBtn.textContent=confirmText;okBtn.className='cmob-ok '+type;
  _cmoCb=function(){form.submit();};
  document.getElementById('cmo').classList.add('open');
}
function cerrarCmo(){document.getElementById('cmo').classList.remove('open');_cmoCb=null;}
function ejecutarCmo(){const cb=_cmoCb;cerrarCmo();if(cb)cb();}
document.getElementById('cmo').addEventListener('click',e=>{if(e.target===document.getElementById('cmo'))cerrarCmo();});

function toggleDD(ctx){const d=document.getElementById('dd-'+ctx);d.classList.toggle('open');if(d.classList.contains('open'))d.querySelector('.img-srch').focus();}
function selImg(ctx,img){document.getElementById(ctx+'_imgex').value=img;document.getElementById(ctx+'_prev_img').src='../assets/img/'+img;document.getElementById(ctx+'_prev_name').textContent=img;document.getElementById('dd-'+ctx).classList.remove('open');}
function filtrarDD(ctx,q){q=q.toLowerCase();document.querySelectorAll('#opts-'+ctx+' .img-opt').forEach(o=>o.style.display=o.querySelector('span').textContent.toLowerCase().includes(q)?'':'none');}
document.addEventListener('click',e=>{document.querySelectorAll('.img-dd.open').forEach(d=>{if(!d.closest('.img-select-wrap').contains(e.target))d.classList.remove('open');});});

function filtrarPlanes(q,ctx){q=q.toLowerCase();document.querySelectorAll('#ps-'+ctx+' .pci').forEach(i=>{i.style.display=(i.dataset.srv+i.dataset.nombre).includes(q)?'':'none';});}
function updatePreview(ctx){
  const cbs=[...document.querySelectorAll('.plan-cb[data-ctx="'+ctx+'"]:checked')];
  document.querySelectorAll('#ps-'+ctx+' .pci').forEach(i=>i.classList.toggle('selected',i.querySelector('input')?.checked));
  document.getElementById('cnt-'+ctx).textContent=cbs.length+' seleccionados';
  const prev=document.getElementById('prev-'+ctx);
  if(!cbs.length){prev.innerHTML='<span style="color:var(--text3)">Selecciona al menos 2 planes…</span>';return;}
  prev.innerHTML=cbs.map(cb=>{const i=cb.closest('.pci');return'<span class="preview-tag">'+i.querySelector('.pci-srv').textContent+': '+i.querySelector('.pci-name').textContent+'</span>';}).join('');
}
function submitCombo(ctx){
  const cbs=[...document.querySelectorAll('.plan-cb[data-ctx="'+ctx+'"]:checked')];
  if(cbs.length < 2){
    const prev=document.getElementById('prev-'+ctx);
    prev.innerHTML='<span style="color:var(--err);font-weight:700">⚠ Selecciona al menos 2 planes para crear el combo.</span>';
    prev.scrollIntoView({behavior:'smooth',block:'center'});
    return false;
  }
  document.getElementById('hids-'+ctx).innerHTML=cbs.map(cb=>'<input type="hidden" name="plan_ids[]" value="'+cb.value+'">').join('');
  return true;
}
<?php if($ce): ?>updatePreview('editar');<?php endif; ?>
</script>
</body>
</html>
