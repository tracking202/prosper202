<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');
include_once(str_repeat("../", 2).'202-config/DashboardDataManager.class.php');

AUTH::require_user();

// Get cached meetups from local database
$dataManager = new DashboardDataManager();
$meetups = $dataManager->getContent('meetups', 20);

// If no cached meetups, exit silently
if (empty($meetups)) {
    exit;
}

$counter = 0;
foreach ($meetups as $meetup) {
    $counter++;
    
    // Extract data from JSON if available, fallback to direct fields
    $data = json_decode($meetup['data'] ?? '{}', true) ?: [];
    
    $title = $meetup['title'] ?? $data['meetup_group'] ?? '';
    $summary = $data['summary'] ?? '';
    $description_raw = $meetup['description'] ?? $data['description'] ?? '';
    $description = $summary . ($summary && $description_raw ? '. ' : '') . $description_raw;
    $link = $meetup['link'] ?? $data['link'] ?? '';
    
    // Handle meetup time - try multiple sources
    $meetup_time = 0;
    if ($meetup['published_at']) {
        $meetup_time = strtotime((string) $meetup['published_at']);
    } elseif (isset($data['meetup_start_time'])) {
        $meetup_time = (int)$data['meetup_start_time'];
    }
    
    $formatted_time = $meetup_time > 0 ? date('l, M j \a\t g:i A T', $meetup_time) : 'Time TBD';
    
    // Sanitize and truncate description
    $clean_description = htmlentities($description);
    if (strlen($clean_description) > 350) { 
        $clean_description = substr($clean_description, 0, 350) . ' [...]';
    }
    
    if ($counter <= 20) { ?>
        
        <h4><a href="http://meetup.tracking202.com" target="_blank"></a> <a href='<?php echo htmlentities($link); ?>' target="_blank"><?php echo htmlentities($title); ?></a> - <?php echo htmlentities($formatted_time); ?></h4>
        <p><?php echo $clean_description; ?></p>
        
    <?php }
} ?>