<?php
// includes/header.php
// Este archivo puede usarse en páginas internas que no usan el layout completo de index.php
// La sidebar y el main content están directamente en index.php y demás páginas principales.
// Aquí van las dependencias <head> compartidas si las necesitas en otras páginas.
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : htmlspecialchars(getConfig('nombre_sitio')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tabler-icons/2.44.0/iconfont/tabler-icons.min.css">
  <link rel="stylesheet" href="<?= isset($rootPath) ? $rootPath : '' ?>assets/css/main.css">
</head>
<body>

<!-- Toggle móvil -->
<button class="nav-mobile-toggle" id="sidebarToggle">
  <i class="ti ti-menu-2"></i>
</button>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
      <span class="brand-text">MODEM<span>STORE</span></span>
    </div>

    <div class="sidebar-search">
      <i class="ti ti-search"></i>
      <input type="text" id="buscador" placeholder="Buscar servicio...">
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Menú</div>
      <a href="<?= isset($rootPath) ? $rootPath : '' ?>index.php">
        <i class="ti ti-home"></i> Inicio
      </a>
      <a href="<?= isset($rootPath) ? $rootPath : '' ?>tienda.php">
        <i class="ti ti-shopping-bag"></i> Tienda
      </a>
      <?php
      $usuario = isset($usuario) ? $usuario : (function_exists('usuarioActual') ? usuarioActual() : null);
      if ($usuario): ?>
        <a href="<?= isset($rootPath) ? $rootPath : '' ?>dashboard.php">
          <i class="ti ti-user"></i> Mi cuenta
        </a>
        <a href="<?= isset($rootPath) ? $rootPath : '' ?>mis-pedidos.php">
          <i class="ti ti-package"></i> Mis pedidos
        </a>
        <?php if (($usuario['rol'] ?? $usuario['tipo'] ?? '') === 'admin'): ?>
          <a href="<?= isset($rootPath) ? $rootPath : '' ?>admin/">
            <i class="ti ti-settings"></i> Admin
          </a>
        <?php endif; ?>
        <a href="<?= isset($rootPath) ? $rootPath : '' ?>logout.php">
          <i class="ti ti-logout"></i> Cerrar sesión
        </a>
      <?php else: ?>
        <a href="<?= isset($rootPath) ? $rootPath : '' ?>login.php">
          <i class="ti ti-key"></i> Ingresar
        </a>
        <a href="<?= isset($rootPath) ? $rootPath : '' ?>registro.php">
          <i class="ti ti-user-plus"></i> Registrarse
        </a>
      <?php endif; ?>
    </nav>


    <div class="sidebar-footer">
      <div class="sidebar-contact">
        <i class="ti ti-brand-whatsapp"></i>
        <span><?= function_exists('getConfig') ? htmlspecialchars(getConfig('whatsapp_soporte')) : '' ?></span>
      </div>
      <div class="sidebar-redes">
        <?php $wa = function_exists('getConfig') ? getConfig('whatsapp_soporte') : ''; ?>
        <a href="https://wa.me/<?= $wa ?>" target="_blank" title="WhatsApp"><i class="ti ti-brand-whatsapp"></i></a>
        <a href="#" title="Instagram"><i class="ti ti-brand-instagram"></i></a>
        <a href="#" title="Facebook"><i class="ti ti-brand-facebook"></i></a>
      </div>
    </div>

  </aside>

  <!-- El main-content lo abre cada página individualmente -->
  <main class="main-content">