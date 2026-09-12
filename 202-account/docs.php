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
    
    // Convert line breaks to paragraphs; pipe tables become <table>
    $lines = explode("\n", $html);
    $paragraphs = [];
    $current_paragraph = '';
    $count = count($lines);
    $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
    $separator = '/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/';

    for ($i = 0; $i < $count; $i++) {
        $line = trim($lines[$i]);
        // A pipe table: a header row, a row of dashes, then the body rows.
        if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match($separator, trim($lines[$i + 1])) === 1) {
            if (!empty($current_paragraph)) {
                $paragraphs[] = '<p>' . $current_paragraph . '</p>';
                $current_paragraph = '';
            }
            $table = '<table class="doc-table"><thead><tr>';
            foreach ($cells($line) as $cell) {
                $table .= '<th>' . $cell . '</th>';
            }
            $table .= '</tr></thead><tbody>';
            for ($i += 2; $i < $count && str_starts_with(trim($lines[$i]), '|'); $i++) {
                $table .= '<tr>';
                foreach ($cells($lines[$i]) as $cell) {
                    $table .= '<td>' . $cell . '</td>';
                }
                $table .= '</tr>';
            }
            $i--; // the loop increment steps past the last row
            $paragraphs[] = $table . '</tbody></table>';
            continue;
        }
        if (empty($line)) {
            if (!empty($current_paragraph)) {
                $paragraphs[] = $current_paragraph;
                $current_paragraph = '';
            }
        } elseif (preg_match('/^<(h[1-6]|pre|ul|li|table)/', $line)) {
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
    'api-integrations' => 'documentation/api/00-api-integrations.md',
    'ui-standard' => 'documentation/features/ui-standard.md'
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
if ($markdown_content === false) {
    header('HTTP/1.1 404 Not Found');
    exit('Document file not found');
}
$html_content = markdownToHtml($markdown_content);

$doc_titles = [
    'attribution-engine' => 'Advanced Attribution Engine',
    'attribution-troubleshooting' => 'Attribution Troubleshooting Guide',
    'api-integrations' => 'API Integrations',
    'ui-standard' => 'The Prosper202 UI Standard'
];

template_top($doc_titles[$doc]); ?>

<style>
.documentation {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    line-height: 1.6;
    color: #333;
}

.documentation h1 {
    color: #32383f;
    border-bottom: 2px solid #2f6fdd;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.documentation h2 {
    color: #32383f;
    margin-top: 30px;
    margin-bottom: 15px;
    border-left: 4px solid #2f6fdd;
    padding-left: 10px;
}

.documentation h3 {
    color: #32383f;
    margin-top: 25px;
    margin-bottom: 10px;
}

.documentation .doc-table {
    width: 100%;
    border-collapse: collapse;
    margin: 16px 0 24px;
    font-size: 14px;
    line-height: 1.5;
}

.documentation .doc-table th,
.documentation .doc-table td {
    padding: 8px 10px;
    border: 1px solid #e7e8ea;
    text-align: left;
    vertical-align: top;
}

.documentation .doc-table th {
    background: #fafbfc;
    color: #32383f;
    font-weight: 600;
}

.documentation code {
    background-color: #fafbfc;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: "Monaco", "Menlo", "Ubuntu Mono", monospace;
    font-size: 85%;
}

.documentation pre {
    background-color: #fafbfc;
    border: 1px solid #f0f1f2;
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
    color: #2f6fdd;
    text-decoration: none;
}

.documentation a:hover {
    text-decoration: underline;
}

.back-link {
    margin-bottom: 20px;
}

.back-link a {
    color: #6b7280;
    text-decoration: none;
    font-size: 14px;
}

.back-link a:hover {
    color: #2f6fdd;
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