<?php
declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$action = $_POST['action'] ?? '';
$messageId = $_POST['message_id'] ?? '';

$messaging = new ServerMessaging();

$response = ['success' => false];

switch ($action) {
    case 'read':
        if ($messageId === '') {
            $response = ['success' => false, 'error' => 'Missing message_id'];
            break;
        }
        $result = $messaging->markAsRead($messageId);
        $response = ['success' => $result, 'unread_count' => $messaging->getUnreadCount()];
        break;

    case 'dismiss':
        if ($messageId === '') {
            $response = ['success' => false, 'error' => 'Missing message_id'];
            break;
        }
        $result = $messaging->dismissMessage($messageId);
        $response = ['success' => $result, 'unread_count' => $messaging->getUnreadCount()];
        break;

    case 'read_all':
        $count = $messaging->markAllAsRead();
        $response = ['success' => true, 'marked_count' => $count, 'unread_count' => 0];
        break;

    case 'count':
        $response = ['success' => true, 'unread_count' => $messaging->getUnreadCount()];
        break;

    default:
        $response = ['success' => false, 'error' => 'Unknown action'];
}

echo json_encode($response);
