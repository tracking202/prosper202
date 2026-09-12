<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

// The Markdown renderer lives in 202-config/markdown.php so it can be tested.
require_once __DIR__ . '/../202-config/markdown.php';

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