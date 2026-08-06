<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../config/init.php';

class CacheService
{
    /**
     * Devuelve un resultado cacheado si aún está dentro de la ventana de vigencia,
     * o null si no hay nada aprovechable (obliga a ir a IMAP).
     */
    public static function getFresh(int $mailboxId, string $serviceType): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT code, message_type, valid_until, fetched_at
             FROM code_cache
             WHERE mailbox_id = ? AND service_type = ?
             ORDER BY fetched_at DESC
             LIMIT 1'
        );
        $stmt->execute([$mailboxId, $serviceType]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $fetchedAt = strtotime($row['fetched_at']);
        if ((time() - $fetchedAt) > CACHE_TTL_SECONDS) {
            return null; // caché vencido, hay que refrescar contra IMAP
        }

        return $row;
    }

    public static function store(int $mailboxId, string $serviceType, ?string $code, string $type): void
    {
        $db = Database::get();
        $validUntil = $code ? date('Y-m-d H:i:s', time() + CODE_VALID_SECONDS) : null;

        // Una sola fila por (buzón, servicio): evita mezclar plataformas y que
        // code_cache crezca sin límite.
        $db->beginTransaction();
        $db->prepare('DELETE FROM code_cache WHERE mailbox_id = ? AND service_type = ?')
           ->execute([$mailboxId, $serviceType]);
        $stmt = $db->prepare(
            'INSERT INTO code_cache (mailbox_id, service_type, code, message_type, valid_until)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$mailboxId, $serviceType, $code, $type, $validUntil]);
        $db->commit();
    }

    /**
     * Segundos restantes de vigencia de un código a partir de su valid_until.
     * Nunca devuelve negativo.
     */
    public static function remainingSeconds(?string $validUntil): int
    {
        if (!$validUntil) {
            return 0;
        }
        return max(0, strtotime($validUntil) - time());
    }
}
