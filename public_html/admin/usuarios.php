<?php
// admin/usuarios.php
require_once '../includes/auth.php';
require_once '../includes/seguridad.php';
requireAdmin();

$msg = ''; $msgTipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire(); // protege todas las acciones de esta página
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_usuario') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email']  ?? '');
        $pass   = $_POST['password'] ?? '';
        $rol    = $_POST['rol'] ?? 'cliente';
        $estado = $_POST['estado'] ?? 'activo';
        $saldo  = (float) preg_replace('/[^0-9]/', '', (string)($_POST['saldo'] ?? '0'));
        $esRevendedor = (($_POST['es_revendedor'] ?? '0') === '1') ? 1 : 0;

        if (!$nombre || !$email || !$pass) {
            $msg = 'Nombre, email y contraseña son obligatorios'; $msgTipo = 'err';
        } else {
            $existe = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $existe->execute([$email]);
            if ($existe->fetch()) {
                $msg = 'Ya existe un usuario con ese email'; $msgTipo = 'err';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO usuarios (nombre,email,password,rol,estado,saldo,es_revendedor) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$nombre,$email,$hash,$rol,$estado,$saldo,$esRevendedor]);
                $msg = "Usuario '$nombre' creado ✓";
            }
        }
    }

    if ($accion === 'editar_usuario') {
        $id     = (int)$_POST['id'];
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email']  ?? '');
        $rol    = $_POST['rol']    ?? 'cliente';
        $estado = $_POST['estado'] ?? 'activo';
        $saldo  = (float) preg_replace('/[^0-9]/', '', (string)($_POST['saldo'] ?? '0'));
        $pass   = $_POST['password'] ?? '';
        $esRevendedor = (($_POST['es_revendedor'] ?? '0') === '1') ? 1 : 0;

        if ($pass) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET nombre=?,email=?,password=?,rol=?,estado=?,saldo=?,es_revendedor=? WHERE id=?")
                ->execute([$nombre,$email,$hash,$rol,$estado,$saldo,$esRevendedor, $id]);
        } else {
            $pdo->prepare("UPDATE usuarios SET nombre=?,email=?,rol=?,estado=?,saldo=?,es_revendedor=? WHERE id=?")
                ->execute([$nombre,$email,$rol,$estado,$saldo,$esRevendedor, $id]);
        }
        $msg = "Usuario actualizado ✓";
    }

    if ($accion === 'cambiar_estado') {
        $id     = (int)$_POST['id'];
        $estado = $_POST['estado_nuevo'] ?? 'activo';
        $pdo->prepare("UPDATE usuarios SET estado=? WHERE id=?")->execute([$estado,$id]);
        $msg = "Estado actualizado ✓";
    }

    if ($accion === 'eliminar_usuario') {
        $id = (int)$_POST['id'];
        // No borramos, solo desactivamos
        $pdo->prepare("UPDATE usuarios SET estado='inactivo' WHERE id=? AND rol != 'admin'")->execute([$id]);
        $msg = "Usuario desactivado";
    }

    // Borrado PERMANENTE del usuario (y sus registros asociados)
    if ($accion === 'borrar_usuario') {
        $id = (int)$_POST['id'];
        $chk = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $chk->execute([$id]);
        $rolU = $chk->fetchColumn();

        if ($rolU === false) {
            $msg = 'El usuario no existe'; $msgTipo = 'err';
        } elseif ($rolU === 'admin') {
            $msg = 'No se puede eliminar un administrador'; $msgTipo = 'err';
        } else {
            // Borrar primero los registros asociados (evita errores de clave foránea).
            // Cada uno va protegido: si una tabla/columna no aplica, se ignora sin romper.
            $asociados = [
                "DELETE FROM pedidos WHERE usuario_id = ?",
                "DELETE FROM recargas WHERE usuario_id = ?",
                "DELETE FROM movimientos_saldo WHERE usuario_id = ?",
                "DELETE FROM notificaciones WHERE usuario_id = ?",
            ];
            foreach ($asociados as $q) {
                try { $pdo->prepare($q)->execute([$id]); } catch (Exception $e) { /* tabla/columna no aplica */ }
            }
            try {
                $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol != 'admin'")->execute([$id]);
                $msg = 'Usuario eliminado permanentemente ✓';
            } catch (Exception $e) {
                $msg = 'No se pudo eliminar (tiene registros asociados). Usa Desactivar en su lugar.'; $msgTipo = 'err';
            }
        }
    }

    if ($accion === 'resetear_password') {
        $id   = (int)$_POST['id'];
        $pass = $_POST['password'] ?? '';
        if ($id && strlen($pass) >= 6) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([$hash, $id]);
            $msg = "Contraseña reseteada ✓";
        } else {
            $msg = 'La nueva contraseña debe tener al menos 6 caracteres'; $msgTipo = 'err';
        }
    }
}

