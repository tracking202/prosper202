<?php
declare(strict_types=1);

/**
 * Server Messaging Client
 *
 * Handles fetching, storing, and managing custom messages sent from the
 * central server (my.tracking202.com) to individual Prosper202 installations.
 * Replaces the previous Intercom integration.
 */
class ServerMessaging
{
    private ?\mysqli $db;
    private string $baseUrl;
    private int $timeout = 10;
    private int $syncInterval = 900; // 15 minutes

    public function __construct()
    {
        try {
            $database = DB::getInstance();
            $this->db = $database->getConnection();
        } catch (Exception $e) {
            error_log('ServerMessaging: Database connection failed: ' . $e->getMessage());
            $this->db = null;
        }

        $this->baseUrl = defined('DASHBOARD_API_URL') ? DASHBOARD_API_URL : 'https://my.tracking202.com/api/v1';
    }

    /**
     * Get the install_hash for the current installation.
     * Falls back to the primary user (user_id=1) if no session.
     */
    private function getInstallHash(): ?string
    {
        if ($this->db === null) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? 1;
        $stmt = $this->db->prepare('SELECT install_hash FROM 202_users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['install_hash'] ?? null;
    }

    /**
     * Get the customer API key for authenticated requests.
     */
    private function getApiKey(): ?string
    {
        if ($this->db === null) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? 1;
        $stmt = $this->db->prepare('SELECT p202_customer_api_key FROM 202_users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['p202_customer_api_key'] ?? null;
    }

    /**
     * Sync messages from the central server.
     * Fetches new messages and stores them locally.
     *
     * @return bool True if sync succeeded
     */
    public function syncMessages(): bool
    {
        if ($this->db === null) {
            return false;
        }

        // Check if sync is needed
        if (!$this->isSyncNeeded()) {
            return true;
        }

        $installHash = $this->getInstallHash();
        if (empty($installHash)) {
            error_log('ServerMessaging: No install_hash found, cannot sync');
            return false;
        }

        $this->updateSyncStatus('start');

        $messages = $this->fetchFromServer($installHash);
        if ($messages === null) {
            $this->updateSyncStatus('error', 'Failed to fetch messages from server');
            return false;
        }

        $stored = $this->storeMessages($messages);
        if ($stored) {
            $this->updateSyncStatus('success');
            return true;
        }

        $this->updateSyncStatus('error', 'Failed to store messages');
        return false;
    }

    /**
     * Check if a sync is needed based on the configured interval.
     */
    private function isSyncNeeded(): bool
    {
        if ($this->db === null) {
            return false;
        }

        $result = $this->db->query('SELECT last_success FROM 202_server_messages_sync WHERE id = 1 LIMIT 1');
        if (!$result || $result->num_rows === 0) {
            return true;
        }

        $row = $result->fetch_assoc();
        $result->close();

        if (empty($row['last_success'])) {
            return true;
        }

        return ((int) $row['last_success'] + $this->syncInterval) < time();
    }

    /**
     * Fetch messages from the central server API.
     *
     * Expected API: GET /api/v1/server-messages/{install_hash}
     * Headers: X-P202-Api-Key, X-P202-Version
     *
     * @param string $installHash Installation identifier
     * @return array|null Array of message objects or null on failure
     */
    private function fetchFromServer(string $installHash): ?array
    {
        $url = $this->baseUrl . '/server-messages/' . urlencode($installHash);
        $apiKey = $this->getApiKey();

        $ch = curl_init();
        if ($ch === false) {
            error_log('ServerMessaging: Failed to initialize cURL');
            return null;
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-P202-Version: ' . (defined('PROSPER202_VERSION') ? PROSPER202_VERSION : 'unknown'),
        ];

        if (!empty($apiKey)) {
            $headers[] = 'X-P202-Api-Key: ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Prosper202-ServerMessaging/' . (defined('PROSPER202_VERSION') ? PROSPER202_VERSION : '1.0'),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            error_log("ServerMessaging: cURL error: {$error}");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("ServerMessaging: HTTP {$httpCode} from server");
            return null;
        }

        if (!is_string($response) || $response === '') {
            error_log('ServerMessaging: Empty response from server');
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('ServerMessaging: JSON decode error: ' . json_last_error_msg());
            return null;
        }

        // Support both {data: [...]} wrapper and plain array
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (is_array($decoded) && !isset($decoded['data'])) {
            return $decoded;
        }

        return null;
    }

    /**
     * Store fetched messages in the local database.
     * Uses UPSERT to avoid duplicates on the unique message_id.
     *
     * @param array $messages Array of message data from the server
     * @return bool True if all messages stored successfully
     */
    private function storeMessages(array $messages): bool
    {
        if ($this->db === null) {
            return false;
        }

        $sql = "INSERT INTO 202_server_messages
                (message_id, type, title, body, action_url, action_label, priority, icon, expires_at, published_at, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                title = VALUES(title),
                body = VALUES(body),
                action_url = VALUES(action_url),
                action_label = VALUES(action_label),
                priority = VALUES(priority),
                icon = VALUES(icon),
                expires_at = VALUES(expires_at),
                published_at = VALUES(published_at)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('ServerMessaging: Failed to prepare insert statement: ' . $this->db->error);
            return false;
        }

        $now = time();
        $allSuccess = true;

        foreach ($messages as $msg) {
            if (!is_array($msg) || empty($msg['id'])) {
                continue;
            }

            $messageId = (string) $msg['id'];
            $type = $msg['type'] ?? 'info';
            $title = $msg['title'] ?? '';
            $body = $msg['body'] ?? '';
            $actionUrl = $msg['action_url'] ?? null;
            $actionLabel = $msg['action_label'] ?? null;
            $priority = isset($msg['priority']) ? (int) $msg['priority'] : 0;
            $icon = $msg['icon'] ?? null;
            $expiresAt = !empty($msg['expires_at']) ? (int) strtotime((string) $msg['expires_at']) : null;
            $publishedAt = !empty($msg['published_at']) ? (int) strtotime((string) $msg['published_at']) : $now;

            $stmt->bind_param(
                'ssssssissii',
                $messageId,
                $type,
                $title,
                $body,
                $actionUrl,
                $actionLabel,
                $priority,
                $icon,
                $expiresAt,
                $publishedAt,
                $now
            );

            if (!$stmt->execute()) {
                error_log('ServerMessaging: Failed to insert message ' . $messageId . ': ' . $stmt->error);
                $allSuccess = false;
            }
        }

        $stmt->close();

        // Clean up expired messages
        $this->cleanExpiredMessages();

        return $allSuccess;
    }

    /**
     * Remove expired messages from the local database.
     */
    private function cleanExpiredMessages(): void
    {
        if ($this->db === null) {
            return;
        }

        $now = time();
        $stmt = $this->db->prepare('DELETE FROM 202_server_messages WHERE expires_at IS NOT NULL AND expires_at < ?');
        if ($stmt) {
            $stmt->bind_param('i', $now);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Get active (non-dismissed, non-expired) messages for display.
     *
     * @param int $limit Maximum number of messages to return
     * @return array Array of message rows
     */
    public function getMessages(int $limit = 20): array
    {
        if ($this->db === null) {
            return [];
        }

        $now = time();
        $sql = "SELECT * FROM 202_server_messages
                WHERE is_dismissed = 0
                AND (expires_at IS NULL OR expires_at > ?)
                ORDER BY priority DESC, published_at DESC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $now, $limit);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        return $messages;
    }

    /**
     * Get count of unread, non-dismissed messages.
     *
     * @return int Number of unread messages
     */
    public function getUnreadCount(): int
    {
        if ($this->db === null) {
            return 0;
        }

        $now = time();
        $sql = "SELECT COUNT(*) AS cnt FROM 202_server_messages
                WHERE is_read = 0 AND is_dismissed = 0
                AND (expires_at IS NULL OR expires_at > ?)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $now);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Mark a message as read.
     *
     * @param string $messageId The server-assigned message ID
     * @return bool True if the message was marked as read
     */
    public function markAsRead(string $messageId): bool
    {
        if ($this->db === null) {
            return false;
        }

        $now = time();
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        $stmt = $this->db->prepare(
            'UPDATE 202_server_messages SET is_read = 1, read_at = ?, read_by_user_id = ? WHERE message_id = ? AND is_read = 0'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iis', $now, $userId, $messageId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Dismiss a message so it no longer appears.
     *
     * @param string $messageId The server-assigned message ID
     * @return bool True if the message was dismissed
     */
    public function dismissMessage(string $messageId): bool
    {
        if ($this->db === null) {
            return false;
        }

        $now = time();
        $stmt = $this->db->prepare(
            'UPDATE 202_server_messages SET is_dismissed = 1, dismissed_at = ? WHERE message_id = ? AND is_dismissed = 0'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('is', $now, $messageId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Mark all visible messages as read.
     *
     * @return int Number of messages marked as read
     */
    public function markAllAsRead(): int
    {
        if ($this->db === null) {
            return 0;
        }

        $now = time();
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        $stmt = $this->db->prepare(
            'UPDATE 202_server_messages SET is_read = 1, read_at = ?, read_by_user_id = ?
             WHERE is_read = 0 AND is_dismissed = 0 AND (expires_at IS NULL OR expires_at > ?)'
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('iii', $now, $userId, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Update sync tracking status.
     */
    private function updateSyncStatus(string $status, ?string $error = null): void
    {
        if ($this->db === null) {
            return;
        }

        $now = time();

        if ($status === 'start') {
            $stmt = $this->db->prepare('UPDATE 202_server_messages_sync SET last_sync = ? WHERE id = 1');
            if ($stmt) {
                $stmt->bind_param('i', $now);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($status === 'success') {
            $stmt = $this->db->prepare('UPDATE 202_server_messages_sync SET last_success = ?, error_count = 0, last_error = NULL WHERE id = 1');
            if ($stmt) {
                $stmt->bind_param('i', $now);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($status === 'error') {
            $stmt = $this->db->prepare('UPDATE 202_server_messages_sync SET error_count = error_count + 1, last_error = ? WHERE id = 1');
            if ($stmt) {
                $stmt->bind_param('s', $error);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}
