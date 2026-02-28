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
    private DashboardAPI $api;
    private int $syncInterval = 900; // 15 minutes

    /** @var array{install_hash: ?string, api_key: ?string}|null Cached credentials */
    private ?array $credentials = null;

    /** @var string[] Allowed HTML tags for rich message bodies */
    private const array ALLOWED_TAGS = [
        'b', 'i', 'strong', 'em', 'a', 'br', 'p', 'ul', 'ol', 'li',
        'code', 'pre', 'h4', 'h5', 'h6', 'blockquote', 'hr', 'span', 'img',
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

        $this->api = new DashboardAPI();
    }

    /**
     * Get the current user ID from the session.
     * Callers gate on AUTH::require_user() so the session is always set.
     */
    private function getCurrentUserId(): int
    {
        return (int) $_SESSION['user_id'];
    }

    /**
     * Load install_hash and API key in a single query (cached per request).
     */
    private function getCredentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $this->credentials = ['install_hash' => null, 'api_key' => null];

        if ($this->db === null) {
            return $this->credentials;
        }

        $userId = $this->getCurrentUserId();
        $stmt = $this->db->prepare(
            'SELECT install_hash, p202_customer_api_key FROM 202_users WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $this->credentials;
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $this->credentials;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            $this->credentials = [
                'install_hash' => $row['install_hash'] ?? null,
                'api_key' => $row['p202_customer_api_key'] ?? null,
            ];
        }

        return $this->credentials;
    }

    /**
     * Build auth headers for API requests.
     *
     * @return string[] HTTP headers
     */
    private function buildApiHeaders(): array
    {
        $creds = $this->getCredentials();
        $headers = [
            'X-P202-Version: ' . PROSPER202_VERSION,
        ];

        if (!empty($creds['api_key'])) {
            $headers[] = 'X-P202-Api-Key: ' . $creds['api_key'];
        }

        return $headers;
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

        $installHash = $this->getCredentials()['install_hash'];
        if (empty($installHash)) {
            error_log('ServerMessaging: No install_hash found, cannot sync');
            return false;
        }

        $this->updateSyncStatus('start');

        $decoded = $this->api->request(
            'server-messages/' . urlencode($installHash),
            $this->buildApiHeaders()
        );

        if ($decoded === null) {
            $this->updateSyncStatus('error', 'Failed to fetch messages from server');
            return false;
        }

        // Support both {data: [...]} wrapper and plain array
        $messages = isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : $decoded;

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
     * Store fetched messages in the local database.
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

        $stmt = $this->db->prepare('DELETE FROM 202_server_messages WHERE expires_at IS NOT NULL AND expires_at < ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $now);
        if (!$stmt->execute()) {
            error_log('ServerMessaging: Failed to clean expired messages: ' . $stmt->error);
        }
        $stmt->close();

        // Clean orphaned user state and reply rows
        $result = $this->db->query(
            'DELETE s FROM 202_server_message_user_state s
             LEFT JOIN 202_server_messages m ON s.message_id = m.message_id
             WHERE m.message_id IS NULL'
        );
        if ($result === false) {
            error_log('ServerMessaging: Failed to clean orphaned user state: ' . $this->db->error);
        }

        $result = $this->db->query(
            'DELETE r FROM 202_server_message_replies r
             LEFT JOIN 202_server_messages m ON r.message_id = m.message_id
             WHERE m.message_id IS NULL'
        );
        if ($result === false) {
            error_log('ServerMessaging: Failed to clean orphaned replies: ' . $this->db->error);
        }
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

        $sql = "SELECT m.id, m.message_id, m.type, m.category, m.title, m.body,
                    m.action_url, m.action_label, m.priority, m.icon, m.image_url,
                    m.format, m.expires_at, m.published_at, m.fetched_at,
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
    // Read / Dismiss (per-user) — unified via setUserState()
    // =========================================================================

    /**
     * Ensure a user-state row exists, then set a flag on it.
     *
     * @param string $messageId Server-assigned message ID
     * @param string $flagColumn 'is_read' or 'is_dismissed'
     * @param string $timestampColumn 'read_at' or 'dismissed_at'
     * @return bool True if the flag was set (i.e. it was not already set)
     */
    private function setUserState(string $messageId, string $flagColumn, string $timestampColumn): bool
    {
        if ($this->db === null) {
            return false;
        }

        // Whitelist column names to prevent SQL injection
        $allowed = ['is_read' => 'read_at', 'is_dismissed' => 'dismissed_at'];
        if (!isset($allowed[$flagColumn]) || $allowed[$flagColumn] !== $timestampColumn) {
            return false;
        }

        $userId = $this->getCurrentUserId();
        $now = time();

        // Ensure state row exists
        $insert = $this->db->prepare(
            "INSERT IGNORE INTO 202_server_message_user_state (message_id, user_id) VALUES (?, ?)"
        );
        if (!$insert) {
            return false;
        }
        $insert->bind_param('si', $messageId, $userId);
        if (!$insert->execute()) {
            $insert->close();
            return false;
        }
        $insert->close();

        // Set the flag
        $sql = "UPDATE 202_server_message_user_state SET {$flagColumn} = 1, {$timestampColumn} = ? WHERE message_id = ? AND user_id = ? AND {$flagColumn} = 0";
        $stmt = $this->db->prepare($sql);
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
     * Mark a message as read for the current user.
     */
    public function markAsRead(string $messageId): bool
    {
        return $this->setUserState($messageId, 'is_read', 'read_at');
    }

    /**
     * Dismiss a message for the current user.
     */
    public function dismissMessage(string $messageId): bool
    {
        return $this->setUserState($messageId, 'is_dismissed', 'dismissed_at');
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

        // First, ensure state rows exist for all visible messages
        $sql = "INSERT IGNORE INTO 202_server_message_user_state (message_id, user_id)
                SELECT m.message_id, ?
                FROM 202_server_messages m
                LEFT JOIN 202_server_message_user_state us
                    ON m.message_id = us.message_id AND us.user_id = ?
                WHERE us.id IS NULL
                AND (m.expires_at IS NULL OR m.expires_at > ?)";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('iii', $userId, $userId, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $stmt->close();

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

        $this->sendReplyToServer($replyId, $messageId, $body);
        $this->markAsRead($messageId);

        return true;
    }

    /**
     * Get replies for a list of message IDs in a single query (batch).
     *
     * @param string[] $messageIds
     * @return array<string, array> Keyed by message_id
     */
    public function getRepliesBatch(array $messageIds): array
    {
        if ($this->db === null || $messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $types = str_repeat('s', count($messageIds));

        $stmt = $this->db->prepare(
            "SELECT r.message_id, r.body, r.created_at, u.user_name
             FROM 202_server_message_replies r
             LEFT JOIN 202_users u ON r.user_id = u.user_id
             WHERE r.message_id IN ({$placeholders})
             ORDER BY r.created_at ASC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$messageIds);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $grouped[$row['message_id']][] = $row;
        }
        $stmt->close();

        return $grouped;
    }

    /**
     * Send a reply to the central server via API.
     */
    private function sendReplyToServer(int $replyId, string $messageId, string $body): void
    {
        $installHash = $this->getCredentials()['install_hash'];
        if (empty($installHash)) {
            return;
        }

        $payload = json_encode([
            'message_id' => $messageId,
            'body' => $body,
        ]);
        if ($payload === false) {
            error_log('ServerMessaging: Failed to encode reply payload: ' . json_last_error_msg());
            return;
        }

        $decoded = $this->api->request(
            'server-messages/' . urlencode($installHash) . '/reply',
            $this->buildApiHeaders(),
            $payload
        );

        if ($decoded !== null) {
            $serverReplyId = isset($decoded['reply_id']) ? (string) $decoded['reply_id'] : null;

            $stmt = $this->db->prepare(
                'UPDATE 202_server_message_replies SET sent_to_server = 1, server_reply_id = ? WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('si', $serverReplyId, $replyId);
                if (!$stmt->execute()) {
                    error_log('ServerMessaging: Failed to mark reply as sent: ' . $stmt->error);
                }
                $stmt->close();
            }
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

        return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Sanitize HTML body to a safe subset of tags.
     */
    private function sanitizeHtml(string $html): string
    {
        $allowedTagStr = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $html = strip_tags($html, $allowedTagStr);

        // Process <a> tags: enforce target and rel, validate URL scheme
        $html = preg_replace_callback(
            '/<a\s+([^>]*)>/i',
            function (array $matches): string {
                $attrs = $matches[1];
                $href = '';
                if (preg_match('/href\s*=\s*["\']([^"\']*)["\']/', $attrs, $hrefMatch)) {
                    $href = $hrefMatch[1];
                }

                if ($href !== '') {
                    $cleaned = function_exists('clean_url') ? clean_url($href) : $href;
                    if ($cleaned === '' || (!str_starts_with($cleaned, 'http') && !str_starts_with($cleaned, 'mailto:'))) {
                        return '<a>';
                    }
                    $href = $cleaned;
                }

                $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                return '<a href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Process <img> tags: enforce https src, sanitize attributes
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

        $sqlMap = [
            'start'   => 'UPDATE 202_server_messages_sync SET last_sync = ? WHERE id = 1',
            'success' => 'UPDATE 202_server_messages_sync SET last_success = ?, error_count = 0, last_error = NULL WHERE id = 1',
            'error'   => 'UPDATE 202_server_messages_sync SET error_count = error_count + 1, last_error = ? WHERE id = 1',
        ];

        $sql = $sqlMap[$status] ?? null;
        if ($sql === null) {
            return;
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }

        if ($status === 'error') {
            $stmt->bind_param('s', $error);
        } else {
            $stmt->bind_param('i', $now);
        }

        if (!$stmt->execute()) {
            error_log('ServerMessaging: Failed to update sync status: ' . $stmt->error);
        }
        $stmt->close();
    }
}
