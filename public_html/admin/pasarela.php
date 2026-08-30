<?php
// admin/pasarela.php — Gestión del slider de la pasarela (index.php)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/seguridad.php';
require_once __DIR__ . '/../includes/imagenes.php';
requireLogin();
requireAdmin();

$msg   = '';
$error = '';

$imgDir = realpath(__DIR__ . '/../assets/img') . DIRECTORY_SEPARATOR;

// ── Acciones ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    csrfRequire(); // <-- LA FUNCIÓN CORRECTA
    $accion = $_POST['accion'];

    // SUBIR / CREAR
    if ($accion === 'subir') {
        if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al recibir el archivo.';
        } else {
            $file     = $_FILES['imagen'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['jpg','jpeg','png','webp','gif'];
            $maxBytes = 5 * 1024 * 1024;

            if (!in_array($ext, $allowed)) {
                $error = 'Solo se permiten imágenes JPG, PNG, WEBP o GIF.';
            } elseif ($file['size'] > $maxBytes) {
                $error = 'La imagen supera el límite de 5 MB.';
            } else {
                $nombre  = 'slide_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destino = $imgDir . $nombre;

                $tag         = trim($_POST['tag'] ?? '');
                $titulo      = trim($_POST['titulo'] ?? '');
                $subtitulo   = trim($_POST['subtitulo'] ?? '');
                $descripcion = trim($_POST['descripcion'] ?? '');
                $botonTexto  = trim($_POST['boton_texto'] ?? '');
                $botonLink   = trim($_POST['boton_link'] ?? '');
                $accentFrom  = trim($_POST['accent_from'] ?? '');
                $accentTo    = trim($_POST['accent_to'] ?? '');

                if (move_uploaded_file($file['tmp_name'], $destino)) {
                    optimizarImagen($destino, 900, 82); // sliders más anchos → 900px
                    $pdo->prepare("
                        INSERT INTO sliders
                            (tag, titulo, subtitulo, descripcion, boton_texto, boton_link, imagen, accent_from, accent_to, estado)
                        VALUES (?,?,?,?,?,?,?,?,?,'activo')
                    ")->execute([
                        $tag ?: null, $titulo ?: null, $subtitulo ?: null, $descripcion ?: null,
                        $botonTexto ?: null, $botonLink ?: null, $nombre,
                        $accentFrom ?: null, $accentTo ?: null
                    ]);
                    $msg = '✓ Imagen agregada al slider correctamente.';
                } else {
                    $error = 'No se pudo mover el archivo. Verifica permisos en assets/img/';
                }
            }
        }
    }

    // TOGGLE ESTADO
    if ($accion === 'toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE sliders SET estado = IF(estado='activo','inactivo','activo') WHERE id=?")->execute([$id]);
        header('Location: pasarela.php');
        exit;
    }

    // EDITAR
    if ($accion === 'editar') {
        $id          = (int)$_POST['id'];
        $tag         = trim($_POST['tag'] ?? '');
        $titulo      = trim($_POST['titulo'] ?? '');
        $subtitulo   = trim($_POST['subtitulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $botonTexto  = trim($_POST['boton_texto'] ?? '');
        $botonLink   = trim($_POST['boton_link'] ?? '');
        $accentFrom  = trim($_POST['accent_from'] ?? '');
        $accentTo    = trim($_POST['accent_to'] ?? '');

        $pdo->prepare("
            UPDATE sliders SET
                tag=?, titulo=?, subtitulo=?, descripcion=?,
                boton_texto=?, boton_link=?, accent_from=?, accent_to=?
            WHERE id=?
        ")->execute([
            $tag ?: null, $titulo ?: null, $subtitulo ?: null, $descripcion ?: null,
            $botonTexto ?: null, $botonLink ?: null, $accentFrom ?: null, $accentTo ?: null,
            $id
        ]);

        // Si subieron una imagen nueva
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['imagen'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];

            if (!in_array($ext, $allowed)) {
                $error = 'Formato de imagen no permitido.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'La imagen supera los 5 MB.';
            } else {
                $nombre  = 'slide_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destino = $imgDir . $nombre;

                if (move_uploaded_file($file['tmp_name'], $destino)) {
                    optimizarImagen($destino, 900, 82); // sliders más anchos → 900px
                    // Eliminar imagen anterior
                    $row = $pdo->prepare("SELECT imagen FROM sliders WHERE id=?");
                    $row->execute([$id]);
                    $old = $row->fetch();
                    if ($old && !empty($old['imagen'])) {
                        $oldPath = $imgDir . $old['imagen'];
                        if (file_exists($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    $pdo->prepare("UPDATE sliders SET imagen=? WHERE id=?")->execute([$nombre, $id]);
                    $msg = '✓ Slide actualizado con nueva imagen.';
                } else {
                    $error = 'No se pudo mover la imagen. Verifica permisos en assets/img/';
                }
            }
        } else {
            if (empty($error)) {
                $msg = '✓ Slide actualizado.';
            }
        }
    }

    // ELIMINAR
    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        $row = $pdo->prepare("SELECT imagen FROM sliders WHERE id=?");
        $row->execute([$id]);
        $slide = $row->fetch();
        if ($slide) {
            if (!empty($slide['imagen'])) {
                $path = $imgDir . $slide['imagen'];
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $pdo->prepare("DELETE FROM sliders WHERE id=?")->execute([$id]);
            $msg = '🗑️ Slide eliminado.';
        }
    }
}

// ── Leer slides ────────────────────────────────────────────────────────────
$slides = $pdo->query("SELECT * FROM sliders ORDER BY id ASC")->fetchAll();
$csrf   = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pasarela / Slider — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0d0d0d;--surface:#161616;--surface2:#1e1e1e;--surface3:#262626;
  --border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);
  --accent:#7c6dfa;--accent2:#f472b6;
  --text:#ffffff;--text2:#a3a3a3;--text3:#555;
  --success:#1db954;--danger:#ef4444;--warning:#f59e0b;
  --r-md:10px;--r-lg:16px;--r-xl:22px;
  --ease:cubic-bezier(.4,0,.2,1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:32px;-webkit-font-smoothing:antialiased}

h1{font-size:22px;font-weight:800;letter-spacing:-.5px;margin-bottom:4px}
.sub{font-size:13px;color:var(--text3);margin-bottom:28px}

.alert{padding:12px 16px;border-radius:var(--r-md);font-size:13px;font-weight:600;margin-bottom:20px}
.alert.ok {background:rgba(29,185,84,.1);border:1px solid rgba(29,185,84,.3);color:var(--success)}
.alert.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--danger)}

