<?php
require_once __DIR__ . '/Database.php';

class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, username, password_hash, role, active FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'])) {
            usleep(300000); // pequeña demora contra fuerza bruta / timing attacks
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? null) === 'admin';
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('Acceso restringido a administradores.');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
