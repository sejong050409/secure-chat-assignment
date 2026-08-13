<?php
namespace SecureChat;

use PDO;

final class MessageRepository
{
    public static function get(int $messageId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.id, m.sender_id, m.receiver_id, m.type, m.body, m.created_at,
                    a.id AS attachment_id, a.original_name, a.mime_type, a.file_size
             FROM messages m
             LEFT JOIN attachments a ON a.message_id = m.id
             WHERE m.id = ?'
        );
        $stmt->execute([$messageId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function history(int $a, int $b, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT m.id, m.sender_id, m.receiver_id, m.type, m.body, m.created_at,
                       a.id AS attachment_id, a.original_name, a.mime_type, a.file_size
                FROM messages m
                LEFT JOIN attachments a ON a.message_id = m.id
                WHERE (m.sender_id = :a AND m.receiver_id = :b)
                   OR (m.sender_id = :b2 AND m.receiver_id = :a2)
                ORDER BY m.id DESC
                LIMIT ' . $limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['a' => $a, 'b' => $b, 'b2' => $b, 'a2' => $a]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
