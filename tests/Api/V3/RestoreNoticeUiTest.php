<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/** Settings' notice of what a database restore set aside says what, where and until when. */
final class RestoreNoticeUiTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/functions-restore-notice-ui.php';
    }

    public function testNoNoticeRendersNothing(): void
    {
        self::assertSame('', p202_restore_notice([], 'https://tracker.example/202-account/administration.php', 't'));
    }

    public function testTheNoticeNamesEachKindTheDirectoryAndTheDismissForm(): void
    {
        $at = 1_790_000_000;
        $html = p202_restore_notice([[
            'at' => $at,
            'name' => 'restored-20260922T000000Z-0a1b2c',
            'dir' => '/var/state/restored-20260922T000000Z-0a1b2c',
            'kept_until' => $at + 30 * 86400,
            'counts' => ['staged_changes' => 3, 'idempotency' => 1, 'jobs' => 2, 'traces' => 4],
        ]], 'https://tracker.example/202-account/administration.php', 'tok"en');

        self::assertStringContainsString(
            '3 staged changes, 1 retry record (Idempotency-Key), 2 sync jobs, 4 other records',
            $html
        );
        self::assertStringContainsString('<code>/var/state/restored-20260922T000000Z-0a1b2c</code>', $html);
        self::assertStringContainsString('until ' . date('M j, Y', $at + 30 * 86400) . ', then removed', $html);
        self::assertStringContainsString('Propose them again', $html);
        self::assertStringContainsString('name="token" value="tok&quot;en"', $html);
        self::assertStringContainsString(
            '<input type="hidden" name="restore_notice_dismiss[]" value="restored-20260922T000000Z-0a1b2c">',
            $html,
            'Dismiss names what was shown, so a notice made since is not dismissed with it'
        );
    }

    public function testWithNoStagedChangeSetAsideItDoesNotSayToProposeAgain(): void
    {
        $html = p202_restore_notice([[
            'at' => 1, 'name' => 'restored-x', 'dir' => '/s/restored-x', 'kept_until' => 2,
            'counts' => ['changes' => 1],
        ]], 'https://tracker.example/a', 't');

        self::assertStringContainsString('1 change-feed entry', $html);
        self::assertStringNotContainsString('Propose them again', $html);
    }
}