// Filtros
$busqueda = trim($_GET['q'] ?? '');
$filtroRol    = $_GET['rol']    ?? '';
$filtroEstado = $_GET['estado'] ?? '';

$where = []; $params = [];
if ($busqueda) {
    $where[] = "(nombre LIKE ? OR email LIKE ?)";
    $params[] = "%$busqueda%"; $params[] = "%$busqueda%";
}
if ($filtroRol)    { $where[] = "rol = ?";    $params[] = $filtroRol; }
if ($filtroEstado) { $where[] = "estado = ?"; $params[] = $filtroEstado; }

$sql = "SELECT * FROM usuarios" . ($where ? " WHERE " . implode(" AND ",$where) : "") . " ORDER BY created_at DESC";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$usuarios = $stm->fetchAll();

// Estadísticas rápidas
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(rol='admin') as admins,
        SUM(rol='cliente') as clientes,
        SUM(estado='activo') as activos,
        SUM(estado='inactivo') as inactivos,
        COALESCE(SUM(saldo),0) as saldo_total
    FROM usuarios
")->fetch();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Usuarios — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0d0d0d;--surface:#161616;--s2:#1e1e1e;--s3:#262626;
  --border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);
  --accent:#7c6dfa;--text:#ffffff;--text2:#a3a3a3;--text3:#555555;
  --ok:#1db954;--warn:#f59e0b;--err:#ef4444;--r:10px;--rl:16px;--rxl:20px;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14px; -webkit-font-smoothing:antialiased; }
