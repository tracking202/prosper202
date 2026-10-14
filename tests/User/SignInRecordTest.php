<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\SignInRecord;

/**
 * The survey flags a sign-in writes, from what getSurveyData() answers. A
 * failed fetch answers null, and the service answers {"error": true, ...}
 * for an install it does not know (measured); a flag is read only from a
 * boolean under its key, so these and any body without it leave the stored
 * flag (null), where they were read as "no modal, no perks" and reset on
 * every such sign-in.
 */
final class SignInRecordTest extends TestCase
{
    /** @return iterable<string, array{mixed, array{?int, ?int}}> */
    public static function answers(): iterable
    {
        yield 'a failed fetch' => [null, [null, null]];
        yield 'not JSON' => ['<html>', [null, null]];
        yield 'an error answer' => [['error' => true, 'msg' => 'Install ID missing'], [null, null]];
        yield 'no modal, perks (as measured)' => [['modal' => false, 'vip_perks' => true], [1, 1]];
        yield 'a modal, no perks' => [['modal' => true, 'vip_perks' => false], [0, 0]];
        yield 'an empty answer' => [[], [null, null]];
        yield 'a server error\'s body' => [['message' => 'Server Error'], [null, null]];
        yield 'one flag answered' => [['modal' => false], [1, null]];
        yield 'not a boolean' => [['modal' => 'false', 'vip_perks' => 1], [null, null]];
    }

    /**
     * @dataProvider answers
     * @param array{?int, ?int} $flags
     */
    public function testTheFlagsAreReadOnlyFromAnAnswer(mixed $survey, array $flags): void
    {
        self::assertSame($flags, SignInRecord::surveyFlags($survey));
    }
}
