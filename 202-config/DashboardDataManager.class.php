<?php
declare(strict_types=1);

// Include the DashboardAPI class
include_once(__DIR__ . '/DashboardAPI.class.php');

/**
 * Dashboard Data Manager
 * Handles synchronization and retrieval of dashboard content from local cache
 */
class DashboardDataManager
{
    private static $db;
    private readonly ?DashboardAPI $api;

    public function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            error_log('DashboardDataManager: Database connection failed: ' . $e->getMessage());
            self::$db = null;
            $this->api = null;
            return;
        }

        $this->api = new DashboardAPI();
    }

    /**
     * Sync all dashboard content types
     * @return bool True if all syncs successful, false otherwise
     */
    public function syncAllContent(): bool
    {
        $contentTypes = ['alerts', 'tweets', 'posts', 'meetups', 'sponsors'];
        $allSuccess = true;

        foreach ($contentTypes as $type) {
            $success = $this->syncContentType($type);
            if (!$success) {
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Sync specific content type
     * @param string $contentType The content type to sync
     * @return bool True if sync successful, false otherwise
     */
    public function syncContentType(string $contentType): bool
    {
        if (self::$db === null) {
            error_log("DashboardDataManager: Cannot sync {$contentType} - no database connection");
            return false;
        }

        // Update sync start time
        $this->updateSyncStatus($contentType, 'start');

        try {
            // Fetch data from API based on content type
            $data = match ($contentType) {
                'alerts' => $this->api->fetchAlerts(),
                'tweets' => $this->api->fetchTweets(),
                'posts' => $this->api->fetchPosts(),
                'meetups' => $this->api->fetchMeetups(),
                'sponsors' => $this->api->fetchSponsors(),
                default => null
            };

            if ($data === null) {
                $this->updateSyncStatus($contentType, 'error', 'API request failed');
                return false;
            }

            // Process and store the data
            $result = $this->storeContent($contentType, $data);
            
            if ($result) {
                $this->updateSyncStatus($contentType, 'success');
                return true;
            } else {
                $this->updateSyncStatus($contentType, 'error', 'Failed to store content');
                return false;
            }
        } catch (Exception $e) {
            $this->updateSyncStatus($contentType, 'error', $e->getMessage());
            error_log("DashboardDataManager: Error syncing {$contentType}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store content in local database
     * @param string $contentType The content type
     * @param array $data The data to store
     * @return bool True if successful, false otherwise
     */
    private function storeContent(string $contentType, array $data): bool
    {
        if (self::$db === null || !is_array($data)) {
            return false;
        }

        // Begin transaction
        self::$db->begin_transaction();

        try {
            // Clear existing content for this type
            $sql = "DELETE FROM 202_dashboard_content WHERE content_type = ?";
            $stmt = self::$db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare delete statement");
            }
            $stmt->bind_param('s', $contentType);
            $stmt->execute();
            $stmt->close();

            // Insert new content
            $insertSql = "INSERT INTO 202_dashboard_content 
                         (content_type, external_id, title, description, link, image_url, published_at, data, is_active) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = self::$db->prepare($insertSql);
            if (!$stmt) {
                throw new Exception("Failed to prepare insert statement");
            }

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $external_id = $item['id'] ?? $item['external_id'] ?? null;
                $title = $item['title'] ?? '';
                $description = $item['description'] ?? $item['summary'] ?? '';
                $link = $item['link'] ?? $item['url'] ?? '';
                $image_url = $item['image_url'] ?? $item['image'] ?? '';
                $published_at = isset($item['published_at']) ? date('Y-m-d H:i:s', strtotime($item['published_at'])) : null;
                $json_data = json_encode($item);

                $stmt->bind_param(
                    'ssssssss',
                    $contentType,
                    $external_id,
                    $title,
                    $description,
                    $link,
                    $image_url,
                    $published_at,
                    $json_data
                );

                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert content item");
                }
            }

            $stmt->close();
            self::$db->commit();
            return true;

        } catch (Exception $e) {
            self::$db->rollback();
            error_log("DashboardDataManager: Failed to store {$contentType} content: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cached content for display
     * @param string $contentType The content type to retrieve
     * @param int $limit Maximum number of items to return
     * @return array Array of content items
     */
    public function getContent(string $contentType, int $limit = 10): array
    {
        if (self::$db === null) {
            return [];
        }

        $sql = "SELECT * FROM 202_dashboard_content 
                WHERE content_type = ? AND is_active = 1 
                ORDER BY published_at DESC, id DESC 
                LIMIT ?";
        
        $stmt = self::$db->prepare($sql);
        if (!$stmt) {
            error_log("DashboardDataManager: Failed to prepare select statement");
            return [];
        }

        $stmt->bind_param('si', $contentType, $limit);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $content = [];
        
        while ($row = $result->fetch_assoc()) {
            $content[] = $row;
        }
        
        $stmt->close();
        return $content;
    }

    /**
     * Update sync status in tracking table
     * @param string $contentType The content type
     * @param string $status The status (start, success, error)
     * @param string|null $error Error message if applicable
     */
    private function updateSyncStatus(string $contentType, string $status, ?string $error = null): void
    {
        if (self::$db === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        if ($status === 'start') {
            // Update last_sync time
            $sql = "INSERT INTO 202_dashboard_sync (content_type, last_sync) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE last_sync = VALUES(last_sync)";
            $stmt = self::$db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $contentType, $now);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($status === 'success') {
            // Update last_success time and reset error count
            $sql = "INSERT INTO 202_dashboard_sync (content_type, last_success, error_count) 
                    VALUES (?, ?, 0) 
                    ON DUPLICATE KEY UPDATE 
                    last_success = VALUES(last_success), 
                    error_count = 0, 
                    last_error = NULL";
            $stmt = self::$db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $contentType, $now);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($status === 'error') {
            // Increment error count and update error message
            $sql = "INSERT INTO 202_dashboard_sync (content_type, error_count, last_error) 
                    VALUES (?, 1, ?) 
                    ON DUPLICATE KEY UPDATE 
                    error_count = error_count + 1, 
                    last_error = VALUES(last_error)";
            $stmt = self::$db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $contentType, $error);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Get sync status for a content type
     * @param string $contentType The content type
     * @return array|null Sync status information
     */
    public function getSyncStatus(string $contentType): ?array
    {
        if (self::$db === null) {
            return null;
        }

        $sql = "SELECT * FROM 202_dashboard_sync WHERE content_type = ?";
        $stmt = self::$db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $contentType);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $status = $result->fetch_assoc();
        
        $stmt->close();
        return $status ?: null;
    }

    /**
     * Check if content is stale and needs refresh
     * @param string $contentType The content type
     * @return bool True if content is stale
     */
    public function isContentStale(string $contentType): bool
    {
        $status = $this->getSyncStatus($contentType);
        
        if (!$status || !$status['last_success']) {
            return true; // No successful sync yet
        }
        
        $lastSuccess = strtotime((string) $status['last_success']);
        $staleThreshold = time() - DASHBOARD_CACHE_TTL;
        
        return $lastSuccess < $staleThreshold;
    }
}