<?php
require_once __DIR__ . '/../core/Database.php';

class LogService
{
    public static function record(?int $userId, ?int $mailboxId, string $result): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO query_logs (user_id, mailbox_id, result, ip) VALUES (?, ?, ?, ?)'
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt->execute([$userId, $mailboxId, $result, $ip]);
    }

    public static function recent(int $limit = 100): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT ql.*, u.username, m.email, m.service_type
             FROM query_logs ql
             LEFT JOIN users u ON u.id = ql.user_id
             LEFT JOIN mailboxes m ON m.id = ql.mailbox_id
             ORDER BY ql.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
