<?php
// includes/auth.php

require_once __DIR__ . '/config.php';

// ── Zona horaria de Colombia (UTC-5) ──
// Evita que PHP y MySQL muestren horas distintas (discrepancia en compras/pedidos).
date_default_timezone_set('America/Bogota');
if (isset($pdo) && $pdo instanceof PDO) {
    try { $pdo->exec("SET time_zone = '-05:00'"); } catch (Throwable $e) { /* silencio */ }
}

function isLoggedIn(): bool {
    return isset($_SESSION['usuario_id']);
}

function isAdmin(): bool {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    }
}

function login(string $email, string $password): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND estado = 'activo'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Anti session-fixation: nuevo ID de sesión al autenticarse
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['nombre']     = $user['nombre'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['rol']        = $user['rol'];
        $_SESSION['saldo']      = $user['saldo'];
        return ['ok' => true, 'rol' => $user['rol']];
    }
    return ['ok' => false, 'msg' => 'Correo o contraseña incorrectos'];
}

function logout(): void {
    // Limpia todas las variables de sesión
    $_SESSION = [];
    // Borra la cookie de sesión del navegador
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

function getSaldo(): float {
    global $pdo;
    $stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $row = $stmt->fetch();
    $saldo = $row ? (float)$row['saldo'] : 0.0;
    $_SESSION['saldo'] = $saldo;
    return $saldo;
}