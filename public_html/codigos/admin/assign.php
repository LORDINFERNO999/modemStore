<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/CodeExtractor.php';

\Auth::requireAdmin();
$db = Database::get();
$message = null;

$users = $db->query("SELECT id, username FROM users WHERE role = 'user' ORDER BY username")->fetchAll();
$mailboxes = $db->query('SELECT id, email, service_type FROM mailboxes ORDER BY service_type, email')->fetchAll();
$platforms = CodeExtractor::availableServices(); // clave => etiqueta

$selectedUserId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? ($users[0]['id'] ?? 0)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \Csrf::requireValid();
    $userId = (int)$_POST['user_id'];
    $selectedIds = array_map('intval', $_POST['mailbox_ids'] ?? []);
    $selectedPlatforms = array_map('strval', $_POST['platform_keys'] ?? []);

    $db->beginTransaction();

    // 1) Cuentas (correos) que puede ver
    $db->prepare('DELETE FROM user_mailbox_access WHERE user_id = ?')->execute([$userId]);
    $stmt = $db->prepare('INSERT INTO user_mailbox_access (user_id, mailbox_id) VALUES (?, ?)');
    foreach ($selectedIds as $mid) {
        $stmt->execute([$userId, $mid]);
    }

    // 2) Plataformas que puede consultar (si no marca ninguna = todas)
    $db->prepare('DELETE FROM user_platform_access WHERE user_id = ?')->execute([$userId]);
    $stmtP = $db->prepare('INSERT INTO user_platform_access (user_id, service_key) VALUES (?, ?)');
    foreach ($selectedPlatforms as $pk) {
        if (isset($platforms[$pk])) {
            $stmtP->execute([$userId, $pk]);
        }
    }

    $db->commit();
    $message = 'Asignaciones actualizadas.';
    $selectedUserId = $userId;
}

$stmt = $db->prepare('SELECT mailbox_id FROM user_mailbox_access WHERE user_id = ?');
$stmt->execute([$selectedUserId]);
$assignedIds = array_column($stmt->fetchAll(), 'mailbox_id');

$stmt = $db->prepare('SELECT service_key FROM user_platform_access WHERE user_id = ?');
$stmt->execute([$selectedUserId]);
$assignedPlatforms = array_column($stmt->fetchAll(), 'service_key');

$pageTitle = 'Admin · Asignaciones';
include __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-4"><i class="bi bi-diagram-3"></i> Asignación de cuentas a usuarios</h4>
<?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link active" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link" href="logs.php">Registros</a></li>
</ul>

<div class="card p-4">
  <form method="get" class="mb-3">
    <label class="form-label">Selecciona usuario</label>
    <select class="form-select" name="user_id" onchange="this.form.submit()">
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $u['id'] == $selectedUserId ? 'selected' : '' ?>>
          <?= htmlspecialchars($u['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <form method="post">
    <?= \Csrf::field() ?>
    <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">

    <!-- 1) Cuentas (correos) -->
    <h6 class="mb-2"><i class="bi bi-envelope"></i> Correos que puede ver</h6>
    <div class="row mb-4">
      <?php if (empty($mailboxes)): ?>
        <div class="text-secondary small">No hay correos registrados todavía.</div>
      <?php endif; ?>
      <?php foreach ($mailboxes as $mb): ?>
        <div class="col-md-4 mb-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="mailbox_ids[]" value="<?= (int)$mb['id'] ?>"
              id="mb-<?= $mb['id'] ?>" <?= in_array($mb['id'], $assignedIds) ? 'checked' : '' ?>>
            <label class="form-check-label" for="mb-<?= $mb['id'] ?>">
              <span class="badge bg-primary"><?= htmlspecialchars($mb['service_type']) ?></span>
              <?= htmlspecialchars($mb['email']) ?>
            </label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- 2) Plataformas permitidas -->
    <h6 class="mb-1"><i class="bi bi-collection-play"></i> Plataformas que puede consultar</h6>
    <p class="text-secondary small mb-2">
      Marca las plataformas permitidas para este usuario. <strong>Si no marcas ninguna, podrá consultar TODAS</strong>
      las plataformas de los correos que le asignaste. Útil cuando un correo recibe varias plataformas y quieres
      que el revendedor solo vea, por ejemplo, Netflix.
    </p>
    <div class="row mb-2">
      <?php foreach ($platforms as $key => $label): ?>
        <div class="col-md-3 col-6 mb-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="platform_keys[]" value="<?= htmlspecialchars($key) ?>"
              id="pf-<?= htmlspecialchars($key) ?>" <?= in_array($key, $assignedPlatforms) ? 'checked' : '' ?>>
            <label class="form-check-label" for="pf-<?= htmlspecialchars($key) ?>">
              <span class="badge bg-primary"><?= htmlspecialchars($label) ?></span>
            </label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <button class="btn btn-success mt-3"><i class="bi bi-check2-circle"></i> Guardar asignaciones</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>