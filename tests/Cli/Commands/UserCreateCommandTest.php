<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\UserCreateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Tests\TestCase;

class UserCreateCommandTest extends TestCase
{
    private UserCreateCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new UserCreateCommand();
        // Register command with an Application so helpers are available
        $app = new Application('test', '1.0');
        $app->add($this->command);
    }

    public function testCommandNameIsUserCreate(): void
    {
        $this->assertSame('user:create', $this->command->getName());
    }

    public function testHasAllExpectedOptions(): void
    {
        $def = $this->command->getDefinition();

        $expectedOptions = [
            'user_name',
            'user_email',
            'user_pass',
            'user_fname',
            'user_lname',
            'user_timezone',
            'json',
        ];

        foreach ($expectedOptions as $option) {
            $this->assertTrue($def->hasOption($option), "Missing expected option: $option");
        }
    }

    public function testUserNameIsRequired(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_name');

        // The option itself uses VALUE_REQUIRED (i.e., if provided, it needs a value)
        $this->assertTrue($opt->isValueRequired());
        // The description indicates it's required
        $this->assertStringContainsString('required', strtolower($opt->getDescription()));
    }

    public function testUserEmailIsRequired(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_email');

        $this->assertTrue($opt->isValueRequired());
        $this->assertStringContainsString('required', strtolower($opt->getDescription()));
    }

    public function testUserPassIsOptional(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_pass');

        // VALUE_OPTIONAL means the option can be provided with or without a value
        $this->assertFalse($opt->isValueRequired());
        // It should not be VALUE_NONE (it can accept a value)
        $this->assertTrue($opt->acceptValue());
    }

    public function testUserTimezoneDefaultsToUtc(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_timezone');

        $this->assertSame('UTC', $opt->getDefault());
    }

    public function testUserFnameOption(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_fname');

        $this->assertTrue($opt->isValueRequired());
        $this->assertNull($opt->getDefault());
    }

    public function testUserLnameOption(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('user_lname');

        $this->assertTrue($opt->isValueRequired());
        $this->assertNull($opt->getDefault());
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertStringContainsString('user', strtolower($this->command->getDescription()));
    }

    public function testJsonOptionIsAvailable(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('json');

        // json is VALUE_NONE (a flag)
        $this->assertFalse($opt->acceptValue());
    }

    public function testNoArgumentsAreDefined(): void
    {
        $def = $this->command->getDefinition();
        // UserCreateCommand should not require any positional arguments
        $this->assertCount(0, $def->getArguments());
    }
}
