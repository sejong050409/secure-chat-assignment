<?php
namespace SecureChat;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;

final class ChatServer implements MessageComponentInterface
{
    private SplObjectStorage $clients;
    private array $byUser = [];
    private array $rate = [];

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $origin = $conn->httpRequest->getHeaderLine('Origin');
        $expectedOrigin = getenv('APP_ORIGIN') ?: 'http://localhost:8080';
        if ($origin !== $expectedOrigin) {
            $conn->close();
            return;
        }

        parse_str($conn->httpRequest->getUri()->getQuery(), $query);
        $userId = isset($query['token']) ? Token::verify((string) $query['token']) : null;
        if (!$userId) {
            $conn->close();
            return;
        }

        $conn->userId = $userId;
        $this->clients->attach($conn);
        $this->byUser[$userId][$conn->resourceId] = $conn;
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (!$this->allowRate($from->resourceId)) {
            $this->send($from, ['type' => 'error', 'error' => 'Rate limit exceeded']);
            return;
        }
        $data = json_decode((string) $msg, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->send($from, ['type' => 'error', 'error' => 'Invalid message']);
            return;
        }

        try {
            if ($data['type'] === 'send_text') {
                $this->handleText($from, $data);
            } elseif ($data['type'] === 'announce') {
                $this->handleAnnounce($from, $data);
            }
        } catch (\Throwable $e) {
            $this->send($from, ['type' => 'error', 'error' => 'Request failed']);
        }
    }

    private function handleText(ConnectionInterface $from, array $data): void
    {
        $sender = (int) $from->userId;
        $receiver = (int) ($data['receiver_id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));
        if ($receiver <= 0 || $body === '' || mb_strlen($body) > 4000) {
            $this->send($from, ['type' => 'error', 'error' => 'Invalid message']);
            return;
        }
        if (!Auth::areFriends($sender, $receiver)) {
            $this->send($from, ['type' => 'error', 'error' => 'Not authorized']);
            return;
        }

        $stmt = Database::pdo()->prepare('INSERT INTO messages(sender_id, receiver_id, type, body) VALUES(?, ?, "text", ?)');
        $stmt->execute([$sender, $receiver, $body]);
        $message = MessageRepository::get((int) Database::pdo()->lastInsertId());
        $this->broadcastMessage($message);
    }

    private function handleAnnounce(ConnectionInterface $from, array $data): void
    {
        $messageId = (int) ($data['message_id'] ?? 0);
        $message = MessageRepository::get($messageId);
        if (!$message || (int) $message['sender_id'] !== (int) $from->userId) {
            return;
        }
        if (!Auth::areFriends((int) $message['sender_id'], (int) $message['receiver_id'])) {
            return;
        }
        $this->broadcastMessage($message);
    }

    private function broadcastMessage(array $message): void
    {
        $payload = ['type' => 'new_message', 'message' => $message];
        $this->sendToUser((int) $message['sender_id'], $payload);
        if ((int) $message['receiver_id'] !== (int) $message['sender_id']) {
            $this->sendToUser((int) $message['receiver_id'], $payload);
        }
    }

    private function sendToUser(int $userId, array $payload): void
    {
        foreach ($this->byUser[$userId] ?? [] as $conn) {
            $this->send($conn, $payload);
        }
    }

    private function send(ConnectionInterface $conn, array $payload): void
    {
        $conn->send(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function allowRate(int $resourceId): bool
    {
        $now = microtime(true);
        $window = array_filter($this->rate[$resourceId] ?? [], fn($t) => $t > $now - 10);
        if (count($window) >= 20) {
            $this->rate[$resourceId] = $window;
            return false;
        }
        $window[] = $now;
        $this->rate[$resourceId] = $window;
        return true;
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
        if (isset($conn->userId)) {
            unset($this->byUser[$conn->userId][$conn->resourceId]);
            if (empty($this->byUser[$conn->userId])) unset($this->byUser[$conn->userId]);
        }
        unset($this->rate[$conn->resourceId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }
}
