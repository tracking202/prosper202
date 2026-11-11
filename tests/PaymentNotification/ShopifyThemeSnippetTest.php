<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRef;
use Prosper202\PaymentNotification\Shopify\ThemeSnippet;

/**
 * The theme snippet the Shopify card hands out, run under node against a
 * stand-in for the store's page: what it adds to the add-to-cart forms is
 * what ShopifyAdapter reads from an order's line items.
 */
final class ShopifyThemeSnippetTest extends TestCase
{
    public function testTheSnippetIsOneScriptElement(): void
    {
        self::assertMatchesRegularExpression('#\A<script>\n.*\n</script>\z#s', ThemeSnippet::html());
        self::assertSame(1, substr_count(ThemeSnippet::html(), '</script'));
        self::assertStringEndsWith(ThemeSnippet::PARAMETER . '=[[subid]]', ThemeSnippet::URL_SUFFIX);
    }

    public function testAClickIdOnTheUrlIsAddedToEveryCartFormAndKeptForLaterPages(): void
    {
        $landing = self::runSnippet('?utm_source=newsletter&t202subid=424242', null);
        self::assertSame(['424242', '424242'], $landing['added']);
        self::assertSame('_t202subid', self::property($landing['name']));
        self::assertNotNull(ClickRef::parse($landing['added'][0]));

        // The product page the buyer reaches next carries no parameter.
        $next = self::runSnippet('', $landing['stored']);
        self::assertSame(['424242', '424242'], $next['added']);
        // A form the theme adds later (a quick-add drawer) is tagged, and none is tagged twice.
        self::assertSame(['424242', '424242', '424242'], $next['afterNewForm']);
    }

    public function testASignedSubidIsKeptWhole(): void
    {
        $subid = '424242-17-0123456789ab';
        self::assertSame([$subid, $subid], self::runSnippet('?t202subid=' . $subid, null)['added']);
    }

    public function testNothingIsAddedWithoutAClickIdOrWithOneThatIsNotOne(): void
    {
        self::assertSame([], self::runSnippet('', null)['added']);
        self::assertSame([], self::runSnippet('?t202subid=', null)['added']);
        self::assertSame([], self::runSnippet('?t202subid=%3Cscript%3E', null)['added']);
        self::assertSame([], self::runSnippet('?xt202subid=5', null)['added']);
        // Kept longer than 30 days ago: the buyer's click is no longer theirs.
        $old = (string) json_encode(['id' => '424242', 'at' => (time() - 31 * 86400) * 1000]);
        self::assertSame([], self::runSnippet('', $old)['added']);
        self::assertSame([], self::runSnippet('', 'not json')['added']);
    }

    private static function property(string $inputName): string
    {
        self::assertSame(1, preg_match('/^properties\[(.+)\]$/', $inputName, $m));

        return $m[1];
    }

    /**
     * @return array{added: list<string>, afterNewForm: list<string>, name: string, stored: ?string}
     */
    private static function runSnippet(string $search, ?string $stored): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            // CI's runners carry node: a run there that cannot find it has not checked anything.
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the snippet was not run');
            self::markTestSkipped('node is not on PATH');
        }
        self::assertSame(1, preg_match('#\A<script>\n(.*)\n</script>\z#s', ThemeSnippet::html(), $m));
        $program = <<<'JS'
            var input = JSON.parse(require('fs').readFileSync(0, 'utf8'));
            var store = input.stored === null ? {} : { t202subid: input.stored };
            function form(action) {
              return { action: action, inputs: [],
                querySelector: function () { return this.inputs.length ? this.inputs[0] : null; },
                appendChild: function (el) { this.inputs.push(el); } };
            }
            var forms = [form('/cart/add'), form('/cart/add'), form('/search')];
            var observed = null;
            var window = {
              location: { search: input.search },
              localStorage: {
                getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
                setItem: function (k, v) { store[k] = String(v); }
              },
              MutationObserver: function (fn) { observed = fn; this.observe = function () {}; }
            };
            var MutationObserver = window.MutationObserver;
            var document = {
              documentElement: {},
              addEventListener: function () {},
              createElement: function () { return {}; },
              querySelectorAll: function (selector) {
                if (selector !== 'form[action*="/cart/add"]') { throw new Error('unexpected selector ' + selector); }
                return forms.filter(function (f) { return f.action.indexOf('/cart/add') !== -1; });
              }
            };
            SNIPPET
            function values() {
              var out = [];
              forms.forEach(function (f) { f.inputs.forEach(function (i) { out.push(i.value); }); });
              return out;
            }
            var added = values();
            var name = forms[0].inputs.length ? forms[0].inputs[0].name : '';
            if (observed) { forms.push(form('/cart/add')); observed(); observed(); }
            process.stdout.write(JSON.stringify({ added: added, afterNewForm: values(), name: name,
              stored: Object.prototype.hasOwnProperty.call(store, 't202subid') ? store.t202subid : null }));
            JS;
        $program = str_replace('SNIPPET', $m[1], $program);
        $streams = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$node, '-e', $program], $streams, $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], (string) json_encode(['search' => $search, 'stored' => $stored]));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err);
        $answer = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }
}
