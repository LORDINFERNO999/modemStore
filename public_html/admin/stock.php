<?php
// admin/stock.php — Stock de cuentas (cuentas precargadas, opcional)
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $email  = trim($_POST['email_cuenta'] ?? '');
        $pass   = trim($_POST['password_cuenta'] ?? '');
        $perfil = trim($_POST['perfil'] ?? '');
        $pin    = trim($_POST['pin'] ?? '');
        if ($planId && $email !== '') {
            $pdo->prepare("INSERT INTO cuentas_stock (plan_id,email_cuenta,password_cuenta,perfil,pin,estado) VALUES (?,?,?,?,?,'disponible')")
                ->execute([$planId, $email, $pass, $perfil ?: null, $pin ?: null]);
            $msg = 'Cuenta agregada al stock ✓';
        } else { $msg = 'Plan y email son obligatorios'; $msgTipo = 'err'; }
    }

    // Editar una cuenta — SOLO se permite si la cuenta está 'disponible'
    if ($accion === 'editar') {
        $id     = (int)($_POST['id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $email  = trim($_POST['email_cuenta'] ?? '');
        $pass   = trim($_POST['password_cuenta'] ?? '');
        $perfil = trim($_POST['perfil'] ?? '');
        $pin    = trim($_POST['pin'] ?? '');

        if ($id && $planId && $email !== '') {
            $st = $pdo->prepare("SELECT estado FROM cuentas_stock WHERE id = ?");
            $st->execute([$id]);
            $estadoActual = $st->fetchColumn();

            if ($estadoActual === false) {
                $msg = 'La cuenta no existe'; $msgTipo = 'err';
            } elseif ($estadoActual !== 'disponible') {
                $msg = 'Solo se pueden editar cuentas disponibles'; $msgTipo = 'err';
            } else {
                $pdo->prepare("UPDATE cuentas_stock SET plan_id=?, email_cuenta=?, password_cuenta=?, perfil=?, pin=? WHERE id=? AND estado='disponible'")
                    ->execute([$planId, $email, $pass ?: null, $perfil ?: null, $pin ?: null, $id]);
                $msg = 'Cuenta actualizada ✓';
            }
        } else {
            $msg = 'Plan y email son obligatorios'; $msgTipo = 'err';
        }
    }

    // Carga masiva: varias cuentas en una sola operación, una por línea
    // Formato por línea: email:password:perfil:pin  (perfil y pin son opcionales)
    if ($accion === 'agregar_bulk') {
        $planId = (int)($_POST['plan_id_bulk'] ?? 0);
        $raw    = (string)($_POST['cuentas_bulk'] ?? '');
        $lineas = preg_split('/\r\n|\r|\n/', $raw);

        $insertados = 0;
        $invalidas  = [];

        if (!$planId) {
            $msg = 'Selecciona un plan para la carga masiva';
            $msgTipo = 'err';
        } else {
            $stmt = $pdo->prepare("INSERT INTO cuentas_stock (plan_id,email_cuenta,password_cuenta,perfil,pin,estado) VALUES (?,?,?,?,?,'disponible')");
            $pdo->beginTransaction();
            foreach ($lineas as $numLinea => $linea) {
                $linea = trim($linea);
                if ($linea === '') continue; // ignora líneas vacías

                // separador : pero permite contraseñas que no tengan ":" dentro
                $partes = array_map('trim', explode(':', $linea));
                $email  = $partes[0] ?? '';
                $pass   = $partes[1] ?? '';
                $perfil = $partes[2] ?? '';
                $pin    = $partes[3] ?? '';

                if ($email === '' || !str_contains($email, '@')) {
                    $invalidas[] = ($numLinea + 1) . ': "' . $linea . '"';
                    continue;
                }

                $stmt->execute([$planId, $email, $pass ?: null, $perfil ?: null, $pin ?: null]);
                $insertados++;
            }
            $pdo->commit();

            if ($insertados > 0) {
                $msg = $insertados . ' cuenta(s) agregada(s) al stock ✓';
                if ($invalidas) {
                    $msg .= ' — ' . count($invalidas) . ' línea(s) inválida(s) (sin email) ignorada(s): ' . implode(' | ', array_slice($invalidas, 0, 5));
                    $msgTipo = 'ok'; // parcial éxito sigue siendo ok, pero avisamos
                }
            } else {
                $msg = 'No se agregó ninguna cuenta. Revisa el formato (email:password:perfil:pin, una por línea).';
                $msgTipo = 'err';
            }
        }
    }

    // Eliminar una sola cuenta — ahora permite borrar tanto disponibles como vendidas
    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM cuentas_stock WHERE id = ?")->execute([$id]);
            $msg = 'Cuenta eliminada del stock ✓';
        } else { $msg = 'Cuenta inválida'; $msgTipo = 'err'; }
    }

    // Eliminar varias cuentas seleccionadas (disponibles y/o vendidas)
    if ($accion === 'eliminar_bulk') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM cuentas_stock WHERE id IN ($ph)")->execute(array_values($ids));
            $msg = count($ids) . ' cuenta(s) eliminada(s) del stock ✓';
        } else { $msg = 'Selecciona al menos una cuenta'; $msgTipo = 'err'; }
    }
}

