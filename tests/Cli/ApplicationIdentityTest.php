<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Two command-line tools answer to `p202`: this legacy PHP CLI (bin/p202)
 * and the Go CLI in go-cli/. Nothing in this one said which it was. Its
 * --version line, `list` and the bare command now name it and point at the
 * Go CLI and its documentation; and its config:show reads the file both
 * share without printing the other CLI's keys.
 */
final class ApplicationIdentityTest extends TestCase
{
    private string $home;
    private string $origHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/p202_identity_' . bin2hex(random_bytes(4));
        mkdir($this->home . '/.p202', 0700, true);
        $this->origHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
    }

    protected function tearDown(): void
    {
        @unlink($this->home . '/.p202/config.json');
        @rmdir($this->home . '/.p202');
        @rmdir($this->home);
        putenv('HOME=' . $this->origHome);
        parent::tearDown();
    }

    private function runCli(array $input): string
    {
        $app = new Application();
        $app->setAutoExit(false);
        $output = new BufferedOutput();
        $app->run(new ArrayInput($input), $output);

        return $output->fetch();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entryPoints(): iterable
    {
        yield '--version' => [['--version' => true]];
        yield 'list' => [['command' => 'list']];
        yield 'no command' => [[]];
    }

    /**
     * @dataProvider entryPoints
     * @param array<string, mixed> $input
     */
    public function testItSaysItIsTheLegacyPhpCliAndWhereTheGoCliIs(array $input): void
    {
        $text = $this->runCli($input);
        self::assertStringContainsString('legacy PHP CLI (bin/p202)', $text);
        self::assertStringContainsString('go-cli/', $text);
        self::assertStringContainsString('documentation/cli/10-go-cli.md', $text);
        self::assertFileExists(dirname(__DIR__, 2) . '/documentation/cli/10-go-cli.md', 'the page it points at exists');
    }

    public function testConfigShowPrintsTheActiveProfileMaskedAndNoOtherKeys(): void
    {
        file_put_contents($this->home . '/.p202/config.json', json_encode([
            'active_profile' => 'prod',
            'profiles' => [
                'default' => ['url' => 'https://staging.example', 'api_key' => 'staging-secret-key-0001'],
                'prod' => ['url' => 'https://prod.example', 'api_key' => 'prod-secret-key-0002'],
            ],
        ]));

        $text = $this->runCli(['command' => 'config:show']);

        self::assertStringContainsString('profile: prod', $text);
        self::assertStringContainsString('url: https://prod.example', $text);
        self::assertStringContainsString('api_key: prod...0002', $text);
        self::assertStringNotContainsString('staging-secret-key-0001', $text, "another profile's key is not printed");
        self::assertStringNotContainsString('prod-secret-key-0002', $text, 'the key is masked');
        self::assertStringNotContainsString('Array', $text);
    }
}
