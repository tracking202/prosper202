<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\PublisherCredit;

/**
 * What a click request's t202pubid can name, and where the click scripts ask
 * PublisherCredit about it. The grant itself is PublisherCreditIntegrationTest's.
 */
final class PublisherCreditTest extends TestCase
{
    /** Every script that records a new click from a link a publisher may hold. */
    private const ENTRY_POINTS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_simple.php',
        'tracking202/static/record_adv.php',
    ];

    public function testOnlyAPublicIdShapedValueIsRead(): void
    {
        self::assertSame('c0c0c0c0c0', PublisherCredit::requested(['t202pubid' => 'c0c0c0c0c0']));
        foreach ([null, '', 'c0c0c0c0c0c', "c0' OR '1", ['c0'], 12345, ' c0c0'] as $value) {
            self::assertNull(PublisherCredit::requested(['t202pubid' => $value]), var_export($value, true));
        }
        self::assertNull(PublisherCredit::requested([]));
    }

    public function testAClickIsNeverCreditedToItsOwnAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClickRecord(clickId: 1, userId: 7, clickTime: 1, publisherUserId: 7);
    }

    public function testEveryEntryPointAsksForTheGrantedPublisher(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ENTRY_POINTS as $path) {
            $source = (string) file_get_contents($root . '/' . $path);
            self::assertMatchesRegularExpression(
                '/p202ClickPublisher\(\$db,[^;]+\$_GET\)/',
                $source,
                "$path must read t202pubid through p202ClickPublisher(), which checks the grant"
            );
            self::assertStringContainsString("\$mysql['publisher_user_id']", $source, $path);
            self::assertDoesNotMatchRegularExpression(
                '/\$_(GET|REQUEST)\s*\[\s*[\'"]t202pubid/',
                $source,
                "$path reads t202pubid itself: an id is a claim until PublisherCredit checks it"
            );
        }
        $landing = (string) file_get_contents($root . '/tracking202/static/landing.php');
        self::assertStringContainsString("\"t202pubid=\" + t202Enc(t202GetVar('t202pubid'))", $landing);
    }
}
