<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Encryption.php';
require_once __DIR__ . '/../services/CodeExtractor.php';

\Auth::requireAdmin();
$db = Database::get();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \Csrf::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $stmt = $db->prepare(
            'INSERT INTO mailboxes (email, imap_host, imap_port, imap_user, password_encrypted, service_type, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            trim($_POST['email']),
            trim($_POST['imap_host']) ?: 'imap.gmail.com',
            (int)($_POST['imap_port'] ?: 993),
            trim($_POST['imap_user']),
            Encryption::encrypt($_POST['imap_password']),
            trim($_POST['service_type']),
        ]);
        $message = 'Cuenta agregada correctamente.';
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        if (!empty($_POST['imap_password'])) {
            $stmt = $db->prepare(
                'UPDATE mailboxes SET email=?, imap_host=?, imap_port=?, imap_user=?, password_encrypted=?, service_type=? WHERE id=?'
            );
            $stmt->execute([
                trim($_POST['email']), trim($_POST['imap_host']), (int)$_POST['imap_port'],
                trim($_POST['imap_user']), Encryption::encrypt($_POST['imap_password']),
                trim($_POST['service_type']), $id,
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE mailboxes SET email=?, imap_host=?, imap_port=?, imap_user=?, service_type=? WHERE id=?'
            );
            $stmt->execute([
                trim($_POST['email']), trim($_POST['imap_host']), (int)$_POST['imap_port'],
                trim($_POST['imap_user']), trim($_POST['service_type']), $id,
            ]);
        }
        $message = 'Cuenta actualizada.';
    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE mailboxes SET active = NOT active WHERE id = ?')->execute([$id]);
        $message = 'Estado actualizado.';
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM mailboxes WHERE id = ?')->execute([$id]);
        $message = 'Cuenta eliminada.';
    }
}

$mailboxes = $db->query('SELECT * FROM mailboxes ORDER BY service_type, email')->fetchAll();
$services  = CodeExtractor::availableServices(); // clave => etiqueta (plataformas activas)

/** Genera <option>s de plataformas, marcando la seleccionada. */
function serviceOptions(array $services, string $selected = ''): string
{
    $html = '';
    $found = false;
    foreach ($services as $key => $label) {
        $sel = $key === $selected ? ' selected' : '';
        if ($sel) $found = true;
        $html .= '<option value="' . htmlspecialchars($key) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    // Si la cuenta tiene un servicio que ya no existe como plataforma, lo conservamos.
    if ($selected !== '' && !$found) {
        $html .= '<option value="' . htmlspecialchars($selected) . '" selected>' . htmlspecialchars($selected) . ' (no registrada)</option>';
    }
    return $html;
}

$pageTitle = 'Admin · Cuentas de correo';
include __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-4"><i class="bi bi-envelope-at"></i> Cuentas de correo (Mailboxes)</h4>

<?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link active" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link" href="logs.php">Registros</a></li>
</ul>

<div class="card p-4 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0">Agregar nueva cuenta</h6>
    <a class="small text-secondary" data-bs-toggle="collapse" href="#ayudaCuenta" role="button">
      <i class="bi bi-question-circle"></i> ¿Cómo lleno esto?
    </a>
  </div>
  <div class="collapse mb-3" id="ayudaCuenta">
    <div class="border rounded p-3 small text-secondary" style="border-color:var(--border)!important">
      <div class="mb-1">📧 <strong>Correo / Usuario IMAP:</strong> la dirección completa donde llegan los códigos.</div>
      <div class="mb-1">🖥️ <strong>Servidor IMAP:</strong> Gmail → <code>imap.gmail.com</code> · Correo de Hostinger → <code>imap.hostinger.com</code> · Titan → <code>imap.titan.email</code> · otro → revísalo con tu proveedor. Puerto casi siempre <code>993</code>.</div>
      <div class="mb-1">🔑 <strong>Contraseña:</strong> en Gmail usa una <strong>Contraseña de aplicación</strong> (no la normal) y activa IMAP en Gmail. En otros correos, la clave normal del buzón.</div>
      <div>💡 Un mismo correo puede recibir varias plataformas: agrégalo una vez y el usuario elige cuál buscar.</div>
    </div>
  </div>
  <form method="post" class="row g-2">
    <?= \Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-3"><input class="form-control" name="email" placeholder="Correo (ej: netflix01@gmail.com)" required></div>
    <div class="col-md-2">
      <select class="form-select" name="service_type" required>
        <option value="">Plataforma...</option>
        <?= serviceOptions($services) ?>
      </select>
    </div>
    <div class="col-md-2">
      <input class="form-control" name="imap_host" placeholder="imap.gmail.com" value="imap.gmail.com">
      <div class="form-text small">Gmail: imap.gmail.com</div>
    </div>
    <div class="col-md-1"><input class="form-control" name="imap_port" placeholder="993" value="993"></div>
    <div class="col-md-2"><input class="form-control" name="imap_user" placeholder="Usuario IMAP" required></div>
    <div class="col-md-2">
      <input type="password" class="form-control" name="imap_password" placeholder="Contraseña / App Password" required>
      <div class="form-text small">Gmail: contraseña de aplicación</div>
    </div>
    <div class="col-12"><button class="btn btn-success mt-2"><i class="bi bi-plus-lg"></i> Agregar</button></div>
  </form>
</div>

<div class="table-responsive">
<table class="table table-dark table-striped align-middle">
<thead><tr><th>Email</th><th>Servicio</th><th>Host</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($mailboxes as $mb): ?>
<tr>
  <td><?= htmlspecialchars($mb['email']) ?></td>
  <td><span class="badge bg-primary"><?= htmlspecialchars($mb['service_type']) ?></span></td>
  <td><?= htmlspecialchars($mb['imap_host']) ?>:<?= (int)$mb['imap_port'] ?></td>
  <td>
    <form method="post" class="d-inline">
      <?= \Csrf::field() ?>
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="id" value="<?= (int)$mb['id'] ?>">
      <button class="btn btn-sm <?= $mb['active'] ? 'btn-success' : 'btn-secondary' ?>">
        <?= $mb['active'] ? 'Activa' : 'Inactiva' ?>
      </button>
    </form>
  </td>
  <td>
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#edit-<?= $mb['id'] ?>">
      <i class="bi bi-pencil"></i>
    </button>
    <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta cuenta?');">
      <?= \Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$mb['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
  </td>
</tr>
<tr class="collapse" id="edit-<?= $mb['id'] ?>">
  <td colspan="5">
    <form method="post" class="row g-2">
      <?= \Csrf::field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$mb['id'] ?>">
      <div class="col-md-3"><input class="form-control" name="email" value="<?= htmlspecialchars($mb['email']) ?>" required></div>
      <div class="col-md-2">
        <select class="form-select" name="service_type" required>
          <?= serviceOptions($services, $mb['service_type']) ?>
        </select>
      </div>
      <div class="col-md-2"><input class="form-control" name="imap_host" value="<?= htmlspecialchars($mb['imap_host']) ?>"></div>
      <div class="col-md-1"><input class="form-control" name="imap_port" value="<?= (int)$mb['imap_port'] ?>"></div>
      <div class="col-md-2"><input class="form-control" name="imap_user" value="<?= htmlspecialchars($mb['imap_user']) ?>"></div>
      <div class="col-md-2"><input type="password" class="form-control" name="imap_password" placeholder="Nueva contraseña (opcional)"></div>
      <div class="col-12"><button class="btn btn-sm btn-primary mt-1">Guardar cambios</button></div>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>