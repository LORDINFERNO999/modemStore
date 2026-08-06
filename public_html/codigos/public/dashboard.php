<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/CodeExtractor.php';

\Auth::requireLogin();

$db = Database::get();
$stmt = $db->prepare(
    'SELECT m.id, m.email, m.service_type
     FROM mailboxes m
     INNER JOIN user_mailbox_access uma ON uma.mailbox_id = m.id
     WHERE uma.user_id = ? AND m.active = 1
     ORDER BY m.service_type, m.email'
);
$stmt->execute([\Auth::id()]);
$mailboxes = $stmt->fetchAll();

// Plataformas (clave => etiqueta) e imágenes, gestionadas por el admin en la BD
$display  = CodeExtractor::platformsForDisplay();
$services = [];
$images   = [];
foreach ($display as $key => $info) {
    $services[$key] = $info['label'];
    $images[$key]   = $info['image']; // ruta relativa a /public (o null)
}
// Si no hay plataformas pero sí cuentas, probablemente falta la migración.
$needsMigration = empty($services) && !empty($mailboxes);

// Restricción de plataformas por usuario (si el admin la definió).
// Sin filas => puede usar todas; con filas => solo esas.
try {
    $stmt = $db->prepare('SELECT service_key FROM user_platform_access WHERE user_id = ?');
    $stmt->execute([\Auth::id()]);
    $allowedPlatforms = array_column($stmt->fetchAll(), 'service_key');
} catch (\PDOException $e) {
    $allowedPlatforms = [];
}
if (!empty($allowedPlatforms)) {
    $services = array_intersect_key($services, array_flip($allowedPlatforms));
    $images   = array_intersect_key($images, array_flip($allowedPlatforms));
}

$defaultWindow = IMAP_SEARCH_WINDOW_MINUTES;
$maxWindow     = defined('IMAP_SEARCH_WINDOW_MINUTES_MAX') ? IMAP_SEARCH_WINDOW_MINUTES_MAX : 120;

$pageTitle = 'Mis cuentas - VerifyCodes';
include __DIR__ . '/../includes/header.php';
?>
<?php if ($needsMigration): ?>
  <div class="alert alert-warning">
    <strong>Falta actualizar la base de datos.</strong> Ejecuta <code>sql/migration_platforms_v2.sql</code>
    en phpMyAdmin para habilitar las plataformas, el selector y las imágenes.
  </div>
<?php endif; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-inboxes"></i> Cuentas autorizadas</h4>
  <?php if (!empty($mailboxes)): ?>
  <div class="d-flex flex-wrap align-items-center gap-2 toolbar">
    <div class="input-group input-group-sm" style="width:220px">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="text" id="searchBox" class="form-control" placeholder="Buscar cuenta o servicio...">
    </div>
    <select id="windowSelect" class="form-select form-select-sm" style="width:auto" title="Ventana de correos recientes">
      <?php
        $opts = [15 => 'Últimos 15 min', 30 => 'Últimos 30 min', 60 => 'Última hora'];
        foreach ($opts as $min => $label):
            if ($min > $maxWindow) continue;
      ?>
        <option value="<?= $min ?>" <?= $min == $defaultWindow ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-check form-switch text-secondary small mb-0">
      <input class="form-check-input" type="checkbox" role="switch" id="autoRefresh">
      <label class="form-check-label" for="autoRefresh">Auto-actualizar</label>
    </div>
    <select id="refreshInterval" class="form-select form-select-sm" style="width:auto" disabled>
      <option value="10">cada 10 s</option>
      <option value="20" selected>cada 20 s</option>
      <option value="30">cada 30 s</option>
      <option value="60">cada 60 s</option>
    </select>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($mailboxes)): ?>
  <div class="alert alert-secondary">Aún no tienes cuentas asignadas. Contacta al administrador.</div>
