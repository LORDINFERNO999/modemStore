<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Database.php';

\Auth::requireAdmin();
$db = Database::get();
$message = null;
$error = null;

/**
 * ¿El usuario indicado es el único administrador activo que queda?
 * Se usa para no dejar el sistema sin ningún admin operativo.
 */
function isLastActiveAdmin(PDO $db, int $userId): bool
{
    $stmt = $db->prepare("SELECT role, active FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target || $target['role'] !== 'admin' || !$target['active']) {
        return false; // no es un admin activo, no aplica la protección
    }
    $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    return $count <= 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \Csrf::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($username === '' || $password === '') {
            $error = 'Usuario y contraseña son obligatorios.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            // Evita el error 500 por la restricción UNIQUE del username
            $check = $db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
            $check->execute([$username]);
            if ($check->fetch()) {
                $error = 'Ya existe un usuario con ese nombre.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, ?, 1)');
                $stmt->execute([$username, $hash, $role]);
                $message = 'Usuario creado.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        // No permitir desactivar al último admin activo
        if (isLastActiveAdmin($db, $id)) {
            $error = 'No puedes desactivar al único administrador activo.';
        } else {
            $db->prepare('UPDATE users SET active = NOT active WHERE id = ?')->execute([$id]);
            $message = 'Estado actualizado.';
        }
    } elseif ($action === 'reset_password') {
        $id = (int)$_POST['id'];
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            $message = 'Contraseña actualizada.';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === \Auth::id()) {
            $error = 'No puedes eliminar tu propia cuenta.';
        } elseif (isLastActiveAdmin($db, $id)) {
            $error = 'No puedes eliminar al único administrador activo.';
        } else {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'Usuario eliminado.';
        }
    }
}

$users = $db->query('SELECT * FROM users ORDER BY username')->fetchAll();

$pageTitle = 'Admin · Usuarios';
include __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-4"><i class="bi bi-people"></i> Usuarios</h4>
<?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<ul class="nav nav-pills mb-4">
  <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
  <li class="nav-item"><a class="nav-link" href="mailboxes.php">Cuentas</a></li>
  <li class="nav-item"><a class="nav-link active" href="users.php">Usuarios</a></li>
  <li class="nav-item"><a class="nav-link" href="assign.php">Asignaciones</a></li>
  <li class="nav-item"><a class="nav-link" href="platforms.php">Plataformas</a></li>
  <li class="nav-item"><a class="nav-link" href="logs.php">Registros</a></li>
</ul>

<div class="card p-4 mb-4">
  <h6 class="mb-3">Crear usuario</h6>
  <form method="post" class="row g-2">
    <?= \Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-4"><input class="form-control" name="username" placeholder="Usuario" required></div>
    <div class="col-md-4"><input type="password" class="form-control" name="password" placeholder="Contraseña" required></div>
    <div class="col-md-2">
      <select class="form-select" name="role">
        <option value="user">Usuario</option>
        <option value="admin">Administrador</option>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-success w-100">Crear</button></div>
  </form>
</div>

<table class="table table-dark table-striped align-middle">
<thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
  <td><?= htmlspecialchars($u['username']) ?></td>
  <td><span class="badge bg-info text-dark"><?= htmlspecialchars($u['role']) ?></span></td>
  <td>
    <form method="post" class="d-inline">
      <?= \Csrf::field() ?>
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button class="btn btn-sm <?= $u['active'] ? 'btn-success' : 'btn-secondary' ?>">
        <?= $u['active'] ? 'Activo' : 'Inactivo' ?>
      </button>
    </form>
  </td>
  <td>
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#pw-<?= $u['id'] ?>">
      <i class="bi bi-key"></i> Cambiar clave
    </button>
    <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este usuario?');">
      <?= \Csrf::field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <div class="collapse mt-2" id="pw-<?= $u['id'] ?>">
      <form method="post" class="d-flex gap-2">
        <?= \Csrf::field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <input type="password" class="form-control form-control-sm" name="password" placeholder="Nueva contraseña" required>
        <button class="btn btn-sm btn-primary">Guardar</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php include __DIR__ . '/../includes/footer.php'; ?>
