<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\KebabCaseArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tests\TestCase;

/**
 * KebabCaseArgvInput reads a snake_case option name as its kebab-case
 * spelling and touches nothing else: not a value, not an argument, not the
 * application name, nothing after a bare "--".
 */
final class KebabCaseArgvInputTest extends TestCase
{
    private static function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('rest', InputArgument::IS_ARRAY),
            new InputOption('time-from', null, InputOption::VALUE_REQUIRED),
            new InputOption('user-pass', null, InputOption::VALUE_OPTIONAL),
            new InputOption('filter[aff-network-id]', null, InputOption::VALUE_REQUIRED),
            new InputOption('limit', 'l', InputOption::VALUE_REQUIRED),
        ]);
    }

    public function testOptionNamesAreReadInKebabCaseAndValuesAsGiven(): void
    {
        $input = new KebabCaseArgvInput(
            ['p202', '--time_from=a_b=--c_d', '--filter[aff_network_id]', 'x_y', '-l', '5_0', 'arg_1', '--user_pass'],
            self::definition()
        );

        self::assertSame('a_b=--c_d', $input->getOption('time-from'));
        self::assertSame('x_y', $input->getOption('filter[aff-network-id]'), 'a separate value token is left alone');
        self::assertSame('5_0', $input->getOption('limit'));
        self::assertSame(['arg_1'], $input->getArgument('rest'));
        self::assertNull($input->getOption('user-pass'));
        self::assertTrue($input->hasParameterOption('--user-pass'), 'user:update asks for the password when --user-pass has no value');
    }

    public function testTheKebabCaseSpellingIsReadAsItIs(): void
    {
        $input = new KebabCaseArgvInput(['p202', '--time-from', '7', '--filter[aff-network-id]=3'], self::definition());

        self::assertSame('7', $input->getOption('time-from'));
        self::assertSame('3', $input->getOption('filter[aff-network-id]'));
    }

    public function testNothingAfterABareDoubleDashIsRewritten(): void
    {
        $input = new KebabCaseArgvInput(['p202', '--time_from=1', '--', '--time_from=2', 'a_b'], self::definition());

        self::assertSame('1', $input->getOption('time-from'));
        self::assertSame(['--time_from=2', 'a_b'], $input->getArgument('rest'));
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        $input = new KebabCaseArgvInput(['p202', '--time_from='], self::definition());

        self::assertSame('', $input->getOption('time-from'));
    }

    public function testOnlyOptionNamesChangeInTheTokens(): void
    {
        self::assertSame(
            ['--bin_name', 'report:summary', 'arg_1', '--time-from=x_1', '--time-from', 'y_2', '-l', '-', '', '--', '--a_b'],
            KebabCaseArgvInput::kebabCaseOptionNames(['--bin_name', 'report:summary', 'arg_1', '--time_from=x_1', '--time_from', 'y_2', '-l', '-', '', '--', '--a_b'])
        );
    }

    public function testArgvIsReadWhenNoneIsGiven(): void
    {
        $_SERVER['argv'] = ['p202', '--time_from=7'];
        $input = new KebabCaseArgvInput(null, self::definition());

        self::assertSame('7', $input->getOption('time-from'));
    }
}