nav { background:var(--surface); border-bottom:1px solid var(--border); padding:0 28px; height:58px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; }
.nav-logo { font-size:16px; font-weight:800; background:linear-gradient(135deg,#7c6dfa,#f472b6); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.nav-links { display:flex; gap:4px; flex-wrap:wrap; }
.nav-links a { color:var(--text2); font-size:13px; text-decoration:none; padding:7px 14px; border-radius:var(--r); transition:all .2s; font-weight:500; }
.nav-links a:hover, .nav-links a.active { background:var(--s2); color:var(--text); }
.container { max-width:1400px; margin:0 auto; padding:28px 24px 60px; }
.flash { border-radius:var(--r); padding:12px 16px; font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:8px; font-weight:500; }
.flash.ok  { background:rgba(29,185,84,0.08);  border:1px solid rgba(29,185,84,0.25);  color:var(--ok); }
.flash.err { background:rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.25);  color:var(--err); }
.stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-bottom:28px; }
.stat-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--rl); padding:18px 20px; position:relative; overflow:hidden; }
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:var(--lc,#333); }
.stat-label { font-size:11px; color:var(--text3); text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; font-weight:600; }
.stat-value { font-size:28px; font-weight:800; letter-spacing:-1px; }
.stat-value.small { font-size:22px; }
.toolbar { display:flex; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.search-box { display:flex; align-items:center; gap:8px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:9px 14px; flex:1; min-width:200px; max-width:340px; }
.search-box svg { width:15px; height:15px; color:var(--text3); flex-shrink:0; }
.search-box input { background:none; border:none; outline:none; color:var(--text); font-family:'Inter',sans-serif; font-size:13px; width:100%; }
.search-box input::placeholder { color:var(--text3); }
.filter-select { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:9px 12px; color:var(--text2); font-family:'Inter',sans-serif; font-size:13px; outline:none; cursor:pointer; transition:border-color .2s; }
.filter-select:focus { border-color:rgba(255,255,255,0.2); }
.filter-select option { background:#1e1e1e; }
.btn-new { padding:9px 20px; background:linear-gradient(135deg,var(--accent),#9c8df7); border:none; border-radius:var(--r); color:#fff; font-family:'Inter',sans-serif; font-size:13px; font-weight:700; cursor:pointer; transition:all .2s; white-space:nowrap; margin-left:auto; }
.btn-new:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(124,109,250,.3); }
.table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--rxl); overflow:hidden; }
.table-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.table-title { font-size:14px; font-weight:700; }
.table-count { font-size:12px; color:var(--text3); }
table { width:100%; border-collapse:collapse; }
th { background:var(--s2); padding:10px 16px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.8px; color:var(--text3); font-weight:700; }
td { padding:13px 16px; border-top:1px solid var(--border); vertical-align:middle; }
tr:hover td { background:rgba(255,255,255,.015); }
.avatar-cell { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; color:#fff; flex-shrink:0; }
.user-cell { display:flex; align-items:center; gap:10px; }
.user-name { font-weight:600; font-size:13px; }
.user-email { font-size:11px; color:var(--text2); margin-top:1px; overflow-wrap:anywhere; }
.badge { padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.badge-admin  { background:rgba(245,158,11,.12); color:var(--warn); }
.badge-cliente{ background:rgba(124,109,250,.12); color:#9c8df7; }
.badge-revendedor{ background:rgba(245,158,11,.12); color:#f59e0b; }
.badge-ok     { background:rgba(29,185,84,.12);  color:var(--ok); }
.badge-no     { background:rgba(239,68,68,.12);  color:var(--err); }
.saldo-cell { font-weight:800; font-size:13px; letter-spacing:-0.3px; color:var(--text3); }
.saldo-cell.has-saldo { color:var(--ok); }
.btn-sm { padding:5px 11px; border-radius:6px; cursor:pointer; font-family:'Inter',sans-serif; font-size:11px; font-weight:700; border:none; transition:all .15s; }
.btn-edit   { background:rgba(124,109,250,.15); color:#9c8df7; }
.btn-edit:hover   { background:rgba(124,109,250,.28); }
.btn-saldo  { background:rgba(29,185,84,.15);   color:var(--ok); }
.btn-saldo:hover  { background:rgba(29,185,84,.28); }
.btn-del    { background:rgba(239,68,68,.15);   color:var(--err); }
.btn-del:hover    { background:rgba(239,68,68,.28); }
.btn-deact  { background:rgba(245,158,11,.15);  color:var(--warn); }
.btn-deact:hover  { background:rgba(245,158,11,.28); }
.btn-borrar { background:rgba(239,68,68,.9);    color:#fff; }
.btn-borrar:hover { background:rgba(239,68,68,1); }
.overlay { position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(10px); }
.overlay.open { display:flex; }
.modal { background:var(--surface); border:1px solid var(--border2); border-radius:var(--rxl); padding:28px; width:100%; max-width:460px; max-height:90vh; overflow-y:auto; position:relative; animation:fadeUp .25s ease; }
@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
.modal-close { position:absolute; top:14px; right:14px; width:28px; height:28px; border-radius:50%; background:var(--s2); border:none; color:var(--text2); cursor:pointer; font-size:15px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.modal-close:hover { background:var(--s3); color:var(--text); }
.modal-title { font-size:17px; font-weight:800; margin-bottom:20px; letter-spacing:-0.3px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11px; color:var(--text3); margin-bottom:5px; text-transform:uppercase; letter-spacing:.5px; font-weight:600; }
.form-group input, .form-group select { width:100%; padding:10px 12px; background:var(--s2); border:1px solid var(--border); border-radius:var(--r); color:var(--text); font-family:'Inter',sans-serif; font-size:13px; outline:none; transition:border-color .2s; }
.form-group input:focus, .form-group select:focus { border-color:rgba(255,255,255,.25); }
.form-group select.select-revendedor { border-color:rgba(245,158,11,.5); background:rgba(245,158,11,.08); color:#f59e0b; }
.form-group select option { background:#1e1e1e; }
.form-group input.input-saldo { border-color:rgba(29,185,84,.4); background:rgba(29,185,84,.06); color:var(--ok); font-weight:700; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-hint { font-size:11px; color:var(--text3); margin-top:4px; }
.btn-primary { width:100%; padding:12px; background:linear-gradient(135deg,var(--accent),#9c8df7); border:none; border-radius:var(--r); color:#fff; font-family:'Inter',sans-serif; font-size:14px; font-weight:700; cursor:pointer; transition:all .2s; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(124,109,250,.3); }
.empty { text-align:center; padding:60px 20px; color:var(--text3); }
.empty svg { width:44px; height:44px; margin-bottom:14px; opacity:.25; display:block; margin-left:auto; margin-right:auto; }

@media(max-width:900px) { .stats-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px) {
  .container { padding:20px 14px 50px; }
  .stats-grid { grid-template-columns:1fr 1fr; }
  .form-row { grid-template-columns:1fr; }
  nav { padding:0 16px; }

  /* Tabla -> tarjetas en movil (sin scroll horizontal, sin ocultar datos) */
  .table-wrap > div[style*="overflow-x"]{overflow-x:visible !important;}
  table thead { display:none; }
  table, table tbody, table tr, table td { display:block; width:100%; }
  table tr { border-top:none; border-bottom:1px solid var(--border); padding:14px 16px; }
  table tr:last-child { border-bottom:none; }
  table td { border-top:none; padding:6px 0; display:flex; justify-content:space-between; align-items:center; gap:12px; text-align:right; }
  table td::before { content:attr(data-label); font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text3); font-weight:700; flex-shrink:0; text-align:left; }
  /* La celda de usuario ocupa toda la fila, sin etiqueta */
  table td[data-label="Usuario"]{ padding-bottom:12px; margin-bottom:6px; border-bottom:1px solid var(--border); text-align:left; }
  table td[data-label="Usuario"]::before { display:none; }
  table td[data-label="Usuario"] .user-cell { gap:12px; align-items:center; width:100%; }
  table td[data-label="Usuario"] .user-cell > div:last-child { min-width:0; flex:1; }
  table td[data-label="Usuario"] .avatar-cell { width:42px; height:42px; font-size:15px; }
  table td[data-label="Usuario"] .user-name { font-size:15px; font-weight:700; }
  table td[data-label="Usuario"] .user-email { font-size:12px; margin-top:2px; color:var(--text2); }
  table td[data-label="Acciones"]{ flex-direction:column; align-items:stretch; }
  table td[data-label="Acciones"]::before { margin-bottom:4px; }
  table td[data-label="Acciones"] > div { justify-content:flex-end; }
}
</style>
</head>
<body>

<nav>
  <div class="nav-logo">⚙️ Admin Panel</div>
  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="servicios.php">Servicios & Planes</a>
    <a href="usuarios.php" class="active">Usuarios</a>
    <a href="../dashboard.php">← Ver tienda</a>
    <a href="../logout.php" style="margin-left:auto;color:#ef4444">Salir</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg): ?>
  <div class="flash <?= $msgTipo ?>">
    <?= $msgTipo==='ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card" style="--lc:#7c6dfa">
      <div class="stat-label">Total usuarios</div>
      <div class="stat-value"><?= $stats['total'] ?></div>
    </div>
    <div class="stat-card" style="--lc:var(--ok)">
      <div class="stat-label">Activos</div>
      <div class="stat-value" style="color:var(--ok)"><?= $stats['activos'] ?></div>
    </div>
    <div class="stat-card" style="--lc:var(--err)">
      <div class="stat-label">Inactivos</div>
      <div class="stat-value" style="color:var(--err)"><?= $stats['inactivos'] ?></div>
    </div>
    <div class="stat-card" style="--lc:var(--warn)">
      <div class="stat-label">Admins</div>
      <div class="stat-value" style="color:var(--warn)"><?= $stats['admins'] ?></div>
    </div>
    <div class="stat-card" style="--lc:var(--ok)">
      <div class="stat-label">Saldo total</div>
      <div class="stat-value small" style="color:var(--ok)">$<?= number_format((float)$stats['saldo_total'], 0, ',', '.') ?></div>
    </div>
  </div>

  <!-- TOOLBAR -->
  <form method="GET" id="filterForm">
    <div class="toolbar">
      <div class="search-box">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="userSearch" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre o email…" oninput="filtrarUsuarios()" onkeydown="if(event.key==='Enter'){event.preventDefault()}" autocomplete="off">
      </div>
      <select name="rol" class="filter-select" onchange="this.form.submit()">
        <option value="">Todos los roles</option>
        <option value="admin"   <?= $filtroRol==='admin'   ?'selected':'' ?>>Admin</option>
        <option value="cliente" <?= $filtroRol==='cliente' ?'selected':'' ?>>Cliente</option>
      </select>
      <select name="estado" class="filter-select" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <option value="activo"   <?= $filtroEstado==='activo'   ?'selected':'' ?>>Activo</option>
        <option value="inactivo" <?= $filtroEstado==='inactivo' ?'selected':'' ?>>Inactivo</option>
      </select>
      <button type="button" class="btn-new" onclick="abrirCrear()">+ Nuevo usuario</button>
    </div>
  </form>

  <!-- TABLE -->
  <div class="table-wrap">
    <div class="table-head">
      <div class="table-title">Usuarios registrados</div>
      <div class="table-count" id="tableCount"><?= count($usuarios) ?> resultado<?= count($usuarios)!=1?'s':'' ?></div>
    </div>
    <?php if (empty($usuarios)): ?>
    <div class="empty">
      <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      <p>No se encontraron usuarios</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>Usuario</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Saldo</th>
          <th>Registro</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="usuariosBody">
        <?php foreach ($usuarios as $u):
          $colors = ['#7c6dfa','#f472b6','#1db954','#f59e0b','#ef4444','#06b6d4','#8b5cf6'];
          $avatarColor = $colors[ord($u['nombre'][0]) % count($colors)];
        ?>
        <tr data-search="<?= htmlspecialchars(strtolower($u['nombre'].' '.$u['email']), ENT_QUOTES) ?>">
          <td data-label="Usuario">
            <div class="user-cell">
              <div class="avatar-cell" style="background:<?= $avatarColor ?>">
                <?= strtoupper(substr($u['nombre'],0,1)) ?>
              </div>
              <div>
                <div class="user-name"><?= htmlspecialchars($u['nombre']) ?></div>
                <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
              </div>
            </div>
          </td>
          <td data-label="Rol">
            <?php
              $esRevendedor = $u['rol'] !== 'admin' && !empty($u['es_revendedor']);
              $rolClase = $u['rol']==='admin' ? 'admin' : ($esRevendedor ? 'revendedor' : 'cliente');
              $rolTexto = $u['rol']==='admin' ? 'admin' : ($esRevendedor ? 'revendedor' : 'cliente');
            ?>
            <span class="badge badge-<?= $rolClase ?>"><?= $rolTexto ?></span>
          </td>
          <td data-label="Estado">
            <span class="badge badge-<?= $u['estado']==='activo'?'ok':'no' ?>"><?= $u['estado'] ?></span>
          </td>
          <td data-label="Saldo">
            <span class="saldo-cell <?= ((float)$u['saldo'] > 0) ? 'has-saldo' : '' ?>">
              $<?= number_format((float)$u['saldo'], 0, ',', '.') ?>
            </span>
          </td>
          <td data-label="Registro" style="color:var(--text3);font-size:12px">
            <?= date('d/m/Y', strtotime($u['created_at'])) ?>
          </td>
          <td data-label="Acciones">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button class="btn-sm btn-edit"
                onclick='abrirEditar(<?= json_encode($u, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                Editar
              </button>
              <button class="btn-sm btn-edit"
                onclick='abrirReset(<?= (int)$u['id'] ?>, <?= json_encode($u['nombre'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                🔑 Clave
              </button>
              <?php if ($u['rol'] !== 'admin'): ?>
              <form method="POST" onsubmit="return confirm('¿Desactivar a <?= htmlspecialchars(addslashes($u['nombre'])) ?>? El usuario no podrá iniciar sesión, pero sus datos se conservan.')" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="eliminar_usuario">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn-sm btn-deact">Desactivar</button>
              </form>
              <form method="POST" onsubmit="return confirm('⚠️ ELIMINAR PERMANENTEMENTE a <?= htmlspecialchars(addslashes($u['nombre'])) ?>?\n\nSe borrarán también sus pedidos, recargas y movimientos de saldo.\nEsta acción NO se puede deshacer.')" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="borrar_usuario">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn-sm btn-borrar">Eliminar</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div>


<!-- MODAL CREAR -->
<div class="overlay" id="overlayCrear">
  <div class="modal">
    <button class="modal-close" onclick="cerrar('overlayCrear')">✕</button>
    <div class="modal-title">Nuevo usuario</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="crear_usuario">
      <div class="form-group">
        <label>Nombre completo</label>
        <input type="text" name="nombre" required placeholder="Juan Pérez">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required placeholder="juan@ejemplo.com">
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="password" name="password" required placeholder="Mínimo 6 caracteres">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Rol</label>
          <select id="cu_rol_combo" onchange="syncRolCombo('cu')">
            <option value="cliente">Cliente</option>
            <option value="cliente_revendedor">Cliente · Revendedor</option>
            <option value="admin">Admin</option>
          </select>
          <input type="hidden" name="rol" id="cu_rol" value="cliente">
          <input type="hidden" name="es_revendedor" id="cu_es_revendedor" value="0">
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select name="estado">
            <option value="activo">Activo</option>
            <option value="inactivo">Inactivo</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Saldo inicial (COP)</label>
        <input type="text" name="saldo" value="0" inputmode="numeric" placeholder="Ej: 30.000 o 30000">
      </div>
      <button type="submit" class="btn-primary">Crear usuario →</button>
    </form>
  </div>
</div>

<!-- MODAL EDITAR -->
<div class="overlay" id="overlayEditar">
  <div class="modal">
    <button class="modal-close" onclick="cerrar('overlayEditar')">✕</button>
    <div class="modal-title">Editar usuario</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="editar_usuario">
      <input type="hidden" name="id" id="eu_id">
      <div class="form-group">
        <label>Nombre completo</label>
        <input type="text" name="nombre" id="eu_nombre" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" id="eu_email" required>
      </div>
      <div class="form-group">
        <label>Nueva contraseña</label>
        <input type="password" name="password" placeholder="Dejar vacío para no cambiar">
        <div class="form-hint">Solo completa si quieres cambiar la contraseña actual</div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Rol</label>
          <select id="eu_rol_combo" onchange="syncRolCombo('eu')">
            <option value="cliente">Cliente</option>
            <option value="cliente_revendedor">Cliente · Revendedor</option>
            <option value="admin">Admin</option>
          </select>
          <input type="hidden" name="rol" id="eu_rol" value="cliente">
          <input type="hidden" name="es_revendedor" id="eu_es_revendedor" value="0">
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select name="estado" id="eu_estado">
            <option value="activo">Activo</option>
            <option value="inactivo">Inactivo</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Saldo (COP)</label>
        <input type="text" name="saldo" id="eu_saldo" class="input-saldo" inputmode="numeric" value="0" placeholder="Ej: 30.000 o 30000">
        <div class="form-hint">Edita directamente el saldo disponible del usuario. Solo visible para administradores.</div>
      </div>
      <button type="submit" class="btn-primary">Guardar cambios →</button>
    </form>
  </div>
</div>

<!-- MODAL RESETEAR CONTRASEÑA -->
<div class="overlay" id="overlayReset">
  <div class="modal">
    <button class="modal-close" onclick="cerrar('overlayReset')">✕</button>
    <div class="modal-title">Resetear contraseña</div>
    <p style="font-size:13px;color:var(--text3);margin-bottom:18px" id="reset_info"></p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="resetear_password">
      <input type="hidden" name="id" id="rs_id">
      <div class="form-group">
        <label>Nueva contraseña</label>
        <input type="text" name="password" id="rs_password" required minlength="6" autocomplete="off" placeholder="Mínimo 6 caracteres">
        <div class="form-hint">Comparte esta contraseña con el usuario. Podrá cambiarla luego desde su cuenta.</div>
      </div>
      <button type="button" class="btn-sm btn-saldo" style="margin-bottom:12px" onclick="generarPass()">🎲 Generar aleatoria</button>
      <button type="submit" class="btn-primary">Guardar nueva contraseña →</button>
    </form>
  </div>
</div>

<script>
// Búsqueda instantánea (sin recargar la página, no pierde el foco)
function filtrarUsuarios() {
  const q = (document.getElementById('userSearch').value || '').toLowerCase().trim();
  const filas = document.querySelectorAll('#usuariosBody tr');
  let visibles = 0;
  filas.forEach(tr => {
    const txt = (tr.getAttribute('data-search') || '');
    const mostrar = txt.includes(q);
    tr.style.display = mostrar ? '' : 'none';
    if (mostrar) visibles++;
  });
  const cnt = document.getElementById('tableCount');
  if (cnt) cnt.textContent = visibles + ' resultado' + (visibles !== 1 ? 's' : '');
}
// Filtrar al cargar por si venía texto escrito
document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('userSearch') && document.getElementById('userSearch').value) filtrarUsuarios(); });

function cerrar(id) { document.getElementById(id).classList.remove('open'); }

function abrirReset(id, nombre) {
  document.getElementById('rs_id').value = id;
  document.getElementById('rs_password').value = '';
  document.getElementById('reset_info').textContent = 'Estableciendo una nueva contraseña para ' + nombre + '.';
  document.getElementById('overlayReset').classList.add('open');
}
function generarPass() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  let p = '';
  for (let i = 0; i < 10; i++) p += chars[Math.floor(Math.random() * chars.length)];
  document.getElementById('rs_password').value = p;
}
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
// ESC cierra cualquier modal
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o=>o.classList.remove('open')); });

function abrirCrear() {
  syncRolCombo('cu');
  document.getElementById('overlayCrear').classList.add('open');
}

function syncRolCombo(prefix) {
  const combo  = document.getElementById(prefix + '_rol_combo');
  const rol    = document.getElementById(prefix + '_rol');
  const rev    = document.getElementById(prefix + '_es_revendedor');
  if (combo.value === 'admin') {
    rol.value = 'admin'; rev.value = '0';
  } else if (combo.value === 'cliente_revendedor') {
    rol.value = 'cliente'; rev.value = '1';
  } else {
    rol.value = 'cliente'; rev.value = '0';
  }
  combo.classList.toggle('select-revendedor', combo.value === 'cliente_revendedor');
}

function abrirEditar(u) {
  document.getElementById('eu_id').value     = u.id;
  document.getElementById('eu_nombre').value = u.nombre;
  document.getElementById('eu_email').value  = u.email;
  document.getElementById('eu_estado').value = u.estado;
  document.getElementById('eu_saldo').value  = (u.saldo !== null && u.saldo !== undefined) ? Math.round(parseFloat(u.saldo)) : 0;
  document.getElementById('eu_rol_combo').value = u.rol === 'admin' ? 'admin' : ((u.es_revendedor == 1) ? 'cliente_revendedor' : 'cliente');
  syncRolCombo('eu');
  document.getElementById('overlayEditar').classList.add('open');
}
</script>
</body>
</html>