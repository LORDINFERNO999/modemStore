<?php
// includes/modal-password.php
// Modal reutilizable para cambiar la contraseña del usuario en sesión.
// Incluir antes de </body>. Disparar con: abrirCambioPassword()
require_once __DIR__ . '/seguridad.php';
?>
<div class="pw-overlay" id="pwOverlay">
  <div class="pw-modal">
    <button type="button" class="pw-close" onclick="cerrarCambioPassword()">✕</button>
    <div class="pw-title">Cambiar contraseña</div>
    <div class="pw-msg" id="pwMsg"></div>
    <form id="pwForm" autocomplete="off">
      <div class="pw-group">
        <label>Contraseña actual</label>
        <input type="password" id="pw_actual" required autocomplete="current-password">
      </div>
      <div class="pw-group">
        <label>Nueva contraseña</label>
        <input type="password" id="pw_nueva" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
      </div>
      <div class="pw-group">
        <label>Confirmar nueva contraseña</label>
        <input type="password" id="pw_confirmar" required minlength="6" autocomplete="new-password">
      </div>
      <button type="submit" class="pw-btn" id="pwBtn">Actualizar contraseña →</button>
    </form>
  </div>
</div>

<style>
.pw-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:3000;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(8px);}
.pw-overlay.open{display:flex;}
.pw-modal{background:#161616;border:1px solid rgba(255,255,255,.14);border-radius:20px;padding:26px;width:100%;max-width:420px;position:relative;color:#fff;font-family:'Inter','DM Sans',sans-serif;animation:pwUp .25s ease;}
@keyframes pwUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.pw-close{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:50%;background:#1e1e1e;border:none;color:#a3a3a3;cursor:pointer;font-size:15px;}
.pw-close:hover{background:#262626;color:#fff;}
.pw-title{font-size:18px;font-weight:800;margin-bottom:18px;}
.pw-group{margin-bottom:14px;}
.pw-group label{display:block;font-size:11px;color:#777;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;}
.pw-group input{width:100%;padding:11px 13px;background:#1e1e1e;border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#fff;font-family:inherit;font-size:14px;outline:none;transition:border-color .2s;}
.pw-group input:focus{border-color:#7c6dfa;}
.pw-btn{width:100%;padding:13px;margin-top:6px;background:linear-gradient(135deg,#7c6dfa,#9c8df7);border:none;border-radius:10px;color:#fff;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:all .2s;}
.pw-btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,109,250,.3);}
.pw-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}
.pw-msg{border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:16px;display:none;}
.pw-msg.ok{display:block;background:rgba(29,185,84,.1);border:1px solid rgba(29,185,84,.3);color:#1db954;}
.pw-msg.err{display:block;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444;}
.pw-toast{position:fixed;top:24px;left:50%;transform:translateX(-50%) translateY(-20px);z-index:5000;background:#13131a;border:1px solid rgba(29,185,84,.4);border-left:4px solid #1db954;color:#fff;padding:14px 22px;border-radius:12px;font-family:'Inter',sans-serif;font-size:14px;font-weight:600;box-shadow:0 14px 40px rgba(0,0,0,.5);opacity:0;pointer-events:none;transition:all .3s ease;}
.pw-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
</style>
<div class="pw-toast" id="pwToast"></div>

<script>
(function () {
  const PW_CSRF = '<?= csrfToken() ?>';
  const PW_URL  = '<?= SITE_URL ?>/ajax/cambiar-password.php';

  window.abrirCambioPassword = function () {
    document.getElementById('pwMsg').className = 'pw-msg';
    document.getElementById('pwForm').reset();
    document.getElementById('pwOverlay').classList.add('open');
  };
  window.cerrarCambioPassword = function () {
    document.getElementById('pwOverlay').classList.remove('open');
  };

  document.getElementById('pwOverlay').addEventListener('click', function (e) {
    if (e.target === this) cerrarCambioPassword();
  });

  document.getElementById('pwForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const msg = document.getElementById('pwMsg');
    const btn = document.getElementById('pwBtn');
    msg.className = 'pw-msg';
    btn.disabled = true; btn.textContent = 'Actualizando…';

    fetch(PW_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: PW_CSRF,
        actual:     document.getElementById('pw_actual').value,
        nueva:      document.getElementById('pw_nueva').value,
        confirmar:  document.getElementById('pw_confirmar').value,
      })
    })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.textContent = 'Actualizar contraseña →';
      if (data.ok) {
        document.getElementById('pwForm').reset();
        cerrarCambioPassword();
        const t = document.getElementById('pwToast');
        t.textContent = '✓ ' + data.msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
      } else {
        msg.textContent = data.msg;
        msg.className = 'pw-msg err';
      }
    })
    .catch(() => {
      btn.disabled = false; btn.textContent = 'Actualizar contraseña →';
      msg.textContent = 'Error de conexión. Intenta de nuevo.';
      msg.className = 'pw-msg err';
    });
  });
})();
</script>
