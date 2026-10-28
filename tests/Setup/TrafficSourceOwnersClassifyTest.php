<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\TrafficSourceOwners;

/**
 * Which sources the report lists, and which of them it names a user for
 * without being told (TrafficSourceOwners::classify(), pure). The database
 * side, the repair and the API are TrafficSourceOwnersIntegrationTest's.
 */
final class TrafficSourceOwnersClassifyTest extends TestCase
{
    private const OWNER = 11;
    private const TAKER = 12;
    private const THIRD = 13;
    private const RENAMED = 2_000_000;

    private const USERS = [
        self::OWNER => ['user_name' => 'owner', 'user_deleted' => false],
        self::TAKER => ['user_name' => 'taker', 'user_deleted' => false],
        self::THIRD => ['user_name' => 'third', 'user_deleted' => true],
    ];

    /** @return array<string, mixed> a 202_ppc_networks row as mysqli answers it */
    private static function source(int $user, int $time = self::RENAMED, int $deleted = 0): array
    {
        return [
            'ppc_network_id' => '7',
            'user_id' => (string) $user,
            'ppc_network_name' => 'Ads',
            'ppc_network_deleted' => (string) $deleted,
            'ppc_network_time' => (string) $time,
        ];
    }

    /** @return array<string, mixed> a 202_ppc_accounts row as mysqli answers it */
    private static function account(int $id, int $user, int $time, int $deleted = 0): array
    {
        return [
            'ppc_account_id' => (string) $id,
            'ppc_network_id' => '7',
            'user_id' => (string) $user,
            'ppc_account_deleted' => (string) $deleted,
            'ppc_account_time' => (string) $time,
        ];
    }

    public function testATakenSourceGoesBackToTheOneUserWhoseAccountsNameIt(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER), [
            self::account(3, self::OWNER, self::RENAMED - 50, 1),
            self::account(2, self::OWNER, self::RENAMED - 1),
        ], self::USERS);

        self::assertNotNull($entry);
        self::assertSame(self::OWNER, $entry['repair_to_user_id']);
        self::assertSame([], $entry['ambiguous']);
        self::assertSame(7, $entry['ppc_network_id']);
        self::assertSame('Ads', $entry['ppc_network_name']);
        self::assertFalse($entry['removed']);
        self::assertSame(self::RENAMED, $entry['ppc_network_time']);
        self::assertSame([
            'user_id' => self::TAKER,
            'user_name' => 'taker',
            'user_deleted' => false,
            'ppc_accounts' => 0,
            'removed_ppc_accounts' => 0,
            'ppc_account_ids' => [],
            'last_ppc_account_time' => null,
        ], $entry['owner']);
        self::assertSame([[
            'user_id' => self::OWNER,
            'user_name' => 'owner',
            'user_deleted' => false,
            'ppc_accounts' => 1,
            'removed_ppc_accounts' => 1,
            'ppc_account_ids' => [2, 3],
            'last_ppc_account_time' => self::RENAMED - 1,
        ]], $entry['named_by']);
    }

    public function testASourceWhoseAccountsAreAllItsOwnUsersIsNotListed(): void
    {
        self::assertNull(TrafficSourceOwners::classify(self::source(self::OWNER), [
            self::account(2, self::OWNER, 1),
            self::account(3, self::OWNER, 1, 1),
        ], self::USERS));
        self::assertNull(TrafficSourceOwners::classify(self::source(self::OWNER), [], self::USERS));
    }

    public function testTheOwnersOwnAccountOnItMakesItAmbiguous(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER), [
            self::account(2, self::OWNER, 1),
            self::account(4, self::TAKER, 1, 1),
        ], self::USERS);

        self::assertSame([TrafficSourceOwners::OWNER_HAS_ACCOUNTS], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
        self::assertSame(1, $entry['owner']['removed_ppc_accounts']);
        self::assertSame([self::OWNER], array_column($entry['named_by'], 'user_id'));
    }

    public function testAccountsOfTwoOtherUsersMakeItAmbiguous(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::OWNER), [
            self::account(5, self::THIRD, 1),
            self::account(2, self::TAKER, 1),
        ], self::USERS);

        self::assertSame([TrafficSourceOwners::SEVERAL_USERS], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
        self::assertSame([self::TAKER, self::THIRD], array_column($entry['named_by'], 'user_id'));
        self::assertTrue($entry['named_by'][1]['user_deleted']);
    }

    /** The shape the API made before 1.9.77: an account put on another user's source after it was saved. */
    public function testAnAccountSavedAfterTheSourceMakesItAmbiguous(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER), [
            self::account(2, self::OWNER, self::RENAMED - 10),
            self::account(3, self::OWNER, self::RENAMED + 1),
        ], self::USERS);

        self::assertSame([TrafficSourceOwners::SAVED_AFTER_RENAME], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
    }

    /**
     * The times are whole seconds: an account the API put on another user's
     * source in the second the source was created is not shown to predate it.
     */
    public function testAnAccountSavedInTheSourcesSecondMakesItAmbiguous(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER), [
            self::account(2, self::OWNER, self::RENAMED - 10),
            self::account(3, self::OWNER, self::RENAMED),
        ], self::USERS);

        self::assertSame([TrafficSourceOwners::SAVED_AFTER_RENAME], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
    }

    public function testAUserWithNoRowMakesItAmbiguous(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER), [self::account(2, 99, 1)], self::USERS);

        self::assertSame([TrafficSourceOwners::NO_SUCH_USER], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
        self::assertNull($entry['named_by'][0]['user_name']);
        self::assertNull($entry['named_by'][0]['user_deleted']);
    }

    public function testEveryReasonIsListedAndARemovedSourceSaysSo(): void
    {
        $entry = TrafficSourceOwners::classify(self::source(self::TAKER, 10, 1), [
            self::account(2, self::OWNER, 20),
            self::account(3, 99, 5),
            self::account(4, self::TAKER, 5),
        ], self::USERS);

        self::assertSame([
            TrafficSourceOwners::OWNER_HAS_ACCOUNTS,
            TrafficSourceOwners::SEVERAL_USERS,
            TrafficSourceOwners::SAVED_AFTER_RENAME,
            TrafficSourceOwners::NO_SUCH_USER,
        ], $entry['ambiguous']);
        self::assertTrue($entry['removed']);
    }
}
