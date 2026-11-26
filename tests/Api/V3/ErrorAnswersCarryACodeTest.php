<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\ErrorCode;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\NothingToUpdateException;
use Api\V3\Exception\SyncException;
use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\UpstreamUnavailableException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * Every 4xx and 5xx the v3 API answers carries a registered code, whatever
 * threw and whoever built the body: Bootstrap::jsonResponse() adds the
 * status's code to a body that names none and replaces one ErrorCode does
 * not list. Answers are produced in a child process, where nothing was
 * printed before the headers.
 */
final class ErrorAnswersCarryACodeTest extends TestCase
{
    /** @return array<string, mixed> the decoded answer */
    private static function answer(string $call): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; ' . $call . ';';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d log_errors=0 -d display_errors=stderr -r '
            . escapeshellarg($code) . ' 2>/dev/null');
        self::assertIsString($out, $call);
        $body = json_decode($out, true);
        self::assertIsArray($body, $call . ' answered ' . $out);

        return $body;
    }

    /** @return iterable<string, array{string, string}> */
    public static function answers(): iterable
    {
        yield '404 naming nothing' => ["Api\\V3\\Bootstrap::errorResponse('Not found', 404)", 'not_found'];
        yield '400 naming nothing' => ["Api\\V3\\Bootstrap::errorResponse('Nope', 400)", 'bad_request'];
        yield 'a status with no code of its own' => [
            "Api\\V3\\Bootstrap::errorResponse('Teapot', 418)",
            'client_error',
        ];
        yield 'a 5xx with no code of its own' => ["Api\\V3\\Bootstrap::errorResponse('Gateway', 504)", 'server_error'];
        yield 'a registered code is kept' => [
            "Api\\V3\\Bootstrap::errorResponse('Taken', 409, ['code' => 'name_taken'])",
            'name_taken',
        ];
        yield 'an unregistered code is replaced' => [
            "Api\\V3\\Bootstrap::errorResponse('Odd', 409, ['code' => 'made_up_here'])",
            'conflict',
        ];
        yield 'a non-string code is replaced' => [
            "Api\\V3\\Bootstrap::errorResponse('Odd', 422, ['code' => 7])",
            'validation_failed',
        ];
        yield 'a body built without errorResponse' => [
            "Api\\V3\\Bootstrap::jsonResponse(['error' => true, 'message' => 'x', 'status' => 503], 503)",
            'service_unavailable',
        ];
        yield 'a body that cannot be encoded' => [
            "Api\\V3\\Bootstrap::jsonResponse(['bad' => \"\\xB1\"], 422)",
            'server_error',
        ];
    }

    /** @dataProvider answers */
    public function testTheAnswerCarriesItsCode(string $call, string $code): void
    {
        self::assertSame($code, self::answer($call)['code'] ?? null);
    }

    public function testASuccessIsUntouched(): void
    {
        self::assertSame(['data' => ['code' => 'abc']], self::answer(
            "Api\\V3\\Bootstrap::jsonResponse(['data' => ['code' => 'abc']], 200)"
        ));
        self::assertSame(['code' => 'made_up_here'], self::answer(
            "Api\\V3\\Bootstrap::jsonResponse(['code' => 'made_up_here'], 201)"
        ));
    }

    public function testTheFieldsAnErrorAlreadyHadAreKept(): void
    {
        self::assertSame(
            ['error' => true, 'message' => 'Rate limit exceeded', 'status' => 429, 'retry_after_seconds' => 4,
                'code' => 'rate_limited'],
            self::answer("Api\\V3\\Bootstrap::errorResponse('Rate limit exceeded', 429, ['retry_after_seconds' => 4])")
        );
    }

    /** @return iterable<string, array{HttpException, string}> */
    public static function exceptions(): iterable
    {
        yield 'validation' => [new ValidationException('x'), ErrorCode::VALIDATION_FAILED];
        yield 'nothing to update' => [new NothingToUpdateException('x'), ErrorCode::NOTHING_TO_UPDATE];
        yield 'conflict' => [new ConflictException('x'), ErrorCode::CONFLICT];
        yield 'not found' => [new NotFoundException(), ErrorCode::NOT_FOUND];
        yield 'database' => [new DatabaseException('detail'), ErrorCode::SERVER_ERROR];
        yield 'write committed' => [new WriteCommittedException('a campaign'), ErrorCode::WRITE_COMMITTED];
        yield 'rate limited' => [new TooManyRequestsException('x', 5), ErrorCode::RATE_LIMITED];
        yield 'upstream not reached' => [new UpstreamUnavailableException('x', 60), ErrorCode::UPSTREAM_UNAVAILABLE];
        yield 'upstream answer unusable' => [
            new UpstreamUnavailableException('x', null),
            ErrorCode::UPSTREAM_UNAVAILABLE,
        ];
        yield 'sync limit' => [SyncException::limit('x'), ErrorCode::SYNC_LIMIT];
        yield 'sync remote' => [SyncException::remoteFailed('x'), ErrorCode::SYNC_REMOTE_FAILED];
        yield 'a status alone' => [new HttpException('x', 405), ErrorCode::METHOD_NOT_ALLOWED];
        yield 'named at the throw' => [
            (new ConflictException('x'))->coded(ErrorCode::NAME_TAKEN),
            ErrorCode::NAME_TAKEN,
        ];
    }

    /** @dataProvider exceptions */
    public function testAnExceptionNamesItsCode(HttpException $e, string $code): void
    {
        self::assertSame($code, $e->errorCode());
        self::assertTrue(ErrorCode::isKnown($e->errorCode()));
    }

    public function testAThrowCannotNameAnUnregisteredCode(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('made_up_here is not in ErrorCode');
        (new ConflictException('x'))->coded('made_up_here');
    }
}
