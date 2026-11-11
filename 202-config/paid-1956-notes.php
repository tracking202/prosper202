<?php

declare(strict_types=1);

// The upgrade notes for installs coming from paid 1.9.56, readable before
// sign-in: the upgrade page links here, and it runs with nobody signed in.
include_once(__DIR__ . '/connect.php');
require_once __DIR__ . '/markdown.php';

$notes = @file_get_contents(dirname(__DIR__) . '/' . \Prosper202\Update\PaidOrigin::NOTES_FILE);
if ($notes === false) {
    http_response_code(404);
    info_top(['title' => 'Upgrade notes - Prosper202 ClickServer']);
    echo p202_standalone_card(
        'The upgrade notes are not on this server',
        'They ship with Prosper202 under documentation/upgrading/.'
    );
    echo p202_standalone_card_end();
    info_bottom();
    exit;
}

info_top(['title' => 'Upgrading from paid Prosper202 1.9.56 - Prosper202 ClickServer', 'wide' => true]);
echo p202_standalone_card();
?>
<p><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(get_absolute_url(), ENT_QUOTES, 'UTF-8'); ?>">
    <i class="bi bi-arrow-left"></i> Back to Prosper202</a></p>
<div class="p202-doc">
    <?php echo markdownDocLinks(markdownToHtml($notes), \Prosper202\Update\PaidOrigin::NOTES_FILE); ?>
</div>
<?php
echo p202_standalone_card_end();
info_bottom();
