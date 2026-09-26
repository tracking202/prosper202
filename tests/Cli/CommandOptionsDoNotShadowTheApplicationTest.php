<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\Application;
use Tests\TestCase;

/**
 * No command declares an option the application already owns.
 *
 * Symfony merges the application's options (--version, --help, --quiet, …)
 * into a command's only when the command runs, and a clash is a
 * LogicException at that moment — so a command whose own definition is
 * fine, and whose unit tests build their input from that definition alone,
 * can still refuse to start for every user. app:report shipped a
 * `--version` filter that way (PR 11), found only by running it. This
 * merges every registered command's definition the way a run does.
 */
final class CommandOptionsDoNotShadowTheApplicationTest extends TestCase
{
    public function testEveryCommandMergesTheApplicationsOptions(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $checked = 0;
        foreach ($app->all() as $name => $command) {
            try {
                $command->mergeApplicationDefinition();
                $command->getDefinition()->getOptions();
            } catch (\LogicException $e) {
                self::fail("$name cannot run: " . $e->getMessage());
            }
            $checked++;
        }
        self::assertGreaterThan(50, $checked, 'the registered commands were found');
        self::assertTrue($app->has('app:report'));
    }
}
