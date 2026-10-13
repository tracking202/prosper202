<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\IpCriterion;

/**
 * A redirector rule's IP criterion: one address however it is spelled, on
 * the rule's side and the visitor's.
 */
final class IpCriterionTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> rule value, visitor address, matches */
    public static function ruleMatches(): iterable
    {
        yield 'the same text' => ['203.0.113.50', '203.0.113.50', true];
        yield 'another address' => ['203.0.113.50', '203.0.113.51', false];
        yield 'upper-case IPv6' => ['2001:DB8::77', '2001:db8::77', true];
        yield 'IPv6 with its zeros written out' => ['2001:0db8:0:0::77', '2001:db8::77', true];
        yield 'the visitor written out' => ['2001:db8::77', '2001:0DB8:0000:0000:0000:0000:0000:0077', true];
        yield 'the second address after a space' => ['203.0.113.50, 198.51.100.88', '198.51.100.88', true];
        yield 'padding around an address' => [' 198.51.100.88 ', '198.51.100.88', true];
        yield 'a value that is not an address' => ['203.0.113.500', '203.0.113.5', false];
        yield 'a range is not an address' => ['203.0.113.0/24', '203.0.113.7', false];
        yield 'no visitor address' => ['203.0.113.50', '', false];
        yield 'an IPv4 visitor and an IPv4-mapped rule' => ['::ffff:203.0.113.50', '203.0.113.50', false];
    }

    /** @dataProvider ruleMatches */
    public function testContains(string $rule, string $visitor, bool $expected): void
    {
        self::assertSame($expected, IpCriterion::contains(explode(',', $rule), $visitor));
    }

    /** @return iterable<string, array{string, string, list<string>}> typed, stored, refused */
    public static function writes(): iterable
    {
        yield 'canonical already' => ['203.0.113.50', '203.0.113.50', []];
        yield 'as a person types them' => [
            '2001:DB8:0:0::77, 198.51.100.88,2001:db8::77',
            '2001:db8::77,198.51.100.88',
            [],
        ];
        yield 'empty items are dropped' => ['203.0.113.50,,', '203.0.113.50', []];
        yield 'a typo is named' => ['203.0.113.50, 203.0.113.500', '203.0.113.50', ['203.0.113.500']];
        yield 'a range is named' => ['203.0.113.0/24', '', ['203.0.113.0/24']];
        yield 'nothing' => [' , ', '', []];
    }

    /**
     * @dataProvider writes
     * @param list<string> $invalid
     */
    public function testNormalize(string $typed, string $stored, array $invalid): void
    {
        self::assertSame(['value' => $stored, 'invalid' => $invalid], IpCriterion::normalize($typed));
    }

    public function testEveryIpArmOfTheRedirectsComparesThroughIpCriterion(): void
    {
        foreach (['tracking202/redirect/rtr.php', 'tracking202/redirect/offrtr.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            $arms = preg_match_all("/case 'ip':(.*?)break;/s", $source, $m);
            self::assertSame(1, $arms, "$file has one ip arm");
            self::assertSame(
                2,
                substr_count($m[1][0], 'IpCriterion::contains($values, $ip_address)'),
                "$file: is and is_not"
            );
            self::assertStringNotContainsString('in_array(', $m[1][0], "$file compares the text again");
        }
    }

    /** @return iterable<string, array{string, ?string}> typed, refusal */
    public static function refusals(): iterable
    {
        yield 'addresses' => ['2001:DB8::77, 198.51.100.88', null];
        yield 'a typo' => [
            '203.0.113.50, 203.0.113.500',
            '"203.0.113.500" is not an IP address, so the rule would never match.'
                . ' List single addresses, comma-separated (ranges are not supported).',
        ];
        yield 'two ranges' => [
            '203.0.113.0/24,198.51.100.0/24',
            '"203.0.113.0/24", "198.51.100.0/24" is not an IP address, so the rule would never match.'
                . ' List single addresses, comma-separated (ranges are not supported).',
        ];
        yield 'nothing' => [' , ', 'An IP address rule needs at least one address.'];
    }

    /** @dataProvider refusals */
    public function testRefusal(string $typed, ?string $refusal): void
    {
        self::assertSame($refusal, IpCriterion::refusal($typed));
    }

    /**
     * The Redirectors page's save (a script, so pinned by its source; the
     * live pass posts to it): a posted criterion value is read in two
     * statements, the check that refuses what refusal() names and the write
     * that stores the canonical list, and nowhere else; and the page's
     * refusal answers "ERROR: <reason>", which p202-setup.js shows.
     */
    public function testThePageSaveChecksAndStoresAnIpCriterionThroughIpCriterion(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/ajax/rotator.php');
        self::assertSame(3, substr_count($source, "\$criteria['value']"), 'a new read of the posted value');
        $check = "\t\t\tif ((\$criteria['type'] ?? '') === 'ip'"
            . " && (\$reason = \\Prosper202\\Rotator\\IpCriterion::refusal("
            . "(string) (\$criteria['value'] ?? ''))) !== null) {\n"
            . "\t\t\t\t\$refuse(\$reason);\n"
            . "\t\t\t}\n";
        self::assertSame(1, substr_count($source, $check), 'the check');
        $refuse = "\t\$refuse = static function (?string \$reason = null): never {\n"
            . "\t\theader('Content-Type: text/plain; charset=utf-8'); // a reason repeats what was typed\n"
            . "\t\tdie(\$reason === null ? \"ERROR\" : 'ERROR: ' . \$reason);\n"
            . "\t};\n";
        self::assertSame(1, substr_count($source, $refuse), 'the refusal answers ERROR: <reason>');
        $write = "\$value = \$db->real_escape_string(\$criteria['type'] === 'ip'"
            . " ? \\Prosper202\\Rotator\\IpCriterion::normalize((string) \$criteria['value'])['value']"
            . " : \$criteria['value']);";
        self::assertSame(1, substr_count($source, $write), 'the write');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/202-js/p202-setup.js');
        self::assertStringContainsString("result.indexOf('ERROR: ') === 0 ? result.slice(7) : ''", $script);
    }
}
