<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\CrudCommands;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tests\TestCase;

class CrudCommandsTest extends TestCase
{
    private array $commands;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commands = CrudCommands::generate(
            'widget',
            'widgets',
            [
                'widget_name' => 'Widget name',
                'widget_color' => 'Widget color',
                'widget_size' => 'Size in inches',
            ],
            ['widget_name'],
            ['filter[color]' => 'Filter by color']
        );

        // Commands need to be registered in an Application to have their helpers set up
        $this->app = new Application('test', '1.0');
        foreach ($this->commands as $cmd) {
            $this->app->add($cmd);
        }
    }

    public function testGenerateReturnsExactlyFiveCommands(): void
    {
        $this->assertCount(5, $this->commands);
        foreach ($this->commands as $cmd) {
            $this->assertInstanceOf(Command::class, $cmd);
        }
    }

    public function testCommandNamesFollowPattern(): void
    {
        $names = array_map(fn(Command $c) => $c->getName(), $this->commands);

        $this->assertContains('widget:list', $names);
        $this->assertContains('widget:get', $names);
        $this->assertContains('widget:create', $names);
        $this->assertContains('widget:update', $names);
        $this->assertContains('widget:delete', $names);
    }

    // --- List command tests ---

    public function testListCommandHasLimitAndOffsetOptions(): void
    {
        $cmd = $this->app->find('widget:list');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasOption('limit'));
        $this->assertTrue($def->hasOption('offset'));

        $limitOpt = $def->getOption('limit');
        $this->assertSame('50', $limitOpt->getDefault());
        $this->assertSame('l', $limitOpt->getShortcut());

        $offsetOpt = $def->getOption('offset');
        $this->assertSame('0', $offsetOpt->getDefault());
        $this->assertSame('o', $offsetOpt->getShortcut());
    }

    public function testListCommandHasCustomListParams(): void
    {
        $cmd = $this->app->find('widget:list');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasOption('filter[color]'));
    }

    public function testListCommandHasJsonOption(): void
    {
        $cmd = $this->app->find('widget:list');
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('json'));
    }

    // --- Get command tests ---

    public function testGetCommandRequiresIdArgument(): void
    {
        $cmd = $this->app->find('widget:get');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasArgument('id'));
        $idArg = $def->getArgument('id');
        $this->assertTrue($idArg->isRequired());
    }

    public function testGetCommandHasJsonOption(): void
    {
        $cmd = $this->app->find('widget:get');
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    // --- Create command tests ---

    public function testCreateCommandHasOptionsForEachField(): void
    {
        $cmd = $this->app->find('widget:create');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasOption('widget_name'));
        $this->assertTrue($def->hasOption('widget_color'));
        $this->assertTrue($def->hasOption('widget_size'));
    }

    public function testCreateCommandRequiredOptionsMarkedCorrectly(): void
    {
        $cmd = $this->app->find('widget:create');
        $def = $cmd->getDefinition();

        // Required fields have '(required)' in their description
        $nameOpt = $def->getOption('widget_name');
        $this->assertStringContainsString('(required)', $nameOpt->getDescription());

        // Non-required fields do not
        $colorOpt = $def->getOption('widget_color');
        $this->assertStringNotContainsString('(required)', $colorOpt->getDescription());

        $sizeOpt = $def->getOption('widget_size');
        $this->assertStringNotContainsString('(required)', $sizeOpt->getDescription());
    }

    public function testCreateCommandHasJsonOption(): void
    {
        $cmd = $this->app->find('widget:create');
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    // --- Update command tests ---

    public function testUpdateCommandRequiresIdArgument(): void
    {
        $cmd = $this->app->find('widget:update');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasArgument('id'));
        $idArg = $def->getArgument('id');
        $this->assertTrue($idArg->isRequired());
    }

    public function testUpdateCommandHasFieldOptions(): void
    {
        $cmd = $this->app->find('widget:update');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasOption('widget_name'));
        $this->assertTrue($def->hasOption('widget_color'));
        $this->assertTrue($def->hasOption('widget_size'));
    }

    public function testUpdateCommandHasJsonOption(): void
    {
        $cmd = $this->app->find('widget:update');
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    // --- Delete command tests ---

    public function testDeleteCommandHasForceOption(): void
    {
        $cmd = $this->app->find('widget:delete');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasOption('force'));
        $forceOpt = $def->getOption('force');
        $this->assertSame('f', $forceOpt->getShortcut());
        $this->assertFalse($forceOpt->acceptValue());
    }

    public function testDeleteCommandRequiresIdArgument(): void
    {
        $cmd = $this->app->find('widget:delete');
        $def = $cmd->getDefinition();

        $this->assertTrue($def->hasArgument('id'));
        $this->assertTrue($def->getArgument('id')->isRequired());
    }

    public function testDeleteCommandHasJsonOption(): void
    {
        $cmd = $this->app->find('widget:delete');
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    // --- Descriptions ---

    public function testCommandDescriptions(): void
    {
        $this->assertStringContainsString('widget', $this->app->find('widget:list')->getDescription());
        $this->assertStringContainsString('widget', $this->app->find('widget:get')->getDescription());
        $this->assertStringContainsString('widget', $this->app->find('widget:create')->getDescription());
        $this->assertStringContainsString('widget', $this->app->find('widget:update')->getDescription());
        $this->assertStringContainsString('widget', $this->app->find('widget:delete')->getDescription());
    }

    // --- Edge case: no required fields, no list params ---

    public function testGenerateWithNoRequiredFieldsAndNoListParams(): void
    {
        $commands = CrudCommands::generate(
            'simple',
            'simples',
            ['simple_name' => 'Name'],
            [],
            []
        );

        $this->assertCount(5, $commands);

        $app = new Application('test', '1.0');
        foreach ($commands as $cmd) {
            $app->add($cmd);
        }

        // Create command should not mark anything as required
        $createCmd = $app->find('simple:create');
        $nameOpt = $createCmd->getDefinition()->getOption('simple_name');
        $this->assertStringNotContainsString('(required)', $nameOpt->getDescription());

        // List command should only have limit, offset, and json
        $listCmd = $app->find('simple:list');
        $def = $listCmd->getDefinition();
        $optionNames = array_keys($def->getOptions());
        $this->assertContains('limit', $optionNames);
        $this->assertContains('offset', $optionNames);
        $this->assertContains('json', $optionNames);
    }
}
