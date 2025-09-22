<?php
declare(strict_types=1);
header('Content-type: application/javascript');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT');
header("Pragma: no-cache");
include_once(substr(__DIR__, 0,-7) . '/202-config/functions.php');
?>

$(document).ready(function() {
    // Helper function for fetch with timeout and error handling
    async function fetchContent(url, elementId) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        try {
            const response = await fetch(url, {
                signal: controller.signal,
                cache: 'no-cache'
            });
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.text();
            const element = document.getElementById(elementId);
            if (element) {
                element.innerHTML = data;
            }
        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                console.log(`Timeout loading ${elementId}`);
            } else {
                console.log(`Error loading ${elementId}:`, error);
            }
            // Gracefully fail - keep existing content or show nothing
        }
    }
    
    // Load all dashboard content with modern fetch API
    fetchContent("<?php echo get_absolute_url();?>202-account/ajax/alerts.php", "tracking202_alerts");
    fetchContent("<?php echo get_absolute_url();?>202-account/ajax/tweets.php", "tracking202_tweets");
    fetchContent("<?php echo get_absolute_url();?>202-account/ajax/posts.php", "tracking202_posts");
    fetchContent("<?php echo get_absolute_url();?>202-account/ajax/meetups.php", "tracking202_meetups");
    fetchContent("<?php echo get_absolute_url();?>202-account/ajax/sponsors.php", "tracking202_sponsors");
    
    // System checks (no UI update needed)
    fetch("<?php echo get_absolute_url();?>202-account/ajax/system-checks.php")
        .catch(error => console.log('System check failed:', error));
});