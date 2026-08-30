<?php
// admin/pago.php — Datos de pago (QR + cuenta destino) editables por el admin
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
require_once '../includes/imagenes.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();

    setConfig('pago_titular',       trim($_POST['pago_titular'] ?? ''));
    setConfig('pago_llave',         trim($_POST['pago_llave'] ?? ''));
    setConfig('pago_banco',         trim($_POST['pago_banco'] ?? ''));
    setConfig('pago_instrucciones', trim($_POST['pago_instrucciones'] ?? ''));

    // QR (opcional): subir imagen nueva
    if (!empty($_FILES['pago_qr']['name']) && ($_FILES['pago_qr']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['pago_qr']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            if ($_FILES['pago_qr']['size'] <= 3 * 1024 * 1024) {
                $nombre = 'qr-pago.' . $ext;
                if (move_uploaded_file($_FILES['pago_qr']['tmp_name'], __DIR__ . '/../assets/img/' . $nombre)) {
                    optimizarImagen(__DIR__ . '/../assets/img/' . $nombre, 600, 82);
                    setConfig('pago_qr', $nombre);
                } else { $msg = 'No se pudo guardar el QR.'; $msgTipo = 'err'; }
            } else { $msg = 'El QR supera 3 MB.'; $msgTipo = 'err'; }
        } else { $msg = 'El QR debe ser una imagen (JPG/PNG/WEBP).'; $msgTipo = 'err'; }
    }

    if ($msg === '') { $msg = 'Datos de pago guardados ✓'; }
}

$titular = getConfig('pago_titular', '');
$llave   = getConfig('pago_llave', '');
$banco   = getConfig('pago_banco', '');
$instr   = getConfig('pago_instrucciones', '');
$qr      = getConfig('pago_qr', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Datos de pago — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--border:rgba(255,255,255,.08);--accent:#7c6dfa;--text:#fff;--text2:#a3a3a3;--text3:#555;--ok:#1db954;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),#f472b6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.nav-links{display:flex;gap:4px;flex-wrap:wrap;}
.nav-links a{color:var(--text2);font-size:13px;text-decoration:none;padding:7px 14px;border-radius:var(--r);transition:all .2s;font-weight:500;}
.nav-links a:hover,.nav-links a.active{background:var(--s2);color:var(--text);}
.container{max-width:900px;margin:0 auto;padding:28px 24px 60px;}
h1{font-size:22px;font-weight:800;margin-bottom:4px;}
.sub{color:var(--text2);font-size:13px;margin-bottom:22px;}
.flash{border-radius:var(--r);padding:12px 16px;font-size:13px;margin-bottom:20px;font-weight:500;}
.flash.ok{background:rgba(29,185,84,.08);border:1px solid rgba(29,185,84,.25);color:var(--ok);}
.flash.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err);}
.grid{display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rxl);padding:22px;}
.card-title{font-size:14px;font-weight:700;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.form-group input,.form-group textarea{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;}
.form-group textarea{min-height:120px;resize:vertical;}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.qr-prev{width:100%;border-radius:var(--rl);background:#fff;padding:14px;text-align:center;}
.qr-prev img{max-width:100%;border-radius:8px;}
.qr-empty{color:var(--text3);font-size:13px;padding:40px 10px;text-align:center;border:1px dashed var(--border);border-radius:var(--rl);}
@media(max-width:760px){.grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<nav>
  <div class="nav-logo">⚙️ Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php">Servicios &amp; Planes</a>
    <a href="stock.php">Stock</a>
    <a href="pedidos.php">Pedidos</a>
    <a href="usuarios.php">Usuarios</a>
    <a href="pago.php" class="active">Datos de pago</a>
    <a href="../logout.php" style="margin-left:auto;color:#ef4444">Salir</a>
  </div>
</nav>

<div class="container">
  <h1>Datos de pago</h1>
  <p class="sub">El cliente verá este QR e información al comprar un servicio.</p>

  <?php if ($msg): ?>
  <div class="flash <?= $msgTipo ?>"><?= $msgTipo === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="grid">
      <div class="card">
        <div class="card-title">Cuenta destino</div>
        <div class="form-group">
          <label>Titular</label>
          <input type="text" name="pago_titular" value="<?= htmlspecialchars($titular) ?>" placeholder="Jose Sora">
        </div>
        <div class="form-group">
          <label>Llave / Número</label>
          <input type="text" name="pago_llave" value="<?= htmlspecialchars($llave) ?>" placeholder="@3134326986">
        </div>
        <div class="form-group">
          <label>Banco / App</label>
          <input type="text" name="pago_banco" value="<?= htmlspecialchars($banco) ?>" placeholder="Nequi / Bre-B">
        </div>
        <div class="form-group">
          <label>Instrucciones para el cliente</label>
          <textarea name="pago_instrucciones" placeholder="Pasos para pagar..."><?= htmlspecialchars($instr) ?></textarea>
        </div>
        <button type="submit" class="btn-primary">Guardar datos de pago →</button>
      </div>

      <div class="card">
        <div class="card-title">Código QR</div>
        <?php if ($qr): ?>
          <div class="qr-prev"><img src="../assets/img/<?= htmlspecialchars($qr) ?>" alt="QR de pago"></div>
        <?php else: ?>
          <div class="qr-empty">Aún no has subido un QR.</div>
        <?php endif; ?>
        <div class="form-group" style="margin-top:14px">
          <label>Subir / reemplazar QR</label>
          <input type="file" name="pago_qr" accept="image/*">
        </div>
        <p style="font-size:11px;color:var(--text3)">Imagen JPG/PNG/WEBP, máx 3 MB.</p>
      </div>
    </div>
  </form>
</div>
</body>
</html>