<?php else: ?>
<div class="row g-3" id="cardsGrid">
  <?php foreach ($mailboxes as $mb):
    $defaultSvc = isset($services[$mb['service_type']]) ? $mb['service_type'] : array_key_first($services);
    $initialImg = $images[$defaultSvc] ?? '';
  ?>
  <div class="col-md-6 col-lg-4 mailbox-col"
       data-search="<?= htmlspecialchars(strtolower($mb['service_type'] . ' ' . $mb['email'])) ?>">
    <div class="card p-3 h-100" data-mailbox-id="<?= (int)$mb['id'] ?>">
      <div class="platform-thumb mb-2">
        <img class="platform-img" src="<?= htmlspecialchars($initialImg) ?>" alt=""
             style="<?= $initialImg ? '' : 'display:none' ?>" onerror="this.style.display='none'">
        <i class="bi bi-play-btn placeholder-icon platform-img-fallback"
           style="<?= $initialImg ? 'display:none' : '' ?>"></i>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-2">
        <select class="form-select form-select-sm service-select" style="width:auto">
          <?php foreach ($services as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $key === $defaultSvc ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="text-secondary text-truncate ms-2" style="max-width:55%"><?= htmlspecialchars($mb['email']) ?></small>
      </div>

      <div class="text-center my-3 result-area">
        <span class="text-secondary small">Elige la plataforma y pulsa "Consultar"</span>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-success btn-sm flex-grow-1 btn-consultar">
          <i class="bi bi-search"></i> Consultar
        </button>
        <button class="btn btn-outline-light btn-sm btn-copy d-none" title="Copiar código">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <div class="text-end mt-2">
        <small class="text-secondary last-updated" style="font-size:.72rem"></small>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div id="noResults" class="alert alert-secondary d-none mt-3">Ninguna cuenta coincide con la búsqueda.</div>
<?php endif; ?>

<script>
const csrfToken = "<?= \Csrf::token() ?>";
const platformImages = <?= json_encode($images, JSON_UNESCAPED_SLASHES) ?>;
let autoTimer = null;

/* ---------- Imagen de plataforma según el selector ---------- */
function updatePlatformImage(card) {
  const sel = card.querySelector('.service-select');
  const img = card.querySelector('.platform-img');
  const fallback = card.querySelector('.platform-img-fallback');
  const url = sel ? platformImages[sel.value] : null;
  if (url) {
    img.src = url;
    img.style.display = '';
    fallback.style.display = 'none';
  } else {
    img.style.display = 'none';
    fallback.style.display = '';
  }
}
document.querySelectorAll('.service-select').forEach(sel => {
  sel.addEventListener('change', () => updatePlatformImage(sel.closest('[data-mailbox-id]')));
});

/* ---------- Búsqueda rápida ---------- */
const searchBox = document.getElementById('searchBox');
if (searchBox) {
  searchBox.addEventListener('input', () => {
    const q = searchBox.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.mailbox-col').forEach(col => {
      const match = col.dataset.search.includes(q);
      col.classList.toggle('d-none', !match);
      if (match) visible++;
    });
    document.getElementById('noResults').classList.toggle('d-none', visible !== 0);
  });
}

/* ---------- Consulta de un código ---------- */
document.querySelectorAll('.btn-consultar').forEach(btn => {
  btn.addEventListener('click', () => consultarCodigo(btn.closest('[data-mailbox-id]')));
});

async function consultarCodigo(card) {
  const btn = card.querySelector('.btn-consultar');
  const mailboxId = card.dataset.mailboxId;
  const service = card.querySelector('.service-select')?.value || '';
  const resultArea = card.querySelector('.result-area');
  const copyBtn = card.querySelector('.btn-copy');
  const lastUpdated = card.querySelector('.last-updated');
  const windowMin = document.getElementById('windowSelect')?.value || <?= (int)$defaultWindow ?>;

  btn.disabled = true;
  resultArea.innerHTML = '<div class="spinner-border spinner-border-sm text-light"></div> <span class="small">Buscando...</span>';

  try {
    const res = await fetch('ajax/get_code.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `mailbox_id=${encodeURIComponent(mailboxId)}&service=${encodeURIComponent(service)}&window=${encodeURIComponent(windowMin)}&csrf_token=${encodeURIComponent(csrfToken)}`
    });
    const data = await res.json();

    resultArea.innerHTML = '';
    if (!data.success) {
      renderText(resultArea, data.message || 'Error', 'text-danger small');
      copyBtn.classList.add('d-none');
    } else if (data.type === 'travel') {
      resultArea.innerHTML = '<span class="text-warning"><i class="bi bi-airplane"></i> Aviso: cuenta en modo "Estoy de viaje"</span>';
      copyBtn.classList.add('d-none');
    } else if (data.type === 'code') {
      const codeBox = document.createElement('div');
      codeBox.className = 'code-box';
      codeBox.textContent = data.code;
      const info = document.createElement('small');
      info.className = 'text-secondary';
      info.textContent = `Válido ${data.valid_seconds}s más`;
      resultArea.append(codeBox, document.createElement('br'), info);

      copyBtn.classList.remove('d-none');
      copyBtn.onclick = () => {
        navigator.clipboard.writeText(data.code);
        copyBtn.innerHTML = '<i class="bi bi-check2"></i>';
        setTimeout(() => copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>', 1500);
      };
    } else {
      renderText(resultArea, 'No hay código reciente. Intenta de nuevo en unos segundos.', 'text-secondary small');
      copyBtn.classList.add('d-none');
    }
    if (lastUpdated) lastUpdated.textContent = 'Actualizado ' + new Date().toLocaleTimeString();
  } catch (e) {
    resultArea.innerHTML = '';
    renderText(resultArea, 'Error de conexión.', 'text-danger small');
  } finally {
    btn.disabled = false;
  }
}

function renderText(container, text, className) {
  const span = document.createElement('span');
  span.className = className;
  span.textContent = text;
  container.appendChild(span);
}

/* ---------- Auto-actualización (polling) ---------- */
const autoRefresh = document.getElementById('autoRefresh');
const refreshInterval = document.getElementById('refreshInterval');

function refreshVisibleCards() {
  document.querySelectorAll('.mailbox-col:not(.d-none) [data-mailbox-id]').forEach(card => consultarCodigo(card));
}
function setupAutoRefresh() {
  if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
  if (autoRefresh && autoRefresh.checked) {
    const secs = parseInt(refreshInterval.value, 10) || 20;
    refreshVisibleCards();
    autoTimer = setInterval(refreshVisibleCards, secs * 1000);
  }
}
if (autoRefresh) {
  autoRefresh.addEventListener('change', () => {
    refreshInterval.disabled = !autoRefresh.checked;
    setupAutoRefresh();
  });
  refreshInterval.addEventListener('change', setupAutoRefresh);
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>