.slides-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-bottom:40px}
.slide-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);
  overflow:hidden;transition:border-color .2s;
}
.slide-card:hover{border-color:var(--border2)}
.slide-card.inactiva{opacity:.5}
.slide-thumb-wrap{position:relative;background:#111}
.slide-thumb{width:100%;height:160px;object-fit:cover;object-position:center;display:block}
.slide-tag-badge{
  position:absolute;top:10px;left:10px;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);
  color:#fff;font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px;letter-spacing:.3px
}
.slide-body{padding:14px 16px}
.slide-titulo{font-size:14px;font-weight:700;color:var(--text);margin-bottom:3px}
.slide-sub{font-size:12px;color:var(--text2);margin-bottom:8px;line-height:1.4}
.slide-meta{font-size:11px;color:var(--text3);margin-bottom:12px;word-break:break-all}
.slide-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{padding:7px 14px;border-radius:var(--r-md);font-size:12px;font-weight:700;cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:all .2s}
.btn-toggle{background:rgba(245,158,11,.1);color:var(--warning);border:1px solid rgba(245,158,11,.25)}
.btn-toggle:hover{background:rgba(245,158,11,.2)}
.btn-toggle.activo{background:rgba(29,185,84,.1);color:var(--success);border-color:rgba(29,185,84,.25)}
.btn-toggle.activo:hover{background:rgba(29,185,84,.2)}
.btn-edit{background:rgba(124,109,250,.1);color:var(--accent);border:1px solid rgba(124,109,250,.25)}
.btn-edit:hover{background:rgba(124,109,250,.2)}
.btn-del{background:rgba(239,68,68,.08);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
.btn-del:hover{background:rgba(239,68,68,.18)}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:28px;margin-bottom:32px}
.card-title{font-size:15px;font-weight:800;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:600px){
  body{padding:16px 12px}
  h1{font-size:18px}
  .sub{font-size:12px;margin-bottom:16px}
  .slides-grid{grid-template-columns:1fr;gap:12px}
  .slide-thumb{height:140px}
  .slide-body{padding:12px 14px}
  .slide-actions{flex-direction:column;gap:6px}
  .slide-actions form,.slide-actions button{width:100%}
  .btn{width:100%;padding:10px;text-align:center;font-size:13px}
  .card{padding:18px;border-radius:16px}
  .card-title{font-size:14px;margin-bottom:14px}
  .form-grid{grid-template-columns:1fr}
  .file-drop{padding:20px}
  .btn-submit{width:100%;padding:14px;font-size:14px}
  .modal{padding:20px;border-radius:18px;max-width:100%}
  .modal h2{font-size:15px}
  .field-row-2{grid-template-columns:1fr}
  .modal-footer{flex-direction:column}
  .modal-footer button{width:100%;padding:12px}
  .overlay{padding:10px;align-items:flex-end}
  .overlay .modal{border-radius:18px 18px 0 0;max-height:92vh}
}
label{font-size:12px;font-weight:600;color:var(--text2);display:block;margin-bottom:5px}
input[type=text],textarea{
  width:100%;background:var(--surface2);border:1px solid var(--border2);
  border-radius:var(--r-md);padding:10px 14px;color:var(--text);
  font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s
}
textarea{resize:vertical;min-height:64px}
input[type=text]:focus,textarea:focus{border-color:var(--accent)}
input[type=color]{
  width:100%;height:40px;border:1px solid var(--border2);border-radius:var(--r-md);
  background:var(--surface2);cursor:pointer;padding:3px
}
.file-drop{
  border:2px dashed var(--border2);border-radius:var(--r-lg);
  padding:28px;text-align:center;cursor:pointer;transition:all .2s;
  background:var(--surface2);color:var(--text2);font-size:13px
}
.file-drop:hover,.file-drop.over{border-color:var(--accent);background:rgba(124,109,250,.05);color:var(--accent)}
.file-drop.ready{border-color:var(--success);color:var(--success);border-style:solid}
.file-drop .icon{font-size:32px;margin-bottom:8px}
.file-drop input{display:none}
.btn-submit{
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff;border:none;border-radius:var(--r-md);
  font-family:'Inter',sans-serif;font-size:14px;font-weight:800;
  padding:13px 30px;cursor:pointer;transition:all .2s;margin-top:16px
}
.btn-submit:hover{opacity:.88;transform:translateY(-1px)}

.overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;
  display:flex;align-items:center;justify-content:center;padding:20px;
  opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(8px)
}
.overlay.open{opacity:1;pointer-events:all}
.modal{
  background:var(--surface);border:1px solid var(--border2);border-radius:var(--r-xl);
  padding:28px;width:100%;max-width:480px;max-height:88vh;overflow-y:auto;
  transform:scale(.95);transition:transform .25s var(--ease)
}
.overlay.open .modal{transform:scale(1)}
.modal h2{font-size:16px;font-weight:800;margin-bottom:18px}
.modal-close{
  float:right;background:var(--surface2);border:none;color:var(--text2);
  width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;
  display:inline-flex;align-items:center;justify-content:center
}
.modal-footer{margin-top:20px;display:flex;gap:10px;justify-content:flex-end}
.btn-save{background:var(--accent);color:#fff;border:none;border-radius:var(--r-md);padding:10px 22px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer}
.btn-cancel{background:var(--surface2);color:var(--text2);border:1px solid var(--border2);border-radius:var(--r-md);padding:10px 16px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer}
.field-row{margin-bottom:12px}
.field-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.modal-current-img{width:100%;height:120px;object-fit:cover;border-radius:10px;margin-bottom:12px;border:1px solid var(--border2)}
.replace-label{font-size:11px;color:var(--text3);margin-bottom:6px;display:block}

.empty{text-align:center;padding:40px;color:var(--text3);font-size:14px}
.badge-estado{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.badge-activo{background:rgba(29,185,84,.12);color:var(--success)}
.badge-inactivo{background:rgba(239,68,68,.1);color:var(--danger)}
</style>
</head>
<body>

<h1>🖼️ Pasarela / Slider</h1>
<p class="sub">Gestiona las imágenes y textos que aparecen en el slider del inicio.</p>

<?php if ($msg):  ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error):?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── SLIDES ACTUALES ── -->
<?php if (empty($slides)): ?>
  <div class="empty">No hay slides aún. ¡Agrega el primero abajo! 👇</div>
<?php else: ?>
<div class="slides-grid">
  <?php foreach ($slides as $sl): ?>
  <div class="slide-card <?= $sl['estado'] === 'inactivo' ? 'inactiva' : '' ?>">
    <div class="slide-thumb-wrap">
      <img class="slide-thumb"
           src="../assets/img/<?= htmlspecialchars($sl['imagen']) ?>"
           alt="<?= htmlspecialchars($sl['titulo'] ?? '') ?>"
           onerror="this.src='../assets/img/logo.png'">
      <?php if (!empty($sl['tag'])): ?><span class="slide-tag-badge"><?= htmlspecialchars($sl['tag']) ?></span><?php endif; ?>
    </div>
    <div class="slide-body">
      <span class="badge-estado badge-<?= $sl['estado'] ?>"><?= $sl['estado'] ?></span>
      <div class="slide-titulo"><?= htmlspecialchars($sl['titulo'] ?: '(Sin título)') ?></div>
      <?php if (!empty($sl['subtitulo'])): ?><div class="slide-sub"><?= htmlspecialchars($sl['subtitulo']) ?></div><?php endif; ?>
      <div class="slide-meta">
        <?= htmlspecialchars($sl['imagen']) ?>
        <?php if (!empty($sl['boton_link'])): ?> · <a href="<?= htmlspecialchars($sl['boton_link']) ?>" target="_blank" style="color:var(--accent)">link ↗</a><?php endif; ?>
      </div>
      <div class="slide-actions">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="accion" value="toggle">
          <input type="hidden" name="id" value="<?= $sl['id'] ?>">
          <button type="submit" class="btn btn-toggle <?= $sl['estado'] === 'activo' ? 'activo' : '' ?>">
            <?= $sl['estado'] === 'activo' ? '✓ Activo' : '✗ Inactivo' ?>
          </button>
        </form>
        <button class="btn btn-edit" onclick='abrirEditar(<?= json_encode($sl, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
          ✏️ Editar
        </button>
        <form method="post" onsubmit="return confirmarEliminarSlide(this);" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?= $sl['id'] ?>">
          <button type="submit" class="btn btn-del">🗑️ Eliminar</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── AGREGAR NUEVA IMAGEN ── -->
<div class="card">
  <div class="card-title">➕ Agregar nuevo slide</div>
  <form method="post" enctype="multipart/form-data" id="formSubir">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="accion" value="subir">

    <div class="file-drop" id="dropZone" onclick="document.getElementById('fileInput').click()">
      <div class="icon">📷</div>
      <div id="dropText">Haz clic o arrastra una imagen aquí</div>
      <div style="font-size:11px;color:var(--text3);margin-top:4px">JPG, PNG, WEBP, GIF · Máx 5 MB</div>
      <input type="file" id="fileInput" name="imagen" accept="image/*" required>
    </div>

    <img id="previewImg" style="display:none;max-height:180px;border-radius:12px;margin-top:12px;border:1px solid var(--border2)" alt="preview">

    <div class="form-grid" style="margin-top:16px">
      <div>
        <label>Tag (etiqueta pequeña)</label>
        <input type="text" name="tag" placeholder="Ej: OFERTA">
      </div>
      <div>
        <label>Título</label>
        <input type="text" name="titulo" placeholder="Ej: Netflix al mejor precio">
      </div>
      <div style="grid-column:1/-1">
        <label>Subtítulo</label>
        <input type="text" name="subtitulo" placeholder="Ej: Disfruta sin límites">
      </div>
      <div style="grid-column:1/-1">
        <label>Descripción</label>
        <textarea name="descripcion" placeholder="Texto descriptivo del slide"></textarea>
      </div>
      <div>
        <label>Texto del botón</label>
        <input type="text" name="boton_texto" placeholder="Ej: Comprar ahora">
      </div>
      <div>
        <label>Link del botón</label>
        <input type="text" name="boton_link" placeholder="Ej: registro.php">
      </div>
      <div>
        <label>Color inicial (gradiente)</label>
        <input type="color" name="accent_from" value="#7c6dfa">
      </div>
      <div>
        <label>Color final (gradiente)</label>
        <input type="color" name="accent_to" value="#f472b6">
      </div>
    </div>

    <button type="submit" class="btn-submit">Subir slide →</button>
  </form>
</div>

<!-- ── MODAL CONFIRMAR ELIMINAR ── -->
<div class="overlay" id="overlayConfirm">
  <div class="modal" style="max-width:380px;text-align:center">
    <div style="font-size:34px;margin-bottom:8px">🗑️</div>
    <h2 style="margin-bottom:8px">Eliminar slide</h2>
    <p style="color:var(--text2);font-size:13px;line-height:1.5;margin-bottom:22px">¿Seguro que quieres eliminar este slide? Esta acción no se puede deshacer.</p>
    <div class="modal-footer" style="justify-content:center">
      <button type="button" class="btn-cancel" onclick="cerrarConfirm()">Cancelar</button>
      <button type="button" class="btn-save" id="confirmDelOk" style="background:var(--danger)">Eliminar</button>
    </div>
  </div>
</div>

<!-- ── MODAL EDITAR ── -->
<div class="overlay" id="overlayEditar">
  <div class="modal">
    <button class="modal-close" onclick="cerrarModal()">✕</button>
    <h2>✏️ Editar slide</h2>
    <img class="modal-current-img" id="editImgPreview" src="" alt="">
    <form method="post" enctype="multipart/form-data" id="formEditar">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="editId">

      <div class="field-row">
        <label class="replace-label">Reemplazar imagen (opcional)</label>
        <input type="file" name="imagen" accept="image/*" style="font-size:12px;color:var(--text2)">
      </div>

      <div class="field-row-2">
        <div>
          <label>Tag</label>
          <input type="text" name="tag" id="editTag">
        </div>
        <div>
          <label>Título</label>
          <input type="text" name="titulo" id="editTitulo">
        </div>
      </div>
      <div class="field-row">
        <label>Subtítulo</label>
        <input type="text" name="subtitulo" id="editSubtitulo">
      </div>
      <div class="field-row">
        <label>Descripción</label>
        <textarea name="descripcion" id="editDescripcion"></textarea>
      </div>
      <div class="field-row-2">
        <div>
          <label>Texto del botón</label>
          <input type="text" name="boton_texto" id="editBotonTexto">
        </div>
        <div>
          <label>Link del botón</label>
          <input type="text" name="boton_link" id="editBotonLink">
        </div>
      </div>
      <div class="field-row-2">
        <div>
          <label>Color inicial</label>
          <input type="color" name="accent_from" id="editAccentFrom">
        </div>
        <div>
          <label>Color final</label>
          <input type="color" name="accent_to" id="editAccentTo">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
        <button type="submit" class="btn-save">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── File drag & drop ──────────────────────────────────────────────────
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const preview   = document.getElementById('previewImg');
const dropText  = document.getElementById('dropText');

fileInput.addEventListener('change', () => showPreview(fileInput.files[0]));
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('over'); });
dropZone.addEventListener('dragleave', ()=> dropZone.classList.remove('over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault(); dropZone.classList.remove('over');
  const f = e.dataTransfer.files[0];
  if (f) { fileInput.files = e.dataTransfer.files; showPreview(f); }
});
function showPreview(file) {
  if (!file) return;
  dropText.textContent = '✓ ' + file.name;
  dropZone.classList.add('ready');
  const reader = new FileReader();
  reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
  reader.readAsDataURL(file);
}

