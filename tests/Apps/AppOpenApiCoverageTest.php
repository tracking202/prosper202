<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Apps\Apple\Protocols;
use Api\V3\Apps\Apple\SignatureState;
use Api\V3\Controllers\AppPostbacksController;
use Tests\TestCase;

/**
 * The OpenAPI document describes what the postback endpoints actually
 * serve: every column the postback list selects is a documented property,
 * the documented protocol and signature enums are the server's own sets,
 * and both well-known receiver paths are documented. Columns were added to
 * the row in three commits during the AdAttributionKit work and the spec
 * had to be patched by hand each time; this makes the omission a failing
 * test instead of a reviewer's catch. Textual on purpose — the repository
 * carries no YAML parser — so the spec's layout (one property per
 * `        name:` line under the schema) is part of the contract.
 */
final class AppOpenApiCoverageTest extends TestCase
{
    private function spec(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    }

    /** The body of one component schema, up to the next top-level component. */
    private function schemaBlock(string $name): string
    {
        $spec = $this->spec();
        $start = strpos($spec, "\n    $name:\n");
        $this->assertNotFalse($start, "schema $name is not in docs/openapi.yaml");
        $rest = substr($spec, $start + 1);
        $end = preg_match('/\n    [A-Z]\w*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1 ? $m[0][1] : strlen($rest);
        return substr($rest, 0, $end);
    }

    /** @return list<string> */
    private function documentedProperties(string $schema): array
    {
        $block = $this->schemaBlock($schema);
        preg_match_all('/^        (\w+):$/m', $block, $m);
        return $m[1];
    }

    public function testEveryListedPostbackColumnIsDocumented(): void
    {
        $constant = (new \ReflectionClass(AppPostbacksController::class))->getConstant('SELECT_COLUMNS');
        $this->assertIsString($constant);
        $columns = array_map('trim', explode(',', $constant));
        $this->assertGreaterThan(20, count($columns));

        $documented = $this->documentedProperties('AppPostback');
        $this->assertSame(
            [],
            array_values(array_diff($columns, $documented)),
            'columns the API serves but docs/openapi.yaml does not describe'
        );
    }

    public function testTheDocumentedProtocolAndSignatureSetsAreTheServers(): void
    {
        $block = $this->schemaBlock('AppPostback');

        $this->assertSame(1, preg_match('/        protocol:\n(?:.*\n){1,3}?\s+enum: \[([^\]]+)\]/', $block, $m), 'AppPostback.protocol has no enum');
        $this->assertEqualsCanonicalizing(Protocols::NAMES, array_map('trim', explode(',', $m[1])));

        $this->assertSame(1, preg_match('/        signature_state:\n(?:.*\n){1,3}?\s+enum: \[([^\]]+)\]/', $block, $m), 'AppPostback.signature_state has no enum');
        $this->assertEqualsCanonicalizing(SignatureState::values(), array_map('trim', explode(',', $m[1])));

        $spec = $this->spec();
        $this->assertSame(1, preg_match('/    postbackSignature:\n(?:.*\n){1,12}?\s+enum: \[([^\]]+)\]/', $spec, $m), 'the signature filter parameter has no enum');
        $this->assertEqualsCanonicalizing(SignatureState::values(), array_map('trim', explode(',', $m[1])));

        $this->assertSame(1, preg_match('/    postbackProtocol:\n(?:.*\n){1,8}?\s+enum: \[([^\]]+)\]/', $spec, $m), 'the protocol filter parameter has no enum');
        $this->assertEqualsCanonicalizing(
            array_merge(Protocols::NAMES, array_keys(Protocols::ALIASES)),
            array_map('trim', explode(',', $m[1]))
        );
    }

    public function testEveryWellKnownReceiverPathIsDocumented(): void
    {
        $spec = $this->spec();
        $root = dirname(__DIR__, 2);
        $paths = glob($root . '/.well-known/*/*/index.php') ?: [];
        $this->assertNotEmpty($paths);
        foreach ($paths as $index) {
            $path = '/' . str_replace($root . '/', '', dirname($index)) . '/';
            $this->assertStringContainsString("\n  $path:\n", $spec, "$path is served but not documented in docs/openapi.yaml");
        }
    }

    /**
     * The Android intake (PR 5): every column the install reads serve is a
     * documented AppInstall property, every documented match-state enum is
     * the server's MatchState set, and every install route is documented.
     */
    public function testTheAndroidInstallSurfaceIsDocumented(): void
    {
        $constant = (new \ReflectionClass(\Api\V3\Controllers\AppInstallsController::class))->getConstant('COLUMNS');
        $this->assertIsString($constant);
        $columns = array_map('trim', explode(',', $constant));
        $this->assertGreaterThan(25, count($columns));
        $this->assertSame([], array_values(array_diff($columns, $this->documentedProperties('AppInstall'))),
            'install columns the API serves but docs/openapi.yaml does not describe');

        $spec = $this->spec();
        $this->assertGreaterThanOrEqual(3, preg_match_all('/enum: \[(attributed, organic[^\]]*)\]/', $spec, $m));
        foreach ($m[1] as $enum) {
            $this->assertSame(\Api\V3\Apps\Android\MatchState::values(), array_map('trim', explode(',', $enum)), 'a documented match_state enum');
        }
        foreach (['/apps/installs', '/apps/installs/{install_uuid}/events', '/apps/{id}/installs', '/apps/{id}/installs/{install_uuid}', '/apps/{id}/install-token'] as $path) {
            $this->assertStringContainsString("\n  $path:\n", $spec, "$path is served but not documented");
        }
        foreach (['attribution_window_days', 'trust_client_revenue', 'integrity_mode', 'integrity_cloud_project_number'] as $field) {
            $this->assertContains($field, $this->documentedProperties('AppRegistration'));
        }

        // Play Integrity (PR 6): the routes, and every documented
        // integrity_state enum is the server's IntegrityState set.
        foreach (['/apps/{id}/integrity', '/apps/{id}/integrity-credential'] as $path) {
            $this->assertStringContainsString("\n  $path:\n", $spec, "$path is served but not documented");
        }
        $this->assertGreaterThanOrEqual(3, preg_match_all('/enum: \[(not_requested, received[^\]]*)\]/', $spec, $m));
        foreach ($m[1] as $enum) {
            $this->assertSame(\Api\V3\Apps\Android\Integrity\IntegrityState::values(), array_map('trim', explode(',', $enum)), 'a documented integrity_state enum');
        }
        $this->assertContains('app_registration_id', $this->documentedProperties('Campaign'));
    }
}
