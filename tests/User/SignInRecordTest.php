<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\SignInRecord;

/**
 * The survey flags a sign-in writes, from what getSurveyData() answers. A
 * failed fetch answers null, and the service answers {"error": true, ...}
 * for an install it does not know (measured); neither is an answer, so both
 * leave the stored flags (null), where they were read as "no modal, no
 * perks" and reset on every such sign-in.
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
        yield 'an answer naming neither' => [[], [1, 0]];
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
