// assets/js/main.js — Interacciones comunes del layout (header.php)

document.addEventListener('DOMContentLoaded', () => {
  // ── Toggle de sidebar en móvil ──
  const toggle  = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');

  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));

    // Cerrar al hacer clic fuera del sidebar (en móvil)
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 900 &&
          sidebar.classList.contains('open') &&
          !sidebar.contains(e.target) &&
          !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  // ── Buscador de servicios ──
  const buscador = document.getElementById('buscador');
  if (buscador) {
    buscador.addEventListener('input', () => {
      const q = buscador.value.trim().toLowerCase();
      document.querySelectorAll('[data-buscable]').forEach((el) => {
        const txt = (el.dataset.buscable || el.textContent).toLowerCase();
        el.style.display = txt.includes(q) ? '' : 'none';
      });
    });
  }
});
