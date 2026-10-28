<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\UserStanding;

/**
 * Who may act, from a user's row: only an active (1), undeleted (0) user, as
 * the driver hands the columns back (an int from a prepared read, a string
 * from a text one). Any other value, none of which a version writes, is a
 * user who may not act. SessionEndsWithUserIntegrationTest holds the sign-in,
 * the remember-me cookie and the session to the same answer.
 */
final class UserStandingTest extends TestCase
{
    /**
     * @dataProvider rows
     * @param array<string, mixed>|null $row
     */
    public function testTheRowsStanding(?array $row, UserStanding $standing): void
    {
        self::assertSame($standing, UserStanding::ofRow($row));
        self::assertSame($standing === UserStanding::Active, UserStanding::ofRow($row)->canAct());
    }

    /**
     * What a writer of user_active leaves: an active user only for 1, as the
     * column reads it back; anything else switches the user off, and the
     * writer revokes their remember-me keys.
     *
     * @dataProvider activeValues
     */
    public function testAWrittenValueLeavesTheUserActiveOnlyWhenItIsOne(mixed $value, bool $active): void
    {
        self::assertSame($active, UserStanding::isActive($value));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function activeValues(): array
    {
        return [
            '1' => [1, true],
            "'1'" => ['1', true],
            '0' => [0, false],
            "'0'" => ['0', false],
            '2' => [2, false],
            "'01'" => ['01', false],
            'true' => [true, false],
            'null' => [null, false],
        ];
    }

    /** @return array<string, array{array<string, mixed>|null, UserStanding}> */
    public static function rows(): array
    {
        return [
            'active, as a prepared read gives it' => [['user_active' => 1, 'user_deleted' => 0], UserStanding::Active],
            'active, as a text read gives it' => [['user_active' => '1', 'user_deleted' => '0'], UserStanding::Active],
            'switched off' => [['user_active' => 0, 'user_deleted' => 0], UserStanding::SwitchedOff],
            'removed' => [['user_active' => 1, 'user_deleted' => 1], UserStanding::Deleted],
            'removed and switched off' => [['user_active' => '0', 'user_deleted' => '1'], UserStanding::Deleted],
            'a deleted value no version writes' => [['user_active' => 1, 'user_deleted' => 2], UserStanding::Deleted],
            'an active value no version writes' => [
                ['user_active' => 2, 'user_deleted' => 0],
                UserStanding::SwitchedOff,
            ],
            'a padded number' => [['user_active' => '01', 'user_deleted' => '0'], UserStanding::SwitchedOff],
            'a boolean' => [['user_active' => true, 'user_deleted' => false], UserStanding::Deleted],
            'a NULL' => [['user_active' => null, 'user_deleted' => 0], UserStanding::SwitchedOff],
            'a column not read' => [['user_active' => 1], UserStanding::Deleted],
            'no row' => [null, UserStanding::Missing],
        ];
    }
}
