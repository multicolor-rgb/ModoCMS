<?php
declare(strict_types=1);

namespace Core;

/**
 * Append-only audit trail for security-relevant admin and API operations.
 */
final class Audit
{
    /**
     * @param array<string,mixed> $meta
     */
    public static function log(string $action, ?string $target = null, array $meta = []): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO audit_log (user_id, action, target, ip_address, meta)
                VALUES (:user_id, :action, :target, :ip, :meta)
            ");
            $stmt->execute([
                ':user_id' => class_exists(Auth::class) && Auth::check() ? Auth::id() : null,
                ':action'  => substr($action, 0, 120),
                ':target'  => $target !== null ? substr($target, 0, 255) : null,
                ':ip'      => Security::clientIp(),
                ':meta'    => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            Logger::exception($e, 'Audit log write failed');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 50): array
    {
        try {
            $limit = max(1, min(500, $limit));
            $stmt = Database::getConnection()->prepare("
                SELECT a.*, u.username
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.id DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function prune(int $days = 365): void
    {
        try {
            $days = max(30, $days);
            Database::getConnection()
                ->prepare("DELETE FROM audit_log WHERE created_at < datetime('now', :window)")
                ->execute([':window' => '-' . $days . ' days']);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }
}
