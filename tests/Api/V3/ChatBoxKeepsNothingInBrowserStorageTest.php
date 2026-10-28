<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The chat box asks 202-account/ajax/chat-pass.php for its pass on every page
 * and keeps nothing in browser storage. The install's session caches the pass
 * against everything it was issued for (API key, install, user, email, name);
 * a browser copy can only be keyed on less, so it outlives an API key change
 * and crosses between two installs on one origin. This reads the source, so
 * storage reached through a computed name is not seen;
 * tests/browser/specs/chat-box.spec.js checks the behaviour in a browser.
 */
final class ChatBoxKeepsNothingInBrowserStorageTest extends TestCase
{
    public function testTheChatBoxNamesNoBrowserStorage(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/202-js/p202-chat.js');
        self::assertIsString($script);
        foreach (['sessionStorage', 'localStorage', 'indexedDB', 'document.cookie'] as $store) {
            self::assertStringNotContainsString(
                $store,
                $script,
                '202-js/p202-chat.js uses ' . $store . '; ask chat-pass.php instead, whose session cache knows when the pass no longer fits'
            );
        }
    }
}
