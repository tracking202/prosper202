<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\AppLinkCommand;
use P202Cli\Commands\AppNotificationsCommand;
use P202Cli\Commands\AppReportCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The PHP CLI's app:report, app:notifications and app:link (PR 11), held to
 * what the Go CLI's `app report`, `app notifications` and `app link` do: the
 * report refuses a one-platform grouping or filter without its --platform
 * before any request, and sends the rest as the API reads them.
 */
final class AppReportCommandTest extends TestCase
{
    /** @param array<string, mixed> $options */
    private static function input(array $options): ArrayInput
    {
        $command = new AppReportCommand();
        (new Application('test', '1.0'))->add($command);

        return new ArrayInput($options, $command->getDefinition());
    }

    public function testTheCommandsAreNamed(): void
    {
        $app = new Application('test', '1.0');
        foreach ([new AppReportCommand(), new AppNotificationsCommand(), new AppLinkCommand()] as $command) {
            $app->add($command);
        }
        self::assertTrue($app->has('app:report') && $app->has('app:notifications') && $app->has('app:link'));
        self::assertTrue($app->find('app:link')->getDefinition()->getArgument('registration_id')->isRequired());
    }

    public function testTheQueryCarriesThePlatformAndItsFilters(): void
    {
        self::assertSame(['group_by' => 'goal', 'platform' => 'android', 'registration_id' => '7', 'trusted' => 'unvouched'],
            AppReportCommand::params(self::input(['--platform' => 'android', '--group_by' => 'goal', '--registration_id' => '7', '--trusted' => 'unvouched'])));
        self::assertSame(['group_by' => 'day', 'time_from' => '0'], AppReportCommand::params(self::input(['--time_from' => '0'])),
            'no --platform sends none: the API\'s default, iOS');
        self::assertSame(['group_by' => 'ad-network', 'signature' => 'valid'], AppReportCommand::params(self::input(['--group_by' => 'ad-network', '--signature' => 'valid'])),
            'an iOS grouping and filter need no --platform: iOS is the default, as it was before Android');
        self::assertSame(['group_by' => 'platform', 'platform' => 'all'], AppReportCommand::params(self::input(['--group_by' => 'platform', '--platform' => 'all'])),
            '--platform=all asks for both');
        self::assertSame(['group_by' => 'ad-network', 'platform' => 'ios', 'signature' => 'valid'],
            AppReportCommand::params(self::input(['--platform' => 'ios', '--group_by' => 'ad-network', '--signature' => 'valid'])));
        self::assertSame(['group_by' => 'day', 'platform' => 'ios', 'version' => '4.0'],
            AppReportCommand::params(self::input(['--platform' => 'ios', '--postback_version' => '4.0'])),
            '--postback_version is the API\'s version filter (--version is the application\'s own option)');
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function oneSided(): iterable
    {
        yield 'an iOS grouping on both' => [['--group_by' => 'ad-network', '--platform' => 'all'], 'use --platform=ios'];
        yield 'an Android grouping by default' => [['--group_by' => 'goal'], 'use --platform=android'];
        yield 'an Android grouping on iOS' => [['--group_by' => 'goal', '--platform' => 'ios'], 'use --platform=android'];
        yield 'a postback filter on Android' => [['--signature' => 'valid', '--platform' => 'android'], 'use --platform=ios'];
        yield 'an install filter by default' => [['--match_state' => 'organic'], 'use --platform=android'];
        yield 'a platform nobody has' => [['--platform' => 'windows'], 'ios (the default), android or all'];
        yield 'an unknown grouping' => [['--group_by' => 'planet'], 'must be one of'];
    }

    /**
     * @dataProvider oneSided
     * @param array<string, string> $options
     */
    public function testAOneSidedOptionNeedsItsPlatform(array $options, string $says): void
    {
        try {
            AppReportCommand::params(self::input($options));
            self::fail('refused nothing');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($says, $e->getMessage());
        }
    }

    public function testApplyWithoutACampaignIsRefusedBeforeAnyRequest(): void
    {
        $app = new Application('test', '1.0');
        $app->add(new AppLinkCommand());
        $tester = new CommandTester($app->find('app:link'));
        self::assertSame(1, $tester->execute(['registration_id' => '7', '--apply' => true]));
        self::assertStringContainsString('--apply needs --campaign_id', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['registration_id' => 'x']));
        self::assertStringContainsString('positive whole number', $tester->getDisplay());
    }
}
