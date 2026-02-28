<?php
declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$messaging = new ServerMessaging();

// Trigger background sync on page load (non-blocking, respects interval)
$messaging->syncMessages();

$category = isset($_GET['category']) ? trim($_GET['category']) : null;
$messages = $messaging->getMessages(20, $category);
$categories = $messaging->getActiveCategories();

// Render category filter tabs if more than one category exists
if (count($categories) > 1) { ?>
    <div class="sm-categories">
        <a href="#" class="sm-cat-tab sm-cat-active" data-category="all">All</a>
        <?php foreach ($categories as $cat) {
            $catLabel = ucfirst(htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'));
            $catValue = htmlspecialchars($cat, ENT_QUOTES, 'UTF-8');
            $activeClass = ($category === $cat) ? ' sm-cat-active' : '';
        ?>
            <a href="#" class="sm-cat-tab<?php echo $activeClass; ?>" data-category="<?php echo $catValue; ?>"><?php echo $catLabel; ?></a>
        <?php } ?>
    </div>
<?php }

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
    $format = $msg['format'] ?? 'plain';
    $body = $messaging->renderBody($msg['body'], $format);
    $categoryValue = htmlspecialchars($msg['category'] ?? 'general', ENT_QUOTES, 'UTF-8');

    $publishedAt = (int) $msg['published_at'];
    $timeAgo = function_exists('human_time_diff')
        ? human_time_diff($publishedAt, time()) . ' ago'
        : date('M j, Y', $publishedAt);

    // Get replies for this message
    $replies = $messaging->getReplies($msg['message_id']);
    ?>
    <div class="sm-item <?php echo $typeClass . ' ' . $readClass; ?>" data-message-id="<?php echo $messageId; ?>" data-category="<?php echo $categoryValue; ?>">
        <div class="sm-item-icon">
            <span class="<?php echo $iconClass; ?>"></span>
        </div>
        <div class="sm-item-content">
            <div class="sm-item-header">
                <strong class="sm-item-title"><?php echo $title; ?></strong>
                <span class="sm-item-time"><?php echo htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <?php if (!empty($msg['image_url'])) {
                $imageUrl = htmlspecialchars($msg['image_url'], ENT_QUOTES, 'UTF-8');
            ?>
                <div class="sm-item-image">
                    <img src="<?php echo $imageUrl; ?>" alt="" loading="lazy">
                </div>
            <?php } ?>

            <div class="sm-item-body sm-rich-content"><?php echo $body; ?></div>

            <?php if (!empty($msg['action_url'])) {
                $actionUrl = htmlspecialchars($msg['action_url'], ENT_QUOTES, 'UTF-8');
                $actionLabel = htmlspecialchars($msg['action_label'] ?? 'Learn More', ENT_QUOTES, 'UTF-8');
            ?>
                <a href="<?php echo $actionUrl; ?>" class="btn btn-xs btn-primary sm-item-action-btn" target="_blank" rel="noopener"><?php echo $actionLabel; ?></a>
            <?php } ?>

            <?php if (!empty($replies)) { ?>
                <div class="sm-replies">
                    <?php foreach ($replies as $reply) {
                        $replyBody = htmlspecialchars($reply['body'], ENT_QUOTES, 'UTF-8');
                        $replyUser = htmlspecialchars($reply['user_name'] ?? 'You', ENT_QUOTES, 'UTF-8');
                        $replyTime = function_exists('human_time_diff')
                            ? human_time_diff((int) $reply['created_at'], time()) . ' ago'
                            : date('M j g:ia', (int) $reply['created_at']);
                    ?>
                        <div class="sm-reply">
                            <span class="sm-reply-user"><?php echo $replyUser; ?></span>
                            <span class="sm-reply-time"><?php echo htmlspecialchars($replyTime, ENT_QUOTES, 'UTF-8'); ?></span>
                            <div class="sm-reply-body"><?php echo $replyBody; ?></div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>

            <div class="sm-reply-form" data-message-id="<?php echo $messageId; ?>">
                <input type="text" class="sm-reply-input" placeholder="Write a reply..." maxlength="2000">
                <button type="button" class="sm-reply-send btn btn-xs btn-default" title="Send reply">
                    <span class="fui-arrow-right"></span>
                </button>
            </div>
        </div>
        <button type="button" class="sm-item-dismiss" data-message-id="<?php echo $messageId; ?>" title="Dismiss">
            <span class="fui-cross"></span>
        </button>
    </div>
<?php } ?>
