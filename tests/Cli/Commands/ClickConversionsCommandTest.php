<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\ClickConversionsCommand;
use P202Cli\Commands\ConversionListCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The PHP CLI's click:conversions and conversion:list's ledger filters,
 * held to what the Go CLI's `click conversions` and `conversion list` do.
 */
class ClickConversionsCommandTest extends TestCase
{
    public function testTheCommandIsNamedAndTakesAClickId(): void
    {
        $command = new ClickConversionsCommand();
        (new Application('test', '1.0'))->add($command);
        self::assertSame('click:conversions', $command->getName());
        self::assertTrue($command->getDefinition()->getArgument('id')->isRequired());
        self::assertTrue($command->getDefinition()->hasOption('json'));
    }

    public function testABadClickIdIsRefusedBeforeAnyRequest(): void
    {
        $app = new Application('test', '1.0');
        $app->add(new ClickConversionsCommand());
        $tester = new CommandTester($app->find('click:conversions'));
        foreach (['abc', '0', '7x', '-1'] as $bad) {
            $code = $tester->execute(['id' => $bad]);
            self::assertSame(1, $code, $bad);
            self::assertStringContainsString('click id must be a positive integer', $tester->getDisplay(), $bad);
        }
    }

    public function testTheTableFlattensEachRowAndTheSummaryNamesTheValue(): void
    {
        $rows = ClickConversionsCommand::tableRows([
            ['conv_id' => 11, 'amount' => '5.00000', 'counted' => false, 'not_counted_reason' => 'superseded', 'superseded_reason' => 'replace',
                'source' => 'postback', 'linked_to' => null, 'transaction_id' => 'A-1', 'event_name' => null, 'conv_time' => 1],
            ['conv_id' => 12, 'amount' => '10.00000', 'counted' => true, 'not_counted_reason' => null, 'superseded_reason' => null,
                'source' => 'goal', 'linked_to' => ['label' => 'Goal "Sale" v2'], 'transaction_id' => null, 'event_name' => 'purchase', 'conv_time' => 2],
        ]);
        self::assertSame('superseded (replace)', $rows[0]['counts']);
        self::assertSame('counted', $rows[1]['counts']);
        self::assertSame('Goal "Sale" v2', $rows[1]['linked_to']);

        $click = ['click_id' => 7, 'lead' => true, 'click_payout' => '10.00000', 'payout_mode' => 'replace', 'counted_rows' => 1, 'rows' => 2,
            'ledger_state' => 'ledger', 'ledger_value' => '10.00000', 'matches_click' => true];
        self::assertSame(['', 'Click 7: 10.00000 (replace mode), 1 of 2 conversions counted.'], ClickConversionsCommand::summary($click));

        $click['matches_click'] = false;
        $click['click_payout'] = '7.00000';
        self::assertStringContainsString('add up to 10.00000, which is not the click\'s 7.00000', implode("\n", ClickConversionsCommand::summary($click)));
    }

    public function testConversionListRefusesBadLedgerFiltersBeforeAnyRequest(): void
    {
        $app = new Application('test', '1.0');
        $app->add(new ConversionListCommand());
        $tester = new CommandTester($app->find('conversion:list'));
        foreach ([['--click_id' => '7x'], ['--goal' => 'first'], ['--source' => 'webhook']] as $options) {
            self::assertSame(1, $tester->execute($options), json_encode($options));
            self::assertStringContainsString('must be', $tester->getDisplay());
        }
        self::assertStringContainsString('--source must be one of pixel, postback', $tester->getDisplay());
    }
}
