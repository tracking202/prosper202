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

// The document's own first heading becomes the page header, so the title is
// not said twice. It is markup from the same render as the body below: the
// renderer passes inline HTML through (it does not escape heading text), which
// is acceptable only because $allowed_docs names files from this repository.
$doc_heading = htmlspecialchars($doc_titles[$doc], ENT_QUOTES, 'UTF-8');
if (preg_match('~^\s*<h1>(.*?)</h1>~s', $html_content, $first_heading)) {
    $doc_heading = $first_heading[1];
    $html_content = substr($html_content, strlen($first_heading[0]));
}

template_top($doc_titles[$doc], ['ui' => 'v2']); ?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-book"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title"><?php echo $doc_heading; ?></h1>
        <p class="p202-page-header__desc">Documentation that ships with this install.</p>
    </div>
    <div class="p202-page-header__actions">
        <a class="btn btn-secondary" href="help.php"><i class="bi bi-arrow-left"></i> Help</a>
    </div>
</div>

<article class="p202-panel">
    <div class="p202-panel__body">
        <div class="p202-doc">
            <?php echo $html_content; ?>
        </div>
    </div>
</article>

<?php template_bottom(); ?>
