<?php

declare(strict_types=1);

namespace Tests\Click;

use Api\V3\Controllers\TrackersController;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Click\TrackingLinkVariables;

/**
 * The parts of a tracking link the API now builds the way Get Links does:
 * the base (domain, scheme, install path) and the variable query.
 */
final class TrackingLinkPartsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testAnEmptyDomainFallsBackToThisServerWithItsPort(): void
    {
        $server = ['SERVER_NAME' => 'p202.local', 'SERVER_PORT' => '8098', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('http://p202.local:8098/', TrackingBaseUrl::build('', $server, self::root()));
        $server['SERVER_PORT'] = '80';
        self::assertSame('http://p202.local/', TrackingBaseUrl::build('', $server, self::root()));
    }

    /**
     * A URL handed back to the requester (a page's links, the API's): with
     * no domain stored, the host the request came in on, port included —
     * not the port the server listens on behind a proxy or a published
     * container port. A URL sent to anyone else keeps build()'s fallback
     * above.
     */
    public function testAnEmptyDomainAnswersTheRequesterOnTheHostItUsed(): void
    {
        $server = [
            'SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080', 'DOCUMENT_ROOT' => self::root(),
            'HTTP_HOST' => 'proxy.example:9443', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        self::assertSame('proxy.example:9443', TrackingBaseUrl::domainForResponse('', $server));
        self::assertSame('https://proxy.example:9443/', TrackingBaseUrl::buildForResponse('', $server, self::root()));
        $built = TrackingBaseUrl::build('', $server, self::root());
        self::assertSame('https://internal:8080/', $built, 'build() is unchanged');

        self::assertSame('track.example.com', TrackingBaseUrl::domainForResponse('http://track.example.com/', $server));
        $stored = TrackingBaseUrl::buildForResponse('http://track.example.com/', $server, self::root());
        self::assertSame('http://track.example.com/', $stored);

        unset($server['HTTP_HOST']);
        $noHost = TrackingBaseUrl::domainForResponse('', $server);
        self::assertSame('internal:8080', $noHost, 'no Host header: the server');
    }

    public function testAHostOnlyDomainGetsTheRequestsScheme(): void
    {
        $server = ['SERVER_NAME' => 'internal', 'SERVER_PORT' => '443', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('https://track.example.com/', TrackingBaseUrl::build('track.example.com', $server, self::root()));
        unset($server['HTTPS']);
        $server['SERVER_PORT'] = '80';
        self::assertSame('http://track.example.com/', TrackingBaseUrl::build('track.example.com', $server, self::root()));
    }

    public function testADomainStoredWithASchemeKeepsIt(): void
    {
        $server = ['SERVER_NAME' => 'internal', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => self::root()];
        self::assertSame('http://127.0.0.1:8098/', TrackingBaseUrl::build('http://127.0.0.1:8098/', $server, self::root()));
    }

    /** @return iterable<string, array{string, ?string, ?string}> stored => storedScheme(), storedSchemeForResponse() */
    public static function storedDomains(): iterable
    {
        yield 'nothing' => ['', null, null];
        yield 'a host' => ['track.example.com', null, null];
        yield 'https' => ['https://track.example.com/', 'https', 'https'];
        yield 'http, any case, after a space' => [' HTTP://track.example.com', 'http', 'http'];
        yield 'scheme-relative' => ['//track.example.com', null, null];
        yield 'another scheme' => ['ftp://track.example.com', null, null];
        yield 'a scheme with no host' => ['https://', 'https', null];
    }

    /** @dataProvider storedDomains */
    public function testTheSchemeADomainIsSavedWith(string $stored, ?string $scheme, ?string $forResponse): void
    {
        self::assertSame($scheme, TrackingBaseUrl::storedScheme($stored));
        self::assertSame($forResponse, TrackingBaseUrl::storedSchemeForResponse($stored));
    }

    /**
     * storedSchemeForResponse() names the scheme buildForResponse() takes:
     * the saved one whatever the request came over, and the request's
     * when none is saved with the domain.
     *
     * @dataProvider storedDomains
     */
    public function testTheSavedSchemeIsTheOneTheLinksTake(string $stored): void
    {
        $plain = ['HTTP_HOST' => 'p202.example', 'SERVER_PORT' => '80', 'DOCUMENT_ROOT' => self::root()];
        foreach ([$plain, ['HTTP_X_FORWARDED_PROTO' => 'https'] + $plain] as $server) {
            $base = TrackingBaseUrl::buildForResponse($stored, $server, self::root());
            $request = isset($server['HTTP_X_FORWARDED_PROTO']) ? 'https' : 'http';
            $expected = TrackingBaseUrl::storedSchemeForResponse($stored) ?? $request;
            self::assertStringStartsWith($expected . '://', $base, $stored . ' over ' . $request);
        }
    }

    /**
     * The pages' link base (p202TrackingBaseForResponse(), functions-shared.php)
     * is the API's (TrackingBaseLookup), install directory included, so Get
     * Links and GET /trackers/{id}/url give one URL. Both run in a child PHP,
     * the page's from its own file, the API's through the trait with the
     * domain read stubbed.
     */
    public function testThePagesLinkBaseIsTheApis(): void
    {
        $code = 'require ' . var_export(self::root() . '/vendor/autoload.php', true) . ';'
            . ' require ' . var_export(self::root() . '/202-config/functions-shared.php', true) . ';'
            . <<<'PHP'
            $api = new class {
                use \Api\V3\Support\TrackingBaseLookup;
                public string $domain = '';
                protected function trackingDomain(): string { return $this->domain; }
                public function base(array $server): string { return $this->trackingBaseUrl($server); }
            };
            $out = [];
            foreach (json_decode($argv[1], true) as $name => [$stored, $server]) {
                $_SERVER = $server;
                $api->domain = $stored;
                $out[$name] = [p202TrackingBaseForResponse($stored), $api->base($server)];
            }
            echo json_encode($out);
            PHP;
        $docRoot = dirname(self::root());
        $plain = [
            'HTTP_HOST' => 'proxy.example:9443', 'SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080',
            'DOCUMENT_ROOT' => $docRoot,
        ];
        $cases = [
            'nothing saved, plain' => ['', $plain],
            'nothing saved, TLS proxy' => ['', ['HTTP_X_FORWARDED_PROTO' => 'https'] + $plain],
            'a host, HTTPS' => ['track.example.com', ['HTTPS' => 'on'] + $plain],
            'saved https, plain' => ['https://track.example.com/', $plain],
            'saved http, TLS proxy' => ['http://track.example.com', ['HTTP_X_FORWARDED_PROTO' => 'https'] + $plain],
        ];
        $command = [PHP_BINARY, '-r', $code, '--', (string) json_encode($cases)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "the child answered: $out $err");
        $dir = '/' . basename(self::root()) . '/';
        self::assertSame([
            'nothing saved, plain' => 'http://proxy.example:9443' . $dir,
            'nothing saved, TLS proxy' => 'https://proxy.example:9443' . $dir,
            'a host, HTTPS' => 'https://track.example.com' . $dir,
            'saved https, plain' => 'https://track.example.com' . $dir,
            'saved http, TLS proxy' => 'http://track.example.com' . $dir,
        ], array_map(static fn (array $pair): string => $pair[0], $seen), 'the pages');
        foreach ($seen as $name => [$page, $api]) {
            self::assertSame($page, $api, $name . ': the API');
        }
    }

    public function testAnInstallInASubdirectoryKeepsTheDirectory(): void
    {
        $server = ['SERVER_NAME' => 'x', 'DOCUMENT_ROOT' => dirname(self::root())];
        self::assertSame('/' . basename(self::root()) . '/', TrackingBaseUrl::installPath($server, self::root()));
        self::assertSame('/', TrackingBaseUrl::installPath(['DOCUMENT_ROOT' => '/nonexistent'], self::root()), 'unknown: the root');
    }

    public function testVariablesFollowGetLinksRules(): void
    {
        self::assertSame('&t202kw=', TrackingLinkVariables::query([], []), 't202kw is always written');
        $custom = [
            ['parameter' => 'adid', 'placeholder' => '{ad_id}'],
            ['parameter' => 't202kw', 'placeholder' => '{keyword}'],
            ['parameter' => 'c2', 'placeholder' => '{placement}'],
        ];
        self::assertSame('&adid={ad_id}&c2={placement}&t202kw={keyword}', TrackingLinkVariables::query($custom, []));
        self::assertSame('&adid={ad_id}&c1=fb&c2={placement}&t202kw=kw', TrackingLinkVariables::query($custom, ['c1' => 'fb', 't202kw' => 'kw']));
        self::assertNull(TrackingLinkVariables::problem('{keyword}'));
        foreach (['a&b', 'a#b', 'a?b', 'a b', "a\nb"] as $bad) {
            self::assertNotNull(TrackingLinkVariables::problem($bad), $bad);
        }
    }

    public function testVariablesGoBeforeALandingPagesFragment(): void
    {
        $url = TrackersController::buildDirectUrl('https://trk.example.com/', 555, [
            'landing_page_id' => 1, 'landing_page_url' => 'https://lp.example.com/a?x=1#top',
        ], '&t202kw=kw');
        self::assertSame('https://lp.example.com/a?x=1&t202id=555&t202kw=kw#top', $url);
        self::assertSame(
            'https://trk.example.com/sub/tracking202/redirect/dl.php?t202id=555&t202kw=',
            TrackersController::buildDirectUrl('https://trk.example.com/sub/', 555, ['landing_page_id' => 0], '&t202kw=')
        );
    }
}
