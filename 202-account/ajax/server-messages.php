<?php
declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$messaging = new ServerMessaging();

// Trigger background sync on page load (non-blocking, respects interval)
$messaging->syncMessages();

$messages = $messaging->getMessages(20);

if (empty($messages)) { ?>
    <div class="sm-empty">
        <span class="fui-check-circle"></span>
        <p>No messages right now.</p>
    </div>
<?php exit;
}

foreach ($messages as $msg) {
    $typeClass = match ($msg['type']) {
        'warning' => 'sm-item-warning',
        'success' => 'sm-item-success',
        'action'  => 'sm-item-action',
        default   => 'sm-item-info',
    };

    $iconClass = match ($msg['type']) {
        'warning' => 'fui-alert',
        'success' => 'fui-check-circle',
        'action'  => 'fui-gear',
        default   => 'fui-info-circle',
    };

    if (!empty($msg['icon'])) {
        $iconClass = htmlspecialchars($msg['icon'], ENT_QUOTES, 'UTF-8');
    }

    $readClass = $msg['is_read'] ? 'sm-item-read' : 'sm-item-unread';
    $messageId = htmlspecialchars($msg['message_id'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($msg['title'], ENT_QUOTES, 'UTF-8');
    $body = nl2br(htmlspecialchars($msg['body'], ENT_QUOTES, 'UTF-8'));

    $publishedAt = (int) $msg['published_at'];
    $timeAgo = function_exists('human_time_diff')
        ? human_time_diff($publishedAt, time()) . ' ago'
        : date('M j, Y', $publishedAt);
    ?>
    <div class="sm-item <?php echo $typeClass . ' ' . $readClass; ?>" data-message-id="<?php echo $messageId; ?>">
        <div class="sm-item-icon">
            <span class="<?php echo $iconClass; ?>"></span>
        </div>
        <div class="sm-item-content">
            <div class="sm-item-header">
                <strong class="sm-item-title"><?php echo $title; ?></strong>
                <span class="sm-item-time"><?php echo htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="sm-item-body"><?php echo $body; ?></div>
            <?php if (!empty($msg['action_url'])) {
                $actionUrl = htmlspecialchars($msg['action_url'], ENT_QUOTES, 'UTF-8');
                $actionLabel = htmlspecialchars($msg['action_label'] ?? 'Learn More', ENT_QUOTES, 'UTF-8');
            ?>
                <a href="<?php echo $actionUrl; ?>" class="btn btn-xs btn-primary sm-item-action-btn" target="_blank" rel="noopener"><?php echo $actionLabel; ?></a>
            <?php } ?>
        </div>
        <button type="button" class="sm-item-dismiss" data-message-id="<?php echo $messageId; ?>" title="Dismiss">
            <span class="fui-cross"></span>
        </button>
    </div>
<?php } ?>
