<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

// Simple markdown to HTML converter
function markdownToHtml(string $markdown): string {
    // Convert headers
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $markdown);
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
    
    // Convert code blocks
    $html = preg_replace('/```(\w+)?\n(.*?)```/s', '<pre><code class="language-$1">$2</code></pre>', $html);
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    
    // Convert links
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
    
    // Convert bold and italic
    $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $html);
    
    // Convert lists
    $html = preg_replace('/^\- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
    
    // Convert line breaks to paragraphs
    $lines = explode("\n", $html);
    $paragraphs = [];
    $current_paragraph = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            if (!empty($current_paragraph)) {
                $paragraphs[] = $current_paragraph;
                $current_paragraph = '';
            }
        } elseif (preg_match('/^<(h[1-6]|pre|ul|li)/', $line)) {
            if (!empty($current_paragraph)) {
                $paragraphs[] = '<p>' . $current_paragraph . '</p>';
                $current_paragraph = '';
            }
            $paragraphs[] = $line;
        } else {
            $current_paragraph .= ($current_paragraph ? ' ' : '') . $line;
        }
    }
    
    if (!empty($current_paragraph)) {
        $paragraphs[] = '<p>' . $current_paragraph . '</p>';
    }
    
    return implode("\n", $paragraphs);
}

// Get the document to display
$doc = $_GET['doc'] ?? '';
$allowed_docs = [
    'attribution-engine' => 'documentation/tutorials-and-guides/14-advanced-attribution-engine.md',
    'attribution-troubleshooting' => 'documentation/tutorials-and-guides/15-advanced-attribution-troubleshooting.md',
    'api-integrations' => 'documentation/api/00-api-integrations.md'
];

if (!isset($allowed_docs[$doc])) {
    header('HTTP/1.1 404 Not Found');
    exit('Document not found');
}

$file_path = '../' . $allowed_docs[$doc];
if (!file_exists($file_path)) {
    header('HTTP/1.1 404 Not Found');
    exit('Document file not found');
}

$markdown_content = file_get_contents($file_path);
$html_content = markdownToHtml($markdown_content);

$doc_titles = [
    'attribution-engine' => 'Advanced Attribution Engine',
    'attribution-troubleshooting' => 'Attribution Troubleshooting Guide',
    'api-integrations' => 'API Integrations'
];

template_top($doc_titles[$doc]); ?>

<style>
.documentation {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.6;
    color: #333;
}

.documentation h1 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.documentation h2 {
    color: #34495e;
    margin-top: 30px;
    margin-bottom: 15px;
    border-left: 4px solid #3498db;
    padding-left: 10px;
}

.documentation h3 {
    color: #34495e;
    margin-top: 25px;
    margin-bottom: 10px;
}

.documentation code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: "Monaco", "Menlo", "Ubuntu Mono", monospace;
    font-size: 85%;
}

.documentation pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 5px;
    padding: 15px;
    overflow-x: auto;
    margin: 15px 0;
}

.documentation pre code {
    background: none;
    padding: 0;
}

.documentation ul {
    margin: 10px 0 10px 20px;
}

.documentation li {
    margin: 5px 0;
}

.documentation a {
    color: #3498db;
    text-decoration: none;
}

.documentation a:hover {
    text-decoration: underline;
}

.back-link {
    margin-bottom: 20px;
}

.back-link a {
    color: #7f8c8d;
    text-decoration: none;
    font-size: 14px;
}

.back-link a:hover {
    color: #3498db;
}
</style>

<div class="row account">
    <div class="col-xs-12">
        <div class="back-link">
            <a href="help.php">&larr; Back to Help Resources</a>
        </div>
        
        <div class="documentation">
            <?php echo $html_content; ?>
        </div>
    </div>
</div>

<?php template_bottom(); ?>