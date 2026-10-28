<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Application;
use P202Cli\Commands\BaseCommand;
use P202Cli\Commands\UserPreferencesGetCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The server answers an integration secret only as `{"stored": <bool>}`;
 * user:prefs:get and user:prefs:update print that as (stored) or
 * (not stored), and --json prints the answer as the API gave it.
 */
final class UserPreferencesCommandsTest extends TestCase
{
    private const ANSWER = ['data' => [
        'user_id' => 1,
        'user_tracking_domain' => 't.example',
        'cb_key' => ['stored' => true],
        'ipqs_api_key' => ['stored' => false],
        'lpo_bridge_config' => ['stored' => true],
    ]];

    /** @return iterable<string, array{list<string>}> */
    public static function commands(): iterable
    {
        yield 'get' => [['user:prefs:get', '1']];
        yield 'update' => [['user:prefs:update', '1', '--ipqs-api-key=k']];
    }

    /**
     * @dataProvider commands
     * @param list<string> $argv
     */
    public function testAStoredSecretIsShownAsItsState(array $argv): void
    {
        $display = self::runCli($argv);

        self::assertMatchesRegularExpression('/^cb_key: \(stored\)$/m', $display);
        self::assertMatchesRegularExpression('/^ipqs_api_key: \(not stored\)$/m', $display);
        self::assertMatchesRegularExpression('/^lpo_bridge_config: \(stored\)$/m', $display);
        self::assertMatchesRegularExpression('/^user_tracking_domain: t\.example$/m', $display);
        self::assertStringNotContainsString('stored:', $display);
    }

    /**
     * @dataProvider commands
     * @param list<string> $argv
     */
    public function testJsonIsTheAnswerAsTheApiGaveIt(array $argv): void
    {
        $decoded = json_decode(self::runCli([...$argv, '--json']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::ANSWER, $decoded);
    }

    public function testOnlyAStoredStateIsRelabelled(): void
    {
        $answer = ['data' => ['a' => ['stored' => 'yes'], 'b' => ['stored' => true, 'n' => 1], 'c' => [], 'd' => 'x']];

        self::assertSame($answer, UserPreferencesGetCommand::withStoredStates($answer));
    }

    /** @param list<string> $argv */
    private static function runCli(array $argv): string
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $client = new class (self::ANSWER) extends ApiClient {
            /** @param array<string, mixed> $answer */
            public function __construct(private readonly array $answer)
            {
                parent::__construct('http://127.0.0.1:9', 'test-key');
            }

            public function get(string $path, array $params = []): array
            {
                return $this->answer;
            }

            public function put(string $path, array $body = []): array
            {
                return $this->answer;
            }
        };
        $property = new \ReflectionProperty(BaseCommand::class, 'client');
        foreach ($app->all() as $command) {
            if ($command instanceof BaseCommand) {
                $property->setValue($command, $client);
            }
        }

        $_SERVER['argv'] = ['p202', ...$argv];
        $output = new BufferedOutput();
        self::assertSame(0, $app->run(null, $output));

        return $output->fetch();
    }
}
