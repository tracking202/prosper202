<?php
declare(strict_types=1);

/**
 * Server Messaging Client
 *
 * Handles fetching, storing, and managing custom messages sent from the
 * central server (my.tracking202.com) to individual Prosper202 installations.
 * Replaces the previous Intercom integration.
 *
 * Features:
 * - Rich HTML/markdown message bodies with safe rendering
 * - Image/media attachments (hero images)
 * - Message categories with filtering
 * - Two-way replies sent back to the central server
 * - Per-user read/dismissed state (multi-user safe)
 */
class ServerMessaging
{
    private ?\mysqli $db;
    private string $baseUrl;
    private int $timeout = 10;
    private int $syncInterval = 900; // 15 minutes

    /** @var string[] Allowed HTML tags for rich message bodies */
    private const array ALLOWED_TAGS = [
        'b', 'i', 'strong', 'em', 'a', 'br', 'p', 'ul', 'ol', 'li',
        'code', 'pre', 'h4', 'h5', 'h6', 'blockquote', 'hr', 'span', 'img',
    ];

    /** @var string[] Valid message categories */
    private const array VALID_CATEGORIES = [
        'general', 'update', 'alert', 'news', 'promo',
    ];

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
     * Get the current user ID from the session.
     */
    private function getCurrentUserId(): int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;
    }

    /**
     * Get the install_hash for the current installation.
     */
    private function getInstallHash(): ?string
    {
        if ($this->db === null) {
            return null;
        }

        $userId = $this->getCurrentUserId();
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

        $userId = $this->getCurrentUserId();
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

    // =========================================================================
    // Sync
    // =========================================================================

    /**
     * Sync messages from the central server.
     */
    public function syncMessages(): bool
    {
        if ($this->db === null) {
            return false;
        }

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
     * Includes new fields: category, image_url, format.
     */
    private function storeMessages(array $messages): bool
    {
        if ($this->db === null) {
            return false;
        }

        $sql = "INSERT INTO 202_server_messages
                (message_id, type, category, title, body, action_url, action_label, priority, icon, image_url, format, expires_at, published_at, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                category = VALUES(category),
                title = VALUES(title),
                body = VALUES(body),
                action_url = VALUES(action_url),
                action_label = VALUES(action_label),
                priority = VALUES(priority),
                icon = VALUES(icon),
                image_url = VALUES(image_url),
                format = VALUES(format),
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
            $category = $msg['category'] ?? 'general';
            $title = $msg['title'] ?? '';
            $body = $msg['body'] ?? '';
            $actionUrl = $msg['action_url'] ?? null;
            $actionLabel = $msg['action_label'] ?? null;
            $priority = isset($msg['priority']) ? (int) $msg['priority'] : 0;
            $icon = $msg['icon'] ?? null;
            $imageUrl = $msg['image_url'] ?? null;
            $format = $msg['format'] ?? 'plain';
            $expiresAt = !empty($msg['expires_at']) ? (int) strtotime((string) $msg['expires_at']) : null;
            $publishedAt = !empty($msg['published_at']) ? (int) strtotime((string) $msg['published_at']) : $now;

            $stmt->bind_param(
                'sssssssisssiii',
                $messageId,
                $type,
                $category,
                $title,
                $body,
                $actionUrl,
                $actionLabel,
                $priority,
                $icon,
                $imageUrl,
                $format,
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
        $this->cleanExpiredMessages();

        return $allSuccess;
    }

    private function cleanExpiredMessages(): void
    {
        if ($this->db === null) {
            return;
        }

        $now = time();

        // Clean expired messages
        $stmt = $this->db->prepare('DELETE FROM 202_server_messages WHERE expires_at IS NOT NULL AND expires_at < ?');
        if ($stmt) {
            $stmt->bind_param('i', $now);
            $stmt->execute();
            $stmt->close();
        }

        // Clean orphaned user state rows for messages that no longer exist
        $this->db->query(
            'DELETE s FROM 202_server_message_user_state s
             LEFT JOIN 202_server_messages m ON s.message_id = m.message_id
             WHERE m.message_id IS NULL'
        );
    }

    // =========================================================================
    // Message retrieval (per-user state)
    // =========================================================================

    /**
     * Get active messages for display, with per-user read/dismissed state.
     *
     * @param int $limit Maximum messages
     * @param string|null $category Filter by category (null = all)
     * @return array Messages with user state merged in
     */
    public function getMessages(int $limit = 20, ?string $category = null): array
    {
        if ($this->db === null) {
            return [];
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        $sql = "SELECT m.*,
                    COALESCE(us.is_read, 0) AS is_read,
                    COALESCE(us.is_dismissed, 0) AS is_dismissed,
                    us.read_at,
                    us.dismissed_at
                FROM 202_server_messages m
                LEFT JOIN 202_server_message_user_state us
                    ON m.message_id = us.message_id AND us.user_id = ?
                WHERE COALESCE(us.is_dismissed, 0) = 0
                AND (m.expires_at IS NULL OR m.expires_at > ?)";

        $params = [$userId, $now];
        $types = 'ii';

        if ($category !== null && $category !== '' && $category !== 'all') {
            $sql .= " AND m.category = ?";
            $params[] = $category;
            $types .= 's';
        }

        $sql .= " ORDER BY m.priority DESC, m.published_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
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
     * Get count of unread, non-dismissed messages for the current user.
     */
    public function getUnreadCount(): int
    {
        if ($this->db === null) {
            return 0;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        $sql = "SELECT COUNT(*) AS cnt
                FROM 202_server_messages m
                LEFT JOIN 202_server_message_user_state us
                    ON m.message_id = us.message_id AND us.user_id = ?
                WHERE COALESCE(us.is_read, 0) = 0
                AND COALESCE(us.is_dismissed, 0) = 0
                AND (m.expires_at IS NULL OR m.expires_at > ?)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('ii', $userId, $now);
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
     * Get distinct categories that have active messages.
     *
     * @return array<string> List of category strings
     */
    public function getActiveCategories(): array
    {
        if ($this->db === null) {
            return [];
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        $sql = "SELECT DISTINCT m.category
                FROM 202_server_messages m
                LEFT JOIN 202_server_message_user_state us
                    ON m.message_id = us.message_id AND us.user_id = ?
                WHERE COALESCE(us.is_dismissed, 0) = 0
                AND (m.expires_at IS NULL OR m.expires_at > ?)
                ORDER BY m.category ASC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $userId, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row['category'];
        }
        $stmt->close();

        return $categories;
    }

    // =========================================================================
    // Read / Dismiss (per-user)
    // =========================================================================

    /**
     * Ensure a user-state row exists for the given message + user, then return it.
     * Uses INSERT IGNORE to avoid race conditions.
     */
    private function ensureUserState(string $messageId, int $userId): bool
    {
        if ($this->db === null) {
            return false;
        }

        $sql = "INSERT IGNORE INTO 202_server_message_user_state (message_id, user_id) VALUES (?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $messageId, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    /**
     * Mark a message as read for the current user.
     */
    public function markAsRead(string $messageId): bool
    {
        if ($this->db === null) {
            return false;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        $this->ensureUserState($messageId, $userId);

        $stmt = $this->db->prepare(
            'UPDATE 202_server_message_user_state SET is_read = 1, read_at = ? WHERE message_id = ? AND user_id = ? AND is_read = 0'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isi', $now, $messageId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Dismiss a message for the current user.
     */
    public function dismissMessage(string $messageId): bool
    {
        if ($this->db === null) {
            return false;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        $this->ensureUserState($messageId, $userId);

        $stmt = $this->db->prepare(
            'UPDATE 202_server_message_user_state SET is_dismissed = 1, dismissed_at = ? WHERE message_id = ? AND user_id = ? AND is_dismissed = 0'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isi', $now, $messageId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Mark all visible messages as read for the current user.
     */
    public function markAllAsRead(): int
    {
        if ($this->db === null) {
            return 0;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        // First, ensure state rows exist for all unread messages
        $sql = "INSERT IGNORE INTO 202_server_message_user_state (message_id, user_id)
                SELECT m.message_id, ?
                FROM 202_server_messages m
                LEFT JOIN 202_server_message_user_state us
                    ON m.message_id = us.message_id AND us.user_id = ?
                WHERE us.id IS NULL
                AND (m.expires_at IS NULL OR m.expires_at > ?)";

        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iii', $userId, $userId, $now);
            $stmt->execute();
            $stmt->close();
        }

        // Now mark all as read
        $stmt = $this->db->prepare(
            'UPDATE 202_server_message_user_state SET is_read = 1, read_at = ?
             WHERE user_id = ? AND is_read = 0 AND is_dismissed = 0'
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('ii', $now, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    // =========================================================================
    // Replies
    // =========================================================================

    /**
     * Submit a reply to a message. Stored locally and sent to the central server.
     *
     * @param string $messageId The message being replied to
     * @param string $body The reply text
     * @return bool True if the reply was stored
     */
    public function submitReply(string $messageId, string $body): bool
    {
        if ($this->db === null) {
            return false;
        }

        $body = trim($body);
        if ($body === '') {
            return false;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        // Verify the message exists
        $check = $this->db->prepare('SELECT id FROM 202_server_messages WHERE message_id = ? LIMIT 1');
        if (!$check) {
            return false;
        }
        $check->bind_param('s', $messageId);
        if (!$check->execute()) {
            $check->close();
            return false;
        }
        $checkResult = $check->get_result();
        if ($checkResult->num_rows === 0) {
            $check->close();
            return false;
        }
        $check->close();

        // Store reply locally
        $stmt = $this->db->prepare(
            'INSERT INTO 202_server_message_replies (message_id, user_id, body, sent_to_server, created_at) VALUES (?, ?, ?, 0, ?)'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sisi', $messageId, $userId, $body, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $replyId = $stmt->insert_id;
        $stmt->close();

        // Try to send to central server (non-blocking — mark as sent on success)
        $this->sendReplyToServer($replyId, $messageId, $body);

        // Auto-mark as read
        $this->markAsRead($messageId);

        return true;
    }

    /**
     * Get replies for a specific message.
     *
     * @param string $messageId The message ID
     * @return array List of reply rows
     */
    public function getReplies(string $messageId): array
    {
        if ($this->db === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT r.*, u.user_name
             FROM 202_server_message_replies r
             LEFT JOIN 202_users u ON r.user_id = u.user_id
             WHERE r.message_id = ?
             ORDER BY r.created_at ASC'
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('s', $messageId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $replies = [];
        while ($row = $result->fetch_assoc()) {
            $replies[] = $row;
        }
        $stmt->close();

        return $replies;
    }

    /**
     * Send a reply to the central server via API.
     */
    private function sendReplyToServer(int $replyId, string $messageId, string $body): void
    {
        $installHash = $this->getInstallHash();
        if (empty($installHash)) {
            return;
        }

        $url = $this->baseUrl . '/server-messages/' . urlencode($installHash) . '/reply';
        $apiKey = $this->getApiKey();

        $ch = curl_init();
        if ($ch === false) {
            return;
        }

        $payload = json_encode([
            'message_id' => $messageId,
            'body' => $body,
            'user_id' => $this->getCurrentUserId(),
        ]);

        if ($payload === false) {
            return;
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Prosper202-ServerMessaging/' . (defined('PROSPER202_VERSION') ? PROSPER202_VERSION : '1.0'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Mark as sent on success
        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string) $response, true);
            $serverReplyId = (is_array($decoded) && isset($decoded['reply_id'])) ? (string) $decoded['reply_id'] : null;

            $stmt = $this->db->prepare(
                'UPDATE 202_server_message_replies SET sent_to_server = 1, server_reply_id = ? WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('si', $serverReplyId, $replyId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            error_log("ServerMessaging: Failed to send reply to server (HTTP {$httpCode})");
        }
    }

    // =========================================================================
    // Rich content rendering
    // =========================================================================

    /**
     * Render a message body as safe HTML.
     *
     * @param string $body Raw message body
     * @param string $format 'plain' or 'html'
     * @return string Safe HTML for rendering
     */
    public function renderBody(string $body, string $format = 'plain'): string
    {
        if ($format === 'html') {
            return $this->sanitizeHtml($body);
        }

        // Plain text: escape then convert newlines
        return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Sanitize HTML body to a safe subset of tags.
     * Strips all tags except the allow list.
     * Ensures <a> tags open in new tabs and have rel="noopener".
     */
    private function sanitizeHtml(string $html): string
    {
        $allowedTagStr = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $html = strip_tags($html, $allowedTagStr);

        // Process <a> tags: enforce target and rel attributes
        $html = preg_replace_callback(
            '/<a\s+([^>]*)>/i',
            function (array $matches): string {
                $attrs = $matches[1];

                // Extract href
                $href = '';
                if (preg_match('/href\s*=\s*["\']([^"\']*)["\']/', $attrs, $hrefMatch)) {
                    $href = $hrefMatch[1];
                }

                // Only allow http/https/mailto URLs
                if ($href !== '' && !preg_match('#^(https?://|mailto:)#i', $href)) {
                    return '<a>';
                }

                $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                return '<a href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Process <img> tags: enforce reasonable attributes
        $html = preg_replace_callback(
            '/<img\s+([^>]*)>/i',
            function (array $matches): string {
                $attrs = $matches[1];

                $src = '';
                if (preg_match('/src\s*=\s*["\']([^"\']*)["\']/', $attrs, $srcMatch)) {
                    $src = $srcMatch[1];
                }

                if ($src === '' || !preg_match('#^https?://#i', $src)) {
                    return '';
                }

                $alt = '';
                if (preg_match('/alt\s*=\s*["\']([^"\']*)["\']/', $attrs, $altMatch)) {
                    $alt = $altMatch[1];
                }

                $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                $safeAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
                return '<img src="' . $safeSrc . '" alt="' . $safeAlt . '" style="max-width:100%;height:auto;">';
            },
            $html
        ) ?? $html;

        return $html;
    }

    // =========================================================================
    // Sync status
    // =========================================================================

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