// ── Modal editar ──────────────────────────────────────────────────────
function abrirEditar(sl) {
  document.getElementById('editId').value          = sl.id;
  document.getElementById('editTag').value         = sl.tag || '';
  document.getElementById('editTitulo').value      = sl.titulo || '';
  document.getElementById('editSubtitulo').value   = sl.subtitulo || '';
  document.getElementById('editDescripcion').value = sl.descripcion || '';
  document.getElementById('editBotonTexto').value  = sl.boton_texto || '';
  document.getElementById('editBotonLink').value   = sl.boton_link || '';
  document.getElementById('editAccentFrom').value  = sl.accent_from || '#7c6dfa';
  document.getElementById('editAccentTo').value    = sl.accent_to || '#f472b6';
  document.getElementById('editImgPreview').src    = '../assets/img/' + sl.imagen;
  document.getElementById('overlayEditar').classList.add('open');
}
function cerrarModal() {
  document.getElementById('overlayEditar').classList.remove('open');
}
document.getElementById('overlayEditar').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});

// ── Confirmación de eliminar (reemplaza el confirm() nativo) ──
let _delSlideForm = null;
function confirmarEliminarSlide(form) {
  _delSlideForm = form;
  document.getElementById('overlayConfirm').classList.add('open');
  return false; // bloquea el envío hasta confirmar
}
function cerrarConfirm() {
  document.getElementById('overlayConfirm').classList.remove('open');
  _delSlideForm = null;
}
document.getElementById('confirmDelOk').addEventListener('click', function() {
  const f = _delSlideForm;
  cerrarConfirm();
  if (f) f.submit();
});
document.getElementById('overlayConfirm').addEventListener('click', function(e) {
  if (e.target === this) cerrarConfirm();
});
</script>
</body>
</html>