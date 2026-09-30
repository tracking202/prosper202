<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Every Slack event the tree sends has a message defined for it.
 *
 * `Slack::getMessage()` returns `''` for a placeholder it does not know, and
 * `Slack::push()` turns `''` into `return false` — so a send with a name
 * nobody defined is a silent no-op. There is no exception, no log line, and
 * no difference in the calling code between "sent" and "there was nothing to
 * send": the installation has a webhook configured, the user performs the
 * action, and nothing arrives. The only symptom is the message a person
 * never sees.
 *
 * Three of these were in the tree when this was written — one added with the
 * mobile-apps page, two older — which is what a failure mode with no output
 * looks like after a while.
 *
 * Two call shapes are checked, because both exist:
 *
 *  - `push('name')` / `sendSlackNotification('name')`: the name must be a
 *    defined message.
 *  - `sendSlackNotification('prefix_' . $suffix)`: the suffix is a runtime
 *    value, so the exact key cannot be known here. At least one defined
 *    message must start with the prefix — enough to catch a family nobody
 *    defined at all, which is the case that actually happened
 *    (`attribution_model_` with no `attribution_model_*` anywhere).
 */
final class SlackPlaceholderIsDefinedTest extends TestCase
{
    private const SLACK_CLASS = '/202-config/Slack.class.php';

    public function testEveryPlaceholderSentIsDefined(): void
    {
        $defined = $this->definedPlaceholders();
        // A floor: a regex that matched nothing would make this test pass by
        // having no work to do. The table is ~90 entries and only grows.
        $this->assertGreaterThan(50, count($defined), 'the message table was not parsed');

        $undefined = [];
        $families = [];
        foreach ($this->phpFiles() as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            if (!str_contains($source, 'push(') && !str_contains($source, 'sendSlackNotification(')) {
                continue;
            }
            $relative = substr($file, strlen($this->repoRoot()));

            // 'name' . $something — a family, checked by prefix.
            preg_match_all(
                '/(?:->push|sendSlackNotification)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\.\s*\$/i',
                $source,
                $dynamic
            );
            foreach ($dynamic[1] as $prefix) {
                $families[$prefix][] = $relative;
            }

            // 'name' on its own.
            preg_match_all(
                '/(?:->push|sendSlackNotification)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*[,)]/i',
                $source,
                $literal
            );
            foreach ($literal[1] as $placeholder) {
                if (!isset($defined[$placeholder])) {
                    $undefined[] = "$placeholder (sent from $relative)";
                }
            }
        }

        foreach ($families as $prefix => $where) {
            $matched = false;
            foreach (array_keys($defined) as $name) {
                if (str_starts_with($name, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $sent = implode(', ', array_unique($where));
                $undefined[] = "$prefix* (no message begins with it; sent from $sent)";
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($undefined)),
            "These Slack events send nothing at all — Slack::getMessage() has no entry, so push() returns false.\n"
            . 'Add the message to ' . ltrim(self::SLACK_CLASS, '/') . ", with the variables the call site supplies."
        );
    }

    /**
     * The keys of Slack::getMessage()'s static table.
     *
     * Read as text: the method is private and the class is not autoloadable
     * from here without a webhook URL.
     *
     * @return array<string, true>
     */
    private function definedPlaceholders(): array
    {
        $source = file_get_contents($this->repoRoot() . self::SLACK_CLASS);
        $this->assertIsString($source, 'Slack.class.php could not be read');
        $this->assertSame(
            1,
            preg_match('/static \$messages = \[(.*?)\n\s*\];/s', $source, $table),
            'the $messages table could not be located; this test reads it by shape'
        );

        preg_match_all('/^\s*[\'"]([a-z0-9_]+)[\'"]\s*=>/mi', $table[1], $keys);

        return array_fill_keys($keys[1], true);
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = $this->repoRoot();
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $entry) {
            $path = $entry->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            // vendor/ is third-party; tests/ may name a placeholder as data.
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/.git/')) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
