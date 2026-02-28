<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\ClickGetCommand;
use P202Cli\Commands\ClickListCommand;
use P202Cli\Commands\ConfigShowCommand;
use P202Cli\Commands\ConfigTestCommand;
use P202Cli\Commands\SystemHealthCommand;
use P202Cli\Commands\SystemVersionCommand;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class CommandExecutionTest extends TestCase
{
    // ------------------------------------------------------------------
    // config:show
    // ------------------------------------------------------------------

    public function testConfigShowCommandHasName(): void
    {
        $command = new ConfigShowCommand();
        self::assertNotNull($command->getName());
    }

    public function testConfigShowCommandNameIsCorrect(): void
    {
        $command = new ConfigShowCommand();
        self::assertSame('config:show', $command->getName());
    }

    public function testConfigShowCommandHasDescription(): void
    {
        $command = new ConfigShowCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    // ------------------------------------------------------------------
    // config:test
    // ------------------------------------------------------------------

    public function testConfigTestCommandHasName(): void
    {
        $command = new ConfigTestCommand();
        self::assertNotNull($command->getName());
    }

    public function testConfigTestCommandNameIsCorrect(): void
    {
        $command = new ConfigTestCommand();
        self::assertSame('config:test', $command->getName());
    }

    public function testConfigTestCommandHasDescription(): void
    {
        $command = new ConfigTestCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    // ------------------------------------------------------------------
    // system:health
    // ------------------------------------------------------------------

    public function testSystemHealthCommandHasName(): void
    {
        $command = new SystemHealthCommand();
        self::assertNotNull($command->getName());
    }

    public function testSystemHealthCommandNameIsCorrect(): void
    {
        $command = new SystemHealthCommand();
        self::assertSame('system:health', $command->getName());
    }

    public function testSystemHealthCommandHasDescription(): void
    {
        $command = new SystemHealthCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    // ------------------------------------------------------------------
    // system:version
    // ------------------------------------------------------------------

    public function testSystemVersionCommandHasName(): void
    {
        $command = new SystemVersionCommand();
        self::assertNotNull($command->getName());
    }

    public function testSystemVersionCommandNameIsCorrect(): void
    {
        $command = new SystemVersionCommand();
        self::assertSame('system:version', $command->getName());
    }

    public function testSystemVersionCommandHasDescription(): void
    {
        $command = new SystemVersionCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    // ------------------------------------------------------------------
    // click:get
    // ------------------------------------------------------------------

    public function testClickGetCommandHasName(): void
    {
        $command = new ClickGetCommand();
        self::assertNotNull($command->getName());
    }

    public function testClickGetCommandNameIsCorrect(): void
    {
        $command = new ClickGetCommand();
        self::assertSame('click:get', $command->getName());
    }

    public function testClickGetCommandHasDescription(): void
    {
        $command = new ClickGetCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    public function testClickGetCommandRequiresIdArgument(): void
    {
        $command = new ClickGetCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('id'), 'click:get should define an "id" argument.');
        self::assertTrue($definition->getArgument('id')->isRequired(), 'The "id" argument should be required.');
    }

    // ------------------------------------------------------------------
    // click:list
    // ------------------------------------------------------------------

    public function testClickListCommandHasName(): void
    {
        $command = new ClickListCommand();
        self::assertNotNull($command->getName());
    }

    public function testClickListCommandNameIsCorrect(): void
    {
        $command = new ClickListCommand();
        self::assertSame('click:list', $command->getName());
    }

    public function testClickListCommandHasDescription(): void
    {
        $command = new ClickListCommand();
        self::assertNotNull($command->getDescription());
        self::assertNotSame('', $command->getDescription());
    }

    public function testClickListCommandHasLimitOption(): void
    {
        $command = new ClickListCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('limit'), 'click:list should define a "limit" option.');
    }

    public function testClickListCommandHasOffsetOption(): void
    {
        $command = new ClickListCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('offset'), 'click:list should define an "offset" option.');
    }

    // ------------------------------------------------------------------
    // BaseCommand subcommands share the --json option
    // ------------------------------------------------------------------

    public function testBaseCommandSubclassesHaveJsonOption(): void
    {
        $commands = [
            new SystemHealthCommand(),
            new SystemVersionCommand(),
            new ClickGetCommand(),
            new ClickListCommand(),
        ];

        foreach ($commands as $command) {
            self::assertTrue(
                $command->getDefinition()->hasOption('json'),
                sprintf('Command "%s" should have a --json option inherited from BaseCommand.', $command->getName())
            );
        }
    }

    public function testClickListCommandHasFilterOptions(): void
    {
        $command = new ClickListCommand();
        $definition = $command->getDefinition();

        $expectedOptions = ['time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'landing_page_id', 'click_lead', 'click_bot'];
        foreach ($expectedOptions as $option) {
            self::assertTrue(
                $definition->hasOption($option),
                sprintf('click:list should define a "%s" option.', $option)
            );
        }
    }
}
