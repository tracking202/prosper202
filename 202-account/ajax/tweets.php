<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');
include_once(str_repeat("../", 2).'202-config/functions-rss.php');
include_once(str_repeat("../", 2).'202-config/DashboardDataManager.class.php');

AUTH::require_user();

// Get cached tweets from local database
$dataManager = new DashboardDataManager();
$tweets = $dataManager->getContent('tweets', 1);

// If no cached tweets, exit silently
if (empty($tweets)) {
    exit;
}

foreach ($tweets as $tweet) {
    $published_time = $tweet['published_at'] ? strtotime((string) $tweet['published_at']) : time();
    
    // Only display items that are recent within 30 days
    if ($published_time > (time() - 60*60*24*30)) {
        $title = str_replace('tracking202: ', '', $tweet['title'] ?? '');
        $description = html2txt($tweet['description'] ?? '');
        $link = $tweet['link'] ?? '';
        $time_ago = human_time_diff($published_time); ?>
        
        <span class="fui-twitter"></span><a href='<?php echo htmlentities($link); ?>'><?php echo htmlentities($title); ?></a> - <span style="font-size: 10px;">(<?php printf(('%s ago'), $time_ago); ?>)</span><br></br>
        
    <?php }
} ?>