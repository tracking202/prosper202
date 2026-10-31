<?php

declare(strict_types=1);

namespace Tests\Lpo;

use PHPUnit\Framework\TestCase;
use Prosper202\Lpo\PairingWebhook;

/**
 * What the pairing card says for each answer PairingWebhook::state() gives,
 * and for none: only an active webhook is said to receive events.
 * PairingCardWebhookStatusIntegrationTest draws the card for each.
 */
final class PairingWebhookTest extends TestCase
{
    public function testOnlyAnActiveWebhookIsReceiving(): void
    {
        self::assertSame(['', ''], PairingWebhook::notice(['state' => PairingWebhook::RECEIVING, 'webhook_id' => 7]));

        $said = [];
        foreach ([PairingWebhook::DEAD, PairingWebhook::OFF, PairingWebhook::GONE, PairingWebhook::UNNAMED] as $state) {
            [$kind, $text] = PairingWebhook::notice(['state' => $state, 'webhook_id' => 7]);
            self::assertContains($kind, ['bad', 'warn'], $state);
            self::assertNotSame('', $text, $state);
            $said[$state] = $text;
        }
        [$kind, $text] = PairingWebhook::notice(null);
        self::assertSame('warn', $kind, 'a status that could not be read');
        self::assertStringContainsString('could not be read', $text);
        $said['unread'] = $text;
        self::assertCount(5, array_unique($said), 'each answer says something of its own');
    }

    public function testADeadWebhookNamesItsIdAndWhereToReviveIt(): void
    {
        [$kind, $text] = PairingWebhook::notice(['state' => PairingWebhook::DEAD, 'webhook_id' => 12]);
        self::assertSame('bad', $kind);
        self::assertSame(
            'The optimizer is not receiving events: its webhook (#12) was stopped after a delivery to it failed'
                . ' 6 times. Fix the endpoint, then revive the webhook in LTV Settings.',
            $text
        );
    }
}
