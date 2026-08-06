<?php
// includes/seguridad.php
// Helpers de seguridad (CSRF) y de configuración (tabla `configuracion`).
require_once __DIR__ . '/config.php';

/**
 * Devuelve el token CSRF de la sesión (lo crea si no existe).
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Imprime un <input hidden> con el token CSRF, listo para meter en un <form>.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Valida el token recibido contra el de la sesión.
 * Busca el token en POST (csrf_token), en el JSON del body o en la cabecera X-CSRF-Token.
 */
function csrfCheck(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? null;
        if ($token === null) {
            $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if ($hdr) {
                $token = $hdr;
            } else {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $data = json_decode($raw, true);
                    $token = $data['csrf_token'] ?? null;
                }
            }
        }
    }
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Corta la ejecución con 403 si el token CSRF no es válido.
 * $json=true responde en JSON (para endpoints ajax).
 */
function csrfRequire(bool $json = false): void {
    if (!csrfCheck()) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido. Recarga la página.']);
        } else {
            echo 'Token de seguridad inválido. Recarga la página.';
        }
        exit;
    }
}

/**
 * Lee un valor de la tabla `configuracion`. Cachea en memoria por petición.
 */
function getConfig(string $clave, ?string $default = null): ?string {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT clave, valor FROM configuracion") as $row) {
                $cache[$row['clave']] = $row['valor'];
            }
        } catch (Exception $e) {
            // Si la tabla aún no existe, devolvemos el default.
        }
    }
    return array_key_exists($clave, $cache) ? $cache[$clave] : $default;
}

/**
 * Guarda (o actualiza) un valor en la tabla `configuracion`.
 */
function setConfig(string $clave, string $valor): bool {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO configuracion (clave, valor) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
    ");
    return $stmt->execute([$clave, $valor]);
}

// ── Anti fuerza-bruta en login (por sesión) ──────────────────────────────

/**
 * Indica si el login está disponible. Devuelve [permitido, segundosRestantes].
 */
function loginThrottleCheck(): array {
    $until = $_SESSION['login_block_until'] ?? 0;
    if ($until > time()) {
        return [false, $until - time()];
    }
    return [true, 0];
}

/**
 * Registra un intento fallido; tras $max fallos bloquea $blockSecs segundos.
 */
function loginRegisterFail(int $max = 5, int $blockSecs = 300): void {
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= $max) {
        $_SESSION['login_block_until'] = time() + $blockSecs;
        $_SESSION['login_fails'] = 0;
    }
}

/**
 * Limpia los contadores de fallos (tras un login exitoso).
 */
function loginResetFails(): void {
    unset($_SESSION['login_fails'], $_SESSION['login_block_until']);
}
