<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/CodeExtractor.php';

\Auth::requireAdmin();
$db = Database::get();
$message = null;
$error = null;

// Carpeta física donde se guardan las imágenes subidas
const UPLOAD_DIR = __DIR__ . '/../public/uploads/platforms/';
const UPLOAD_WEB = 'uploads/platforms/'; // ruta relativa a /public que se guarda en BD

/**
 * Procesa la imagen subida desde el escritorio. Devuelve [rutaWeb|null, error|null].
 */
function handleUpload(string $serviceKey): array
{
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null]; // no subieron imagen (opcional)
    }
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Error al subir la imagen (código ' . $file['error'] . ').'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, 'La imagen supera el máximo de 2 MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extByMime[$mime])) {
        return [null, 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    $safeKey = preg_replace('/[^a-z0-9_-]/', '', strtolower($serviceKey)) ?: 'plataforma';
    $filename = $safeKey . '-' . time() . '.' . $extByMime[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        return [null, 'No se pudo guardar la imagen en el servidor.'];
    }
    return [UPLOAD_WEB . $filename, null];
}

/** Borra del disco una imagen guardada (si existe). */
function deleteImageFile(?string $webPath): void
{
    if (!$webPath) return;
    $full = __DIR__ . '/../public/' . $webPath;
    if (is_file($full)) {
        @unlink($full);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \Csrf::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $label   = trim($_POST['label'] ?? '');
        $from    = trim($_POST['from_contains'] ?? '');
        $subject = trim($_POST['subject_contains'] ?? '');
        $codeMin = max(1, min(10, (int)($_POST['code_min'] ?? 4)));
        $codeMax = max($codeMin, min(10, (int)($_POST['code_max'] ?? 6)));
        $travel  = trim($_POST['travel_keywords'] ?? '');
        $blocked = trim($_POST['blocked_keywords'] ?? '');
        $active  = isset($_POST['active']) ? 1 : 0;

        if ($action === 'create') {
            $key = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['service_key'] ?? '')));
            if ($key === '' || $label === '') {
                $error = 'La clave y el nombre son obligatorios.';
            } else {
                $chk = $db->prepare('SELECT 1 FROM platforms WHERE service_key = ? LIMIT 1');
                $chk->execute([$key]);
                if ($chk->fetch()) {
                    $error = 'Ya existe una plataforma con esa clave.';
                }
            }
            if (!$error) {
                [$imgPath, $imgErr] = handleUpload($key);
                if ($imgErr) {
                    $error = $imgErr;
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO platforms (service_key, label, image_path, from_contains, subject_contains, code_min, code_max, travel_keywords, blocked_keywords, active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$key, $label, $imgPath, $from, $subject, $codeMin, $codeMax, $travel, $blocked, $active]);
                    $message = 'Plataforma agregada.';
                }
            }
        } else { // update
            $id = (int)$_POST['id'];
            $cur = $db->prepare('SELECT * FROM platforms WHERE id = ? LIMIT 1');
            $cur->execute([$id]);
            $current = $cur->fetch();
            if (!$current) {
                $error = 'Plataforma no encontrada.';
            } elseif ($label === '') {
                $error = 'El nombre es obligatorio.';
            } else {
                [$imgPath, $imgErr] = handleUpload($current['service_key']);
                if ($imgErr) {
                    $error = $imgErr;
                } else {
                    if ($imgPath) {
                        deleteImageFile($current['image_path']); // reemplaza la anterior
                    } else {
                        $imgPath = $current['image_path']; // conserva la actual
                    }
                    $stmt = $db->prepare(
                        'UPDATE platforms SET label=?, image_path=?, from_contains=?, subject_contains=?, code_min=?, code_max=?, travel_keywords=?, blocked_keywords=?, active=? WHERE id=?'
                    );
                    $stmt->execute([$label, $imgPath, $from, $subject, $codeMin, $codeMax, $travel, $blocked, $active, $id]);
                    $message = 'Plataforma actualizada.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $cur = $db->prepare('SELECT image_path FROM platforms WHERE id = ? LIMIT 1');
        $cur->execute([$id]);
        if ($row = $cur->fetch()) {
            deleteImageFile($row['image_path']);
        }
        $db->prepare('DELETE FROM platforms WHERE id = ?')->execute([$id]);
        $message = 'Plataforma eliminada.';
    }

    CodeExtractor::clearCache();
}

// Listado
try {
    $platforms = $db->query('SELECT * FROM platforms ORDER BY label')->fetchAll();
    $tableMissing = false;
} catch (\PDOException $e) {
    $platforms = [];
    $tableMissing = true;
}

$pageTitle = 'Admin · Plataformas';
include __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-4"><i class="bi bi-collection-play"></i> Plataformas</h4>

<?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($tableMissing): ?>
  <div class="alert alert-warning">Falta la migración de base de datos. Ejecuta <code>sql/migration_platforms_v2.sql</code> en phpMyAdmin.</div>
<?php endif; ?>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link active" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link" href="logs.php">Registros</a></li>
</ul>

<!-- Agregar nueva plataforma -->
<div class="card p-4 mb-4">
  <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Agregar plataforma</h6>
  <form method="post" enctype="multipart/form-data" class="row g-2">
    <?= \Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-2"><label class="form-label small">Clave interna</label>
      <input class="form-control" name="service_key" placeholder="netflix" required></div>
    <div class="col-md-2"><label class="form-label small">Nombre visible</label>
      <input class="form-control" name="label" placeholder="Netflix" required></div>
    <div class="col-md-3"><label class="form-label small">Remitente contiene</label>
      <input class="form-control" name="from_contains" placeholder="netflix.com"></div>
    <div class="col-md-5"><label class="form-label small">Asunto contiene (separado por comas)</label>
      <input class="form-control" name="subject_contains" placeholder="código, code, verificación"></div>
    <div class="col-md-2"><label class="form-label small">Dígitos mín.</label>
      <input type="number" class="form-control" name="code_min" value="4" min="1" max="10"></div>
    <div class="col-md-2"><label class="form-label small">Dígitos máx.</label>
      <input type="number" class="form-control" name="code_max" value="6" min="1" max="10"></div>
    <div class="col-md-4"><label class="form-label small">Palabras "de viaje" (comas, opcional)</label>
      <input class="form-control" name="travel_keywords" placeholder="viaje, travel"></div>
    <div class="col-md-4"><label class="form-label small">Bloquear palabras (comas, opcional)</label>
      <input class="form-control" name="blocked_keywords" placeholder="promo, oferta"></div>
    <div class="col-md-4"><label class="form-label small">Imagen / logo (desde tu equipo)</label>
      <input type="file" class="form-control" name="image" accept="image/*"></div>
    <div class="col-md-2 d-flex align-items-center pt-3">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="act-new" checked>
        <label class="form-check-label" for="act-new">Activa</label></div>
    </div>
    <div class="col-12"><button class="btn btn-success mt-2"><i class="bi bi-plus-lg"></i> Agregar plataforma</button></div>
  </form>
</div>

<!-- Listado / edición -->
<div class="row g-3">
  <?php foreach ($platforms as $p): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="badge bg-primary badge-service"><?= htmlspecialchars($p['label']) ?></span>
        <span class="badge <?= $p['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['active'] ? 'Activa' : 'Inactiva' ?></span>
      </div>
      <div class="platform-thumb my-2">
        <?php if (!empty($p['image_path'])): ?>
          <img src="../public/<?= htmlspecialchars($p['image_path']) ?>" alt="<?= htmlspecialchars($p['label']) ?>">
        <?php else: ?>
          <i class="bi bi-image placeholder-icon"></i>
        <?php endif; ?>
      </div>
      <div class="small text-secondary mb-2">
        <div>Clave: <code><?= htmlspecialchars($p['service_key']) ?></code></div>
        <div>Remitente: <?= htmlspecialchars($p['from_contains'] ?: '—') ?></div>
        <div>Dígitos: <?= (int)$p['code_min'] ?>–<?= (int)$p['code_max'] ?></div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-light flex-grow-1" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int)$p['id'] ?>">
          <i class="bi bi-pencil"></i> Editar
        </button>
        <form method="post" onsubmit="return confirm('¿Eliminar la plataforma <?= htmlspecialchars($p['label']) ?>?');">
          <?= \Csrf::field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
      </div>

      <div class="collapse mt-3" id="edit-<?= (int)$p['id'] ?>">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= \Csrf::field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div class="col-12"><label class="form-label small">Nombre visible</label>
            <input class="form-control form-control-sm" name="label" value="<?= htmlspecialchars($p['label']) ?>" required></div>
          <div class="col-12"><label class="form-label small">Remitente contiene</label>
            <input class="form-control form-control-sm" name="from_contains" value="<?= htmlspecialchars($p['from_contains'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label small">Asunto contiene (comas)</label>
            <input class="form-control form-control-sm" name="subject_contains" value="<?= htmlspecialchars($p['subject_contains'] ?? '') ?>"></div>
          <div class="col-6"><label class="form-label small">Dígitos mín.</label>
            <input type="number" class="form-control form-control-sm" name="code_min" value="<?= (int)$p['code_min'] ?>" min="1" max="10"></div>
          <div class="col-6"><label class="form-label small">Dígitos máx.</label>
            <input type="number" class="form-control form-control-sm" name="code_max" value="<?= (int)$p['code_max'] ?>" min="1" max="10"></div>
          <div class="col-12"><label class="form-label small">Palabras "de viaje" (comas)</label>
            <input class="form-control form-control-sm" name="travel_keywords" value="<?= htmlspecialchars($p['travel_keywords'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label small">Bloquear palabras (comas)</label>
            <input class="form-control form-control-sm" name="blocked_keywords" value="<?= htmlspecialchars($p['blocked_keywords'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label small">Cambiar imagen (opcional)</label>
            <input type="file" class="form-control form-control-sm" name="image" accept="image/*"></div>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="act-<?= (int)$p['id'] ?>" <?= $p['active'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="act-<?= (int)$p['id'] ?>">Activa</label></div></div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-save"></i> Guardar cambios</button></div>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
