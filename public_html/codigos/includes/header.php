<?php
// Prefijos de navegación para que los enlaces funcionen igual si el header
// se incluye desde /public o desde /admin (carpetas hermanas).
$inAdmin     = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
$pubPrefix   = $inAdmin ? '../public/' : '';
$adminPrefix = $inAdmin ? '' : '../admin/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0a0a0f">
<title><?= $pageTitle ?? 'VerifyCodes' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#08070d; --surface:#141220; --surface-2:#1b1828;
    --border:#2a2340; --text:#ece9f5; --muted:#9a94b5;
    --purple:#8b5cf6; --purple-2:#a855f7; --purple-dark:#7c3aed;
    --radius:16px;
  }
  *{ scrollbar-color:#3a3155 transparent; }
  ::-webkit-scrollbar{ width:10px; height:10px; }
  ::-webkit-scrollbar-thumb{ background:#332c4d; border-radius:10px; }
  ::-webkit-scrollbar-track{ background:transparent; }
  ::selection{ background:rgba(139,92,246,.4); color:#fff; }

  body {
    background:
      radial-gradient(1200px 600px at 15% -15%, rgba(124,58,237,.22), transparent 60%),
      radial-gradient(1000px 560px at 110% -10%, rgba(168,85,247,.16), transparent 55%),
      radial-gradient(800px 800px at 50% 120%, rgba(88,28,135,.18), transparent 60%),
      var(--bg);
    background-attachment:fixed;
    color:var(--text); font-family:'Inter','Segoe UI',system-ui,sans-serif;
    min-height:100vh; -webkit-font-smoothing:antialiased; }
  a { text-decoration:none; color:var(--purple-2); }
  a:hover { color:var(--purple); }

  h1,h2,h3,h4,h5,h6 { color:#f6f4fc; font-weight:700; letter-spacing:-.01em; }
  code { color:#c4b5fd; }

  /* ---------- Navbar ---------- */
  .navbar { background:rgba(10,9,15,.72); backdrop-filter:blur(14px);
    border-bottom:1px solid var(--border); box-shadow:0 6px 26px rgba(0,0,0,.35); }
  .navbar-brand { color:#fff !important; font-weight:800; font-size:1.2rem; letter-spacing:.2px; }
  .navbar-brand .bi { color:var(--purple-2); }
  .nav-user{ color:var(--muted); font-size:.85rem; }

  /* ---------- Cards ---------- */
  .card { background:linear-gradient(165deg,#161327 0%,#100e1a 100%);
    border:1px solid var(--border); border-radius:var(--radius);
    box-shadow:0 10px 30px rgba(0,0,0,.42);
    transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease; }
  .card:hover { transform:translateY(-3px); border-color:rgba(139,92,246,.55);
    box-shadow:0 16px 40px rgba(124,58,237,.25); }

  /* ---------- Botones ---------- */
  .btn { font-weight:600; border-radius:12px; transition:transform .12s ease, box-shadow .2s ease, filter .2s ease; }
  .btn:active{ transform:translateY(1px); }
  .btn-success{ background:linear-gradient(120deg,var(--purple),var(--purple-dark)); border:none; color:#fff;
    box-shadow:0 6px 18px rgba(124,58,237,.35); }
  .btn-success:hover,.btn-success:focus{ filter:brightness(1.1); color:#fff;
    box-shadow:0 10px 26px rgba(124,58,237,.5); }
  .btn-primary{ background:linear-gradient(120deg,var(--purple),var(--purple-dark)); border:none; color:#fff; }
  .btn-primary:hover{ filter:brightness(1.1); }
  .btn-outline-light{ border-color:var(--border); color:var(--text); }
  .btn-outline-light:hover{ background:var(--surface-2); color:#fff; border-color:var(--purple); }

  /* ---------- Textos / etiquetas ---------- */
  .form-label { color:#d7d2ea; font-weight:500; }
  label, .form-check-label { color:#d7d2ea; }
  .text-secondary { color:var(--muted) !important; }
  .form-text { color:#8b86a3; }

  .code-box { font-size:2.4rem; letter-spacing:.2em; font-weight:800; color:#e5dcff;
    display:inline-block; padding:.4rem 1.2rem; border-radius:14px;
    background:linear-gradient(120deg,rgba(139,92,246,.18),rgba(124,58,237,.1));
    border:1px solid rgba(139,92,246,.45);
    box-shadow:0 0 26px rgba(168,85,247,.28), inset 0 0 20px rgba(139,92,246,.08); }

  /* ---------- Badges ---------- */
  .badge.bg-primary, .badge-service{
    background:linear-gradient(120deg,var(--purple),var(--purple-dark)) !important; }
  .badge-service{ text-transform:uppercase; font-size:.7rem; letter-spacing:.4px; }

  /* ---------- Formularios ---------- */
  .form-control,.form-select{ background:var(--surface-2); border:1px solid var(--border);
    color:var(--text); border-radius:10px; }
  .form-control:focus,.form-select:focus{ background:var(--surface-2); color:var(--text);
    border-color:var(--purple); box-shadow:0 0 0 .22rem rgba(139,92,246,.28); }
  .form-control::placeholder{ color:var(--muted); }
  .input-group-text{ background:var(--surface-2); border:1px solid var(--border); color:var(--muted); }
  .form-check-input:checked{ background-color:var(--purple); border-color:var(--purple); }

  /* ---------- Pestañas admin (pill container) ---------- */
  .nav-pills{ display:inline-flex; flex-wrap:wrap; gap:4px; padding:6px;
    background:var(--surface); border:1px solid var(--border); border-radius:14px; }
  .nav-pills .nav-link{ color:var(--muted); border-radius:9px; padding:.4rem .85rem; font-size:.92rem; }
  .nav-pills .nav-link:hover{ color:var(--text); }
  .nav-pills .nav-link.active{ background:linear-gradient(120deg,var(--purple),var(--purple-dark));
    color:#fff; box-shadow:0 4px 14px rgba(124,58,237,.4); }

  /* ---------- Tablas ---------- */
  .table-dark{ --bs-table-bg:transparent; --bs-table-striped-bg:rgba(255,255,255,.03); }

  /* ---------- Alertas (acordes al tema oscuro) ---------- */
  .alert{ border-radius:12px; border:1px solid var(--border); }
  .alert-secondary{ background:rgba(139,92,246,.08); color:#cfc9e6; border-color:var(--border); }
  .alert-success{ background:rgba(34,197,94,.12); color:#86efac; border-color:rgba(34,197,94,.35); }
  .alert-danger{ background:rgba(239,68,68,.12); color:#fca5a5; border-color:rgba(239,68,68,.35); }
  .alert-warning{ background:rgba(234,179,8,.12); color:#fde68a; border-color:rgba(234,179,8,.35); }
  .alert-secondary a{ color:var(--purple-2); }

  .btn-copy { border-radius:8px; }
  .spinner-border-sm { width:1rem; height:1rem; }

  /* ---------- Miniatura de plataforma (llena el cuadro) ---------- */
  .platform-thumb{ width:100%; height:170px; border-radius:12px; overflow:hidden;
    background:#0d0b16; display:flex; align-items:center; justify-content:center;
    border:1px solid var(--border); }
  .platform-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
  .platform-thumb .placeholder-icon{ color:var(--muted); font-size:2rem; }

  /* ---------- Responsive / móvil ---------- */
  @media (max-width:576px){
    .container{ padding-left:14px; padding-right:14px; }
    .card{ border-radius:14px; }
    .code-box{ font-size:1.95rem; letter-spacing:.15em; padding:.35rem .9rem; }
    .navbar-brand{ font-size:1.05rem; }
    .platform-thumb{ height:150px; }
    h4{ font-size:1.25rem; }
    /* controles del dashboard a ancho completo en móvil */
    .toolbar .input-group,
    .toolbar .form-select{ width:100% !important; }
    .toolbar{ width:100%; }
  }
</style>
</head>
<body>
<nav class="navbar navbar-dark mb-4 sticky-top">
  <div class="container flex-wrap gap-2">
    <a class="navbar-brand fw-bold" href="<?= $pubPrefix ?>dashboard.php"><i class="bi bi-shield-lock-fill"></i> VerifyCodes</a>
    <?php if (\Auth::check()): ?>
    <div class="d-flex align-items-center gap-2">
      <span class="nav-user d-none d-sm-inline"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
      <?php if (\Auth::isAdmin()): ?>
        <a href="<?= $adminPrefix ?>index.php" class="btn btn-sm btn-outline-light"><i class="bi bi-speedometer2"></i><span class="d-none d-sm-inline"> Admin</span></a>
      <?php endif; ?>
      <a href="<?= $pubPrefix ?>logout.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline"> Salir</span></a>
    </div>
    <?php endif; ?>
  </div>
</nav>
<div class="container pb-5">