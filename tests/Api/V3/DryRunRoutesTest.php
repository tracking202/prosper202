<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * `?dry_run=1` on a write either previews or is refused; it is never
 * ignored. A DELETE previews through $previewRouter or is refused; a POST,
 * PUT or PATCH previews only where its handler reads writeDryRunRequested()
 * -- the routes listed on $writePreviewRouter -- and the dispatcher refuses
 * it everywhere else.
 *
 * The parameter used to be read for a DELETE alone, so on any other write
 * it was ignored and the write ran: measured live, PUT
 * /system/retention?dry_run=1 (the spelling that route's own 422 for a body
 * dry_run gave) changed the retention setting, and POST
 * /aff-networks?dry_run=1 created a category. This test holds the list and
 * the handlers to each other in both directions, and the dispatcher to its
 * refusal; DryRunWritesInstanceTest proves the refusal and the previews
 * over HTTP.
 */
final class DryRunRoutesTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** @return array{0: list<string>, 1: list<string>} [handlers that read the flag, listed previews] as "METHOD /path" */
    private function sides(string $src): array
    {
        $reads = [];
        $listed = [];
        foreach ($this->registrations($src) as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            if ($route['router'] === '$writePreviewRouter') {
                $listed[] = $key;
                continue;
            }
            if ($route['router'] !== '$router') {
                continue;
            }
            if (str_contains($route['handler'], 'writeDryRunRequested(')) {
                self::assertContains($route['method'], ['POST', 'PUT', 'PATCH'], "$key (line {$route['line']}) previews a write");
                self::assertStringContainsString('writeDryRunRequested($queryParams)', $route['handler'], "$key (line {$route['line']}) reads the request's own dry_run");
                $reads[] = $key;
            } else {
                // A handler that reads the parameter any other way is one
                // this test cannot hold to the list.
                self::assertStringNotContainsString('dry_run', $route['handler'], "$key (line {$route['line']}) reads dry_run other than through writeDryRunRequested()");
            }
        }
        sort($reads);
        sort($listed);

        return [$reads, $listed];
    }

    public function testEveryPreviewingWriteIsListedAndEveryListedRoutePreviews(): void
    {
        [$reads, $listed] = $this->sides(self::indexSource());
        self::assertSame([
            'POST /clicks/cpc',
            'POST /conversions/subids',
            'POST /conversions/subids/delete',
            'POST /conversions/subids/reset',
            'POST /conversions/uploads',
            'POST /system/retention/delete-before',
        ], $reads, 'the writes whose handlers preview');
        self::assertSame($reads, $listed, '$writePreviewRouter lists exactly the writes whose handlers preview');
    }

    /** The dispatcher reads dry_run for every write, and refuses it where no preview is listed. */
    public function testTheDispatcherRefusesAPreviewNoRouteHas(): void
    {
        $src = self::indexSource();
        self::assertSame(1, substr_count($src, '$writePreviewRouter = new Router();'), 'one list');
        self::assertMatchesRegularExpression(
            "/\\\$dryRun = \\\$method === 'DELETE'\\s*\\?\\s*deleteDryRunRequested\\(\\\$method, \\\$queryParams\\)\\s*:\\s*in_array\\(\\\$method, \\['POST', 'PUT', 'PATCH'\\], true\\) && writeDryRunRequested\\(\\\$queryParams\\);/",
            $src,
            'dry_run is read for a DELETE, a POST, a PUT and a PATCH'
        );
        self::assertMatchesRegularExpression(
            "/\\} elseif \\(\\\$dryRun && \\\$writePreviewRouter->match\\(\\\$method, \\\$path\\) === null\\) \\{\\s*(\\/\\/[^\\n]*\\n\\s*)*throw new ValidationException\\('dry_run is not supported for this endpoint'/",
            $src,
            'a write with no listed preview is refused before its handler runs'
        );
        // The refusal sits in the branch that runs the handler, after the
        // DELETE preview branch and before the handler call.
        $refusal = strpos($src, '} elseif ($dryRun && $writePreviewRouter->match($method, $path) === null) {');
        $deletePreview = strpos($src, 'if ($dryRun && $method === \'DELETE\') {');
        $handlerCall = strpos($src, '$response = ($match[\'handler\'])($match[\'pathParams\']);', (int) $refusal);
        self::assertNotFalse($refusal);
        self::assertNotFalse($deletePreview);
        self::assertNotFalse($handlerCall);
        self::assertLessThan($refusal, $deletePreview);
        self::assertSame(1, substr_count($src, '$response = ($match[\'handler\'])($match[\'pathParams\']);'), 'the handler is called in one place');
        // Staged and dry_run together are refused for every write, not
        // only a DELETE.
        self::assertLessThan(
            strpos($src, '$scopeArea = scopeAreaForPath($path);'),
            strpos($src, 'if ($stagedWrite && $dryRun) {'),
            'staged with dry_run is refused before anything else reads the request'
        );
    }

    /** The two sides are read, not assumed: a handler or a listing planted on one side only is reported. */
    public function testAPreviewOnOneSideOnlyIsSeen(): void
    {
        $src = <<<'PHP'
<?php
$router->post('/clicks/cpc', fn() => $c->cpc($payload, writeDryRunRequested($queryParams)));
$router->put('/system/retention', fn() => $c->setRetention($payload, writeDryRunRequested($queryParams)));
$writePreviewRouter = new Router();
$writePreviewRouter->post('/clicks/cpc', $writePreview);
$writePreviewRouter->post('/aff-networks', $writePreview);
PHP;
        [$reads, $listed] = $this->sides($src);
        self::assertSame(['POST /clicks/cpc', 'PUT /system/retention'], $reads);
        self::assertSame(['POST /aff-networks', 'POST /clicks/cpc'], $listed);
        self::assertNotSame($reads, $listed);
    }
}
