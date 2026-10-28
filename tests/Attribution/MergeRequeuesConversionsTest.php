<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelType;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * A merge re-attributes the conversions it joins (plan §6.2).
 *
 * A web click and an install click carry different visitor keys; the
 * install converts and its journey is built one-touch. Then the signed
 * customer id arrives on both clicks — the normal order, after the install —
 * and merges the two keys. The worker must re-queue the install conversion
 * (reason identity_merge), rebuild its journey with both clicks, credit both
 * under every active model, and mark the merge requeued.
 *
 * @group integration
 */
final class MergeRequeuesConversionsTest extends TestCase
{
    use AttributionDatabase;

    public function testAMergeRebuildsTheJourneysItJoins(): void
    {
        $now = time();
        $this->campaign(1); // web
        $this->campaign(2); // app install
        $this->click(500, 1, $now - 2 * 86400);
        $webKey = $this->visit(500, $now - 2 * 86400, self::cookie('web-browser'));
        $this->click(501, 2, $now - 3600);
        $installKey = $this->visit(501, $now - 3600, self::cookie('phone-webview'));
        self::assertNotSame($webKey, $installKey, 'two browsers, two visitors');

        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $first = $this->addModel('First', ModelType::FIRST_TOUCH);
        $install = $this->convert(501, '5.00', 'install');
        $this->work();
        self::assertSame([501], self::journeyClicks($install), 'built one-touch before anything linked the two');

        // The signed customer id, on both clicks.
        $this->visit(500, $now - 2 * 86400, self::cookie('web-browser'), self::customer('custom:u-42'));
        $this->visit(501, $now - 3600, self::cookie('phone-webview'), self::customer('custom:u-42'));
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_merges WHERE requeued_at IS NULL'), 'one merge, not yet fanned out');
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'), 'the request that merged queued nothing itself');

        $report = $this->work();
        self::assertSame(1, $report->mergesRequeued, $report->summary());
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_merges WHERE requeued_at IS NULL'));

        self::assertSame([500, 501], self::journeyClicks($install), 'the journey now spans both sides of the merge');
        self::assertSame([501 => '1.00000000'], self::credits($install, $this->defaultModelId()));
        self::assertSame([500 => '1.00000000'], self::credits($install, $first), 'first touch moves to the web click');
        self::assertSame([500 => '0.50000000', 501 => '0.50000000'], self::credits($install, $linear));
        foreach ([$this->defaultModelId(), $linear, $first] as $m) {
            self::assertSame('5.00000', self::revenueUnder($m));
        }
    }

    public function testAMergeRequeuesConversionsOnBothSides(): void
    {
        $now = time();
        $this->campaign(1);
        $this->click(600, 1, $now - 7200);
        $this->visit(600, $now - 7200, self::cookie('a'));
        $this->click(601, 1, $now - 3600);
        $this->visit(601, $now - 3600, self::cookie('b'));
        $left = $this->convert(600, '1', 'L');
        $right = $this->convert(601, '2', 'R');
        $this->work();

        // One click carrying both cookies joins the two people.
        $this->click(602, 1, $now - 60);
        $this->visit(602, $now - 60, self::cookie('a'), self::cookie('b'));
        $this->work();

        self::assertSame([600], self::journeyClicks($left), 'nothing of the other side is earlier than the left conversion\'s click');
        self::assertSame([600, 601], self::journeyClicks($right), 'the right conversion gains the left side\'s earlier click');
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'));
    }
}
