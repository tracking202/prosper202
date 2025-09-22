<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');
include_once(str_repeat("../", 2).'202-config/functions-rss.php');
include_once(str_repeat("../", 2).'202-config/DashboardDataManager.class.php');

AUTH::require_user();

// Get cached posts from local database
$dataManager = new DashboardDataManager();
$posts = $dataManager->getContent('posts', 2);

// If no cached posts, exit silently
if (empty($posts)) {
    exit;
}

foreach ($posts as $post) {
    $description = html2txt($post['description'] ?? '');
    
    if (strlen((string) $description) > 350) { 
        $description = substr((string) $description, 0, 350) . ' [...]';
    }
    
    $published_time = $post['published_at'] ? strtotime((string) $post['published_at']) : time();
    $time_ago = human_time_diff($published_time, time());
    $title = $post['title'] ?? '';
    $link = $post['link'] ?? ''; ?>
    
    <i class="fa fa-rss-square"></i> <a href='<?php echo htmlentities($link); ?>'><?php echo htmlentities($title); ?></a> - <span style="font-size: 10px;">(<?php printf(('%s ago'), $time_ago); ?>)</span><br/>
    <span class="infotext"><?php echo htmlentities((string) $description); ?></span><br></br>
    
<?php } ?>