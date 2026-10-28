<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Application;
use P202Cli\Commands\BaseCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `rotator:rule:create` sends a rule only with a criterion and a destination.
 * A rule with no criterion matches every visitor: the server refuses one, as
 * Setup › Redirectors does, and one from before 1.9.79 stored it, so the
 * command refuses it before any request, saying how to ask for every visitor.
 */
final class RotatorRuleCreateCommandTest extends TestCase
{
    private const CRITERIA = '--criteria-json=[{"type":"country","statement":"is","value":"United States(US)"}]';
    private const REDIRECTS = '--redirects-json=[{"redirect_url":"https://r.test","weight":"100"}]';

    /** @return iterable<string, array{list<string>, string}> */
    public static function refused(): iterable
    {
        $noCriteria = '--criteria-json is required';
        $noRedirects = '--redirects-json is required';
        yield 'no criteria' => [['--rule-name=r', self::REDIRECTS], $noCriteria];
        yield 'an empty criteria list' => [['--rule-name=r', '--criteria-json=[]', self::REDIRECTS], $noCriteria];
        yield 'no redirects' => [['--rule-name=r', self::CRITERIA], $noRedirects];
        yield 'an empty redirects list' => [['--rule-name=r', self::CRITERIA, '--redirects-json=[]'], $noRedirects];
    }

    /**
     * @dataProvider refused
     * @param list<string> $options
     */
    public function testARuleWithoutACriterionOrADestinationIsNotSent(array $options, string $said): void
    {
        [$code, $calls, $display] = self::runCli(['rotator:rule:create', '3', ...$options]);
        self::assertSame(1, $code, $display);
        self::assertSame([], $calls, 'nothing was sent');
        self::assertStringContainsString($said, $display);
        if (str_contains($said, 'criteria')) {
            self::assertStringContainsString('"value":"ALL"', $display, 'it says how to send every visitor');
        }
    }

    public function testARuleWithBothIsSent(): void
    {
        $argv = ['rotator:rule:create', '3', '--rule-name=r', self::CRITERIA, self::REDIRECTS];
        [$code, $calls, $display] = self::runCli($argv);
        self::assertSame(0, $code, $display);
        self::assertSame([['POST', 'rotators/3/rules', [
            'rule_name' => 'r',
            'splittest' => 0,
            'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'United States(US)']],
            'redirects' => [['redirect_url' => 'https://r.test', 'weight' => '100']],
        ]]], $calls);
    }

    /**
     * @param list<string> $argv without the application name
     * @return array{int, list<list<mixed>>, string}
     */
    private static function runCli(array $argv): array
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $client = new class () extends ApiClient {
            /** @var list<list<mixed>> */
            public array $calls = [];

            public function __construct()
            {
                parent::__construct('http://127.0.0.1:9', 'test-key');
            }

            public function post(string $path, array $body = []): array
            {
                $this->calls[] = ['POST', $path, $body];
                return ['data' => []];
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
        $code = $app->run(null, $output);

        return [$code, $client->calls, $output->fetch()];
    }
}