$planes = $pdo->query("
    SELECT p.id, p.nombre, s.nombre AS servicio_nombre
    FROM planes p JOIN servicios s ON p.servicio_id = s.id
    WHERE p.estado = 'activo' ORDER BY s.nombre, p.nombre
")->fetchAll();

$stock = $pdo->query("
    SELECT cs.*, p.nombre AS plan_nombre, s.nombre AS servicio_nombre, s.imagen AS servicio_imagen
    FROM cuentas_stock cs
    JOIN planes p ON cs.plan_id = p.id
    JOIN servicios s ON p.servicio_id = s.id
    ORDER BY cs.estado = 'disponible' DESC, s.nombre, cs.id DESC
")->fetchAll();

$disponibles = (int)$pdo->query("SELECT COUNT(*) FROM cuentas_stock WHERE estado = 'disponible'")->fetchColumn();
$vendidasCount = (int)$pdo->query("SELECT COUNT(*) FROM cuentas_stock WHERE estado != 'disponible'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Stock de cuentas — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;--border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.14);--accent:#7c6dfa;--accent2:#f472b6;--text:#fff;--text2:#a3a3a3;--text3:#555;--ok:#1db954;--warn:#f59e0b;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-logo{font-size:16px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.nav-links{display:flex;gap:4px;}
.nav-links a{color:var(--text2);font-size:13px;text-decoration:none;padding:7px 14px;border-radius:var(--r);transition:all .2s;font-weight:500;}
.nav-links a:hover,.nav-links a.active{background:var(--s2);color:var(--text);}
.container{max-width:1300px;margin:0 auto;padding:28px 24px 60px;}
h1{font-size:22px;font-weight:800;margin-bottom:4px;letter-spacing:-0.3px;}
.sub{color:var(--text2);font-size:13px;margin-bottom:22px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
.sub-stat{display:inline-flex;align-items:center;gap:6px;}
.sub-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}

/* ── Flash message mejorado ── */
.flash{border-radius:var(--rl);padding:14px 16px;font-size:13px;margin-bottom:20px;font-weight:500;display:flex;align-items:flex-start;gap:11px;animation:flashIn .35s ease;position:relative;}
@keyframes flashIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.flash-icon{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:13px;margin-top:1px;}
.flash-text{flex:1;line-height:1.5;padding-top:2px;}
.flash.ok{background:rgba(29,185,84,.08);border:1px solid rgba(29,185,84,.25);color:var(--ok);}
.flash.ok .flash-icon{background:rgba(29,185,84,.18);}
.flash.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--err);}
.flash.err .flash-icon{background:rgba(239,68,68,.18);}
.flash-close{background:none;border:none;color:inherit;opacity:.55;cursor:pointer;font-size:14px;padding:2px 4px;flex-shrink:0;transition:opacity .15s;}
.flash-close:hover{opacity:1;}

.grid2{display:grid;grid-template-columns:1fr;gap:20px;align-items:start;}
.grid2 > .card:first-child{max-width:560px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rxl);padding:22px;}
.card-title{font-size:14px;font-weight:700;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:rgba(124,109,250,.5);}
.form-group textarea{resize:vertical;min-height:140px;font-family:'JetBrains Mono','Courier New',monospace;font-size:12px;line-height:1.6;}
.form-group select option{background:#1e1e1e;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,var(--accent),#9c8df7);border:none;border-radius:var(--r);color:#fff;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,109,250,.3);}
table{width:100%;border-collapse:collapse;}
th{background:var(--s2);padding:9px 9px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text3);font-weight:700;}
th:first-child{border-radius:var(--r) 0 0 var(--r);}
td{padding:10px 9px;border-top:1px solid var(--border);vertical-align:middle;font-size:12.5px;}
tbody tr{transition:background .15s;}
tbody tr:hover td{background:rgba(255,255,255,.018);}
tr.row-selected td{background:rgba(124,109,250,0.06);}
.srv-img{width:28px;height:28px;object-fit:contain;border-radius:6px;background:var(--s2);padding:3px;flex-shrink:0;}
.badge{padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;}
.b-disp{background:rgba(29,185,84,.14);color:var(--ok);}
.b-vend{background:rgba(120,120,120,.18);color:#aaa;}
.acciones-stock{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
.btn-edit{background:rgba(124,109,250,.15);color:#9c8df7;border:none;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;}
.btn-edit:hover{background:rgba(124,109,250,.28);}
.btn-del{background:rgba(239,68,68,.14);color:var(--err);border:none;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;}
.btn-del:hover{background:rgba(239,68,68,.26);}
.empty{text-align:center;padding:40px 20px;color:var(--text3);}
.note{font-size:12px;color:var(--text3);background:var(--s2);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;margin-bottom:18px;display:flex;gap:8px;align-items:flex-start;}
.row-check{width:15px;height:15px;cursor:pointer;accent-color:var(--accent);flex-shrink:0;}
th .row-check{margin:0;}

/* ── Bulk action bar (eliminar seleccionadas) ── */
.bulk-bar{display:none;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:rgba(124,109,250,0.08);border:1px solid rgba(124,109,250,0.25);border-radius:var(--r);margin-bottom:12px;flex-wrap:wrap;}
.bulk-bar.visible{display:flex;}
.bulk-bar-info{font-size:13px;font-weight:600;color:var(--accent);}
.bulk-bar-actions{display:flex;gap:8px;align-items:center;}
.btn-bulk-act{padding:7px 16px;border-radius:7px;cursor:pointer;font-family:'Inter',sans-serif;font-size:12px;font-weight:700;border:none;transition:all .15s;}
.btn-bulk-act.delete{background:rgba(239,68,68,.15);color:var(--err);border:1px solid rgba(239,68,68,.3);}
.btn-bulk-act.delete:hover{background:rgba(239,68,68,.28);}
.btn-bulk-act.cancel{background:var(--s2);color:var(--text2);border:1px solid var(--border);}
.btn-bulk-act.cancel:hover{color:var(--text);}

/* ── Tabs: Individual / Carga masiva ── */
.tabs{display:flex;gap:6px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);padding:4px;margin-bottom:16px;}
.tab-btn{flex:1;padding:8px 10px;border:none;background:transparent;color:var(--text2);font-family:'Inter',sans-serif;font-size:12.5px;font-weight:700;border-radius:7px;cursor:pointer;transition:all .15s;}
.tab-btn.active{background:linear-gradient(135deg,var(--accent),#9c8df7);color:#fff;}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
.bulk-hint{font-size:11.5px;color:var(--text3);background:var(--s2);border:1px solid var(--border);border-radius:var(--r);padding:9px 11px;margin-bottom:12px;line-height:1.5;}
.bulk-hint code{background:var(--s3);padding:1px 5px;border-radius:4px;color:var(--accent2);font-family:'JetBrains Mono','Courier New',monospace;}
.bulk-count-live{font-size:11px;color:var(--text3);margin-top:6px;text-align:right;}

/* ── Confirm modal (reemplaza confirm() nativo) ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(8px);padding:20px;}
.modal-overlay.open{display:flex;}
.confirm-modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rxl);max-width:380px;width:100%;text-align:center;padding:28px 26px;animation:fadeUp .25s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.confirm-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;}
.confirm-icon.danger{background:rgba(239,68,68,.12);}
.confirm-title{font-size:16px;font-weight:800;margin-bottom:8px;letter-spacing:-0.2px;}
.confirm-text{font-size:13px;color:var(--text2);line-height:1.55;margin-bottom:22px;}
.confirm-actions{display:flex;gap:10px;}
.btn-confirm{flex:1;padding:11px;border-radius:var(--r);border:none;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-confirm-cancel{background:var(--s2);color:var(--text2);border:1px solid var(--border);}
.btn-confirm-cancel:hover{color:var(--text);border-color:var(--border2);}
.btn-confirm-ok.danger{background:linear-gradient(135deg,var(--err),#ff7a7a);color:#fff;}
.btn-confirm-ok.danger:hover{box-shadow:0 8px 20px rgba(239,68,68,.3);transform:translateY(-1px);}

/* ── Edit modal ── */
.edit-modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rxl);max-width:440px;width:100%;padding:26px;position:relative;animation:fadeUp .25s ease;max-height:90vh;overflow-y:auto;}
.edit-close{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;background:var(--s2);border:none;color:var(--text2);cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;}
.edit-close:hover{background:var(--s3);color:var(--text);}
.edit-title{font-size:17px;font-weight:800;margin-bottom:4px;letter-spacing:-0.2px;}
.edit-sub{font-size:12px;color:var(--text3);margin-bottom:18px;}

/* ── Mobile cards ── */
.mobile-cards{display:none;}
.m-select-all{display:none;align-items:center;gap:9px;padding:11px 14px;background:var(--s2);border:1px solid var(--border);border-radius:var(--r);margin-bottom:12px;font-size:13px;font-weight:600;color:var(--text2);cursor:pointer;}
.m-select-all input{width:16px;height:16px;accent-color:var(--accent);}
.m-stock-card{background:var(--s2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;gap:10px;align-items:flex-start;}
.m-stock-card.row-selected{border-color:rgba(124,109,250,.4);background:rgba(124,109,250,0.05);}
.m-stock-info{flex:1;min-width:0;}
.m-stock-top{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.m-stock-srv{font-weight:700;font-size:13px;}
.m-stock-plan{font-size:11px;color:var(--text3);}
.m-stock-row{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--text2);margin-top:3px;}
.m-stock-row b{color:var(--text);font-weight:600;word-break:break-all;text-align:right;}
.m-stock-actions{display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:8px;}

@media(max-width:1300px){.grid2{grid-template-columns:1fr;}}
/* Pantallas medianas/tablet: la tabla de 8 columnas no cabe -> tarjetas con Editar/Eliminar siempre visibles */
@media(max-width:900px){
  table{display:none;}
  .mobile-cards{display:flex;flex-direction:column;gap:10px;}
  .m-select-all{display:flex;}
  .bulk-bar{flex-direction:column;gap:8px;text-align:center;}
  .bulk-bar-actions{flex-wrap:wrap;justify-content:center;width:100%;}
  .btn-bulk-act{flex:1;}
}
@media(max-width:680px){
  nav{padding:0 12px;height:50px;}.nav-links{display:none;}
  .container{padding:16px 12px 60px;}
  h1{font-size:19px;}
  .card{padding:16px;border-radius:14px;}
  .form-row{grid-template-columns:1fr;}
  table{display:none;}
  .mobile-cards{display:flex;flex-direction:column;gap:10px;}
  .m-select-all{display:flex;}
  .bulk-bar{flex-direction:column;gap:8px;text-align:center;}
  .bulk-bar-actions{flex-wrap:wrap;justify-content:center;width:100%;}
  .btn-bulk-act{flex:1;}
  .confirm-modal{padding:22px 18px;}
  .edit-modal{padding:22px 18px;}
}
</style>
</head>
<body>

<nav>
  <div class="nav-logo">⚙️ Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php">Servicios &amp; Planes</a>
    <a href="stock.php" class="active">Stock</a>
    <a href="pedidos.php">Pedidos</a>
    <a href="usuarios.php">Usuarios</a>
    <a href="pago.php">Datos de pago</a>
    <a href="../logout.php" style="margin-left:auto;color:#ef4444">Salir</a>
  </div>
</nav>

<!-- Confirm modal -->
<div class="modal-overlay" id="modalConfirmar">
  <div class="confirm-modal">
    <div class="confirm-icon danger">&#x1F5D1;&#xFE0F;</div>
    <div class="confirm-title" id="confirmTitle">Eliminar cuenta</div>
    <p class="confirm-text" id="confirmText"></p>
    <div class="confirm-actions">
      <button type="button" class="btn-confirm btn-confirm-cancel" id="confirmBtnCancel">Cancelar</button>
      <button type="button" class="btn-confirm btn-confirm-ok danger" id="confirmBtnOk">Eliminar</button>
    </div>
  </div>
</div>

<!-- Edit modal -->
<div class="modal-overlay" id="modalEditar">
  <div class="edit-modal">
    <button type="button" class="edit-close" onclick="cerrarEditar()">&#x2715;</button>
    <div class="edit-title">Editar cuenta</div>
    <div class="edit-sub" id="editSub"></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="ed_id">
      <div class="form-group">
        <label>Plan</label>
        <select name="plan_id" id="ed_plan" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($planes as $pl): ?>
          <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['servicio_nombre'].' — '.$pl['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Usuario / Email de la cuenta</label>
        <input type="text" name="email_cuenta" id="ed_email" required autocomplete="off">
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="text" name="password_cuenta" id="ed_pass" autocomplete="off">
      </div>
      <div class="form-row">
        <div class="form-group"><label>Perfil (opcional)</label><input type="text" name="perfil" id="ed_perfil"></div>
        <div class="form-group"><label>PIN (opcional)</label><input type="text" name="pin" id="ed_pin"></div>
      </div>
      <button type="submit" class="btn-primary">Guardar cambios →</button>
    </form>
  </div>
</div>

<div class="container">
  <h1>Stock de cuentas</h1>
  <p class="sub">
    <span class="sub-stat"><span class="sub-dot" style="background:var(--ok)"></span><?= $disponibles ?> disponible<?= $disponibles != 1 ? 's' : '' ?></span>
    <span class="sub-stat"><span class="sub-dot" style="background:#777"></span><?= $vendidasCount ?> vendida<?= $vendidasCount != 1 ? 's' : '' ?></span>
  </p>

  <?php if ($msg): ?>
  <div class="flash <?= $msgTipo ?>" id="flashMsg">
    <span class="flash-icon"><?= $msgTipo === 'ok' ? '&#x2713;' : '&#x26A0;' ?></span>
    <span class="flash-text"><?= htmlspecialchars($msg) ?></span>
    <button type="button" class="flash-close" onclick="document.getElementById('flashMsg').remove()">&#x2715;</button>
  </div>
  <?php endif; ?>

  <div class="note">&#x2139;&#xFE0F; <span>Agrega cuentas al stock. Cuando un usuario compra un plan con saldo, se le asigna automáticamente una cuenta disponible. Puedes editar las cuentas <b>disponibles</b>, y eliminar cuentas disponibles o ya vendidas/vencidas, una por una o en bloque.</span></div>

  <div class="grid2">
    <div class="card">
      <div class="tabs">
        <button type="button" class="tab-btn active" id="tabBtnIndividual" onclick="switchTab('individual')">Individual</button>
        <button type="button" class="tab-btn" id="tabBtnMasiva" onclick="switchTab('masiva')">Carga masiva</button>
      </div>

      <!-- Tab: agregar una cuenta -->
      <div class="tab-panel active" id="tabIndividual">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="accion" value="agregar">
          <div class="form-group">
            <label>Plan</label>
            <select name="plan_id" required>
              <option value="">— Seleccionar —</option>
              <?php foreach ($planes as $pl): ?>
              <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['servicio_nombre'].' — '.$pl['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Usuario / Email de la cuenta</label>
            <input type="text" name="email_cuenta" required placeholder="cuenta@correo.com" autocomplete="off">
          </div>
          <div class="form-group">
            <label>Contraseña</label>
            <input type="text" name="password_cuenta" placeholder="••••••••" autocomplete="off">
          </div>
          <div class="form-row">
            <div class="form-group"><label>Perfil (opcional)</label><input type="text" name="perfil" placeholder="Perfil 1"></div>
            <div class="form-group"><label>PIN (opcional)</label><input type="text" name="pin" placeholder="1234"></div>
          </div>
          <button type="submit" class="btn-primary">Agregar al stock →</button>
        </form>
      </div>

      <!-- Tab: carga masiva -->
      <div class="tab-panel" id="tabMasiva">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="accion" value="agregar_bulk">
          <div class="form-group">
            <label>Plan (aplica a todas las cuentas de la lista)</label>
            <select name="plan_id_bulk" required>
              <option value="">— Seleccionar —</option>
              <?php foreach ($planes as $pl): ?>
              <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['servicio_nombre'].' — '.$pl['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="bulk-hint">
            Una cuenta por línea, formato: <code>email:password:perfil:pin</code><br>
            Perfil y PIN son opcionales — puedes dejarlos vacíos: <code>correo@gmail.com:clave123</code>
          </div>
          <div class="form-group">
            <label>Cuentas</label>
            <textarea name="cuentas_bulk" id="cuentasBulkTextarea" placeholder="correo1@gmail.com:clave123
correo2@gmail.com:clave456:Perfil 2:9090
correo3@gmail.com:clave789" oninput="updateBulkLiveCount()"></textarea>
            <div class="bulk-count-live" id="bulkLiveCount">0 línea(s) detectada(s)</div>
          </div>
          <button type="submit" class="btn-primary">Agregar todas al stock →</button>
        </form>
      </div>
    </div>

    <div class="card" style="overflow-x:auto">
      <div class="card-title">
        <span>Cuentas en stock</span>
      </div>

      <!-- Bulk bar -->
      <div class="bulk-bar" id="bulkBar">
        <span class="bulk-bar-info">&#x2713; <span id="bulkCount">0</span> cuenta(s) seleccionada(s)</span>
        <div class="bulk-bar-actions">
          <form method="POST" id="formBulkDel" style="display:contents">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="eliminar_bulk">
            <div id="bulkHiddenIds"></div>
            <button type="button" class="btn-bulk-act delete" onclick="confirmBulkDelete()">&#x1F5D1;&#xFE0F; Eliminar seleccionadas</button>
            <button type="button" class="btn-bulk-act cancel" onclick="clearSel()">Cancelar</button>
          </form>
        </div>
      </div>

      <?php if (empty($stock)): ?>
        <div class="empty">No hay cuentas en stock.</div>
      <?php else: ?>

      <!-- Seleccionar todo (solo visible en celular) -->
      <label class="m-select-all">
        <input type="checkbox" id="checkAllMobile" onchange="toggleAll(this)">
        Seleccionar todas las cuentas
      </label>

      <table>
        <thead><tr>
          <th style="width:32px"><input type="checkbox" class="row-check" id="checkAll" onchange="toggleAll(this)"></th>
          <th>Servicio / Plan</th><th>Usuario</th><th>Contraseña</th><th>Perfil</th><th>PIN</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody id="tbodyStock">
          <?php foreach ($stock as $c):
            $cJson = json_encode([
              'id' => (int)$c['id'],
              'plan_id' => (int)$c['plan_id'],
              'email_cuenta' => $c['email_cuenta'],
              'password_cuenta' => $c['password_cuenta'],
              'perfil' => $c['perfil'],
              'pin' => $c['pin'],
              'servicio_nombre' => $c['servicio_nombre'],
              'plan_nombre' => $c['plan_nombre'],
            ], JSON_HEX_APOS|JSON_HEX_QUOT);
          ?>
          <tr id="stock-row-<?= $c['id'] ?>">
            <td><input type="checkbox" class="row-check stock-check" value="<?= $c['id'] ?>" onchange="updateBulk()"></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <img class="srv-img" src="../assets/img/<?= htmlspecialchars($c['servicio_imagen'] ?? '') ?>" onerror="this.style.opacity='.15'">
                <div><?= htmlspecialchars($c['servicio_nombre']) ?><br><small style="color:var(--text3)"><?= htmlspecialchars($c['plan_nombre']) ?></small></div>
              </div>
            </td>
            <td><?= htmlspecialchars($c['email_cuenta']) ?></td>
            <td><?= htmlspecialchars($c['password_cuenta'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['perfil'] ?: '—') ?></td>
            <td><?= htmlspecialchars($c['pin'] ?: '—') ?></td>
            <td><span class="badge <?= $c['estado'] === 'disponible' ? 'b-disp' : 'b-vend' ?>"><?= $c['estado'] ?></span></td>
            <td>
              <div class="acciones-stock">
                <?php if ($c['estado'] === 'disponible'): ?>
                <button type="button" class="btn-edit" onclick='abrirEditar(<?= $cJson ?>)'>Editar</button>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar la cuenta <?= htmlspecialchars(addslashes($c['email_cuenta'])) ?> del stock? Esta acción no se puede deshacer.')" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn-del">Eliminar</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Mobile cards -->
      <div class="mobile-cards">
        <?php foreach ($stock as $c):
          $cJsonM = json_encode([
            'id' => (int)$c['id'],
            'plan_id' => (int)$c['plan_id'],
            'email_cuenta' => $c['email_cuenta'],
            'password_cuenta' => $c['password_cuenta'],
            'perfil' => $c['perfil'],
            'pin' => $c['pin'],
            'servicio_nombre' => $c['servicio_nombre'],
            'plan_nombre' => $c['plan_nombre'],
          ], JSON_HEX_APOS|JSON_HEX_QUOT);
        ?>
        <div class="m-stock-card" id="stock-card-<?= $c['id'] ?>">
          <input type="checkbox" class="row-check stock-check-m" value="<?= $c['id'] ?>" onchange="updateBulk()" style="margin-top:3px">
          <img class="srv-img" style="width:34px;height:34px" src="../assets/img/<?= htmlspecialchars($c['servicio_imagen'] ?? '') ?>" onerror="this.style.opacity='.15'">
          <div class="m-stock-info">
            <div class="m-stock-top">
              <span class="m-stock-srv"><?= htmlspecialchars($c['servicio_nombre']) ?></span>
              <span class="badge <?= $c['estado'] === 'disponible' ? 'b-disp' : 'b-vend' ?>" style="margin-left:auto"><?= $c['estado'] ?></span>
            </div>
            <div class="m-stock-plan"><?= htmlspecialchars($c['plan_nombre']) ?></div>
            <div class="m-stock-row"><span>Usuario</span><b><?= htmlspecialchars($c['email_cuenta']) ?></b></div>
            <div class="m-stock-row"><span>Contraseña</span><b><?= htmlspecialchars($c['password_cuenta'] ?? '—') ?></b></div>
            <div class="m-stock-row"><span>Perfil</span><b><?= htmlspecialchars($c['perfil'] ?: '—') ?></b></div>
            <div class="m-stock-row"><span>PIN</span><b><?= htmlspecialchars($c['pin'] ?: '—') ?></b></div>
            <div class="m-stock-actions">
              <?php if ($c['estado'] === 'disponible'): ?>
              <button type="button" class="btn-edit" onclick='abrirEditar(<?= $cJsonM ?>)'>&#x270F;&#xFE0F; Editar</button>
              <?php else: ?><span></span><?php endif; ?>
              <form method="POST" onsubmit="return confirmarAccion(this,'¿Eliminar la cuenta <?= htmlspecialchars(addslashes($c['email_cuenta'])) ?> del stock?')">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn-del">&#x1F5D1;&#xFE0F; Eliminar</button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>


<script>
// ── Tabs Individual / Carga masiva ──
function switchTab(tab){
  document.getElementById('tabIndividual').classList.toggle('active', tab === 'individual');
  document.getElementById('tabMasiva').classList.toggle('active', tab === 'masiva');
  document.getElementById('tabBtnIndividual').classList.toggle('active', tab === 'individual');
  document.getElementById('tabBtnMasiva').classList.toggle('active', tab === 'masiva');
}
function updateBulkLiveCount(){
  const ta = document.getElementById('cuentasBulkTextarea');
  const lineas = ta.value.split(/\r\n|\r|\n/).map(l => l.trim()).filter(l => l !== '');
  document.getElementById('bulkLiveCount').textContent = lineas.length + ' línea(s) detectada(s)';
}

// ── Editar cuenta (solo disponibles) ──
function abrirEditar(c){
  document.getElementById('ed_id').value     = c.id;
  document.getElementById('ed_plan').value   = c.plan_id;
  document.getElementById('ed_email').value  = c.email_cuenta || '';
  document.getElementById('ed_pass').value   = c.password_cuenta || '';
  document.getElementById('ed_perfil').value = c.perfil || '';
  document.getElementById('ed_pin').value    = c.pin || '';
  document.getElementById('editSub').textContent = (c.servicio_nombre || '') + ' — ' + (c.plan_nombre || '');
  document.getElementById('modalEditar').classList.add('open');
}
function cerrarEditar(){ document.getElementById('modalEditar').classList.remove('open'); }
document.getElementById('modalEditar').addEventListener('click', function(e){ if (e.target === this) cerrarEditar(); });

// ── Confirm modal genérico (reemplaza confirm() nativo) ──
let pendingConfirmForm = null;
let pendingConfirmCallback = null;
function confirmarAccion(form, mensaje){
  pendingConfirmForm = form;
  pendingConfirmCallback = null;
  document.getElementById('confirmText').textContent = mensaje;
  document.getElementById('confirmTitle').textContent = 'Eliminar cuenta';
  document.getElementById('modalConfirmar').classList.add('open');
  return false; // bloquea el submit nativo hasta confirmar
}
function pedirConfirmacion(mensaje, titulo, callback){
  pendingConfirmForm = null;
  pendingConfirmCallback = callback;
  document.getElementById('confirmText').textContent = mensaje;
  document.getElementById('confirmTitle').textContent = titulo;
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
document.getElementById('modalConfirmar').addEventListener('click', function(e){
  if (e.target === this) this.classList.remove('open');
});

// ── Selección múltiple ──
function getChecked() {
  return [...document.querySelectorAll('.stock-check:checked, .stock-check-m:checked')]
    .map(c => c.value).filter((v,i,a) => a.indexOf(v) === i);
}
function updateBulk() {
  // sincroniza checkboxes desktop <-> mobile por valor
  document.querySelectorAll('.stock-check').forEach(c => {
    const mCb = document.querySelector(`.stock-check-m[value="${c.value}"]`);
    if (mCb && mCb.checked !== c.checked) mCb.checked = c.checked;
  });
  document.querySelectorAll('.stock-check-m').forEach(c => {
    const dCb = document.querySelector(`.stock-check[value="${c.value}"]`);
    if (dCb && dCb.checked !== c.checked) dCb.checked = c.checked;
  });

  const ids = getChecked();
  const bar = document.getElementById('bulkBar');
  document.getElementById('bulkCount').textContent = ids.length;
  bar.classList.toggle('visible', ids.length > 0);

  document.querySelectorAll('#tbodyStock tr').forEach(tr => {
    const cb = tr.querySelector('.stock-check');
    if (cb) tr.classList.toggle('row-selected', cb.checked);
  });
  document.querySelectorAll('.m-stock-card').forEach(card => {
    const cb = card.querySelector('.stock-check-m');
    if (cb) card.classList.toggle('row-selected', cb.checked);
  });

  // sincroniza los "seleccionar todo" (desktop y mobile)
  const total = document.querySelectorAll('.stock-check').length;
  const allChecked = total > 0 && ids.length === total;
  const ca = document.getElementById('checkAll'); if (ca) ca.checked = allChecked;
  const cam = document.getElementById('checkAllMobile'); if (cam) cam.checked = allChecked;
}
function toggleAll(master) {
  document.querySelectorAll('.stock-check, .stock-check-m').forEach(c => c.checked = master.checked);
  updateBulk();
}
function clearSel() {
  document.querySelectorAll('.stock-check, .stock-check-m').forEach(c => c.checked = false);
  const ca = document.getElementById('checkAll'); if (ca) ca.checked = false;
  const cam = document.getElementById('checkAllMobile'); if (cam) cam.checked = false;
  updateBulk();
}
function confirmBulkDelete() {
  const ids = getChecked();
  if (!ids.length) return;
  pedirConfirmacion(
    `¿Eliminar ${ids.length} cuenta(s) del stock? Esta acción no se puede deshacer.`,
    'Eliminar cuentas',
    () => {
      const container = document.getElementById('bulkHiddenIds');
      container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
      document.getElementById('formBulkDel').submit();
    }
  );
}
</script>
</body>
</html>