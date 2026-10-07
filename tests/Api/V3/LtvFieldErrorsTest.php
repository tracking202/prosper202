<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Ltv\CompanyConflictException;
use Prosper202\Ltv\LtvConflictException;
use Prosper202\Ltv\LtvInputException;
use Prosper202\Ltv\RecordNotFoundException;
use Prosper202\Ltv\SubscriptionNotFoundException;
use Tests\Support\SourceScan;

/**
 * Every 422 an /ltv route answers names at least one field.
 *
 * LtvController::wrap() turned every RuntimeException a repository threw
 * into a 422 carrying only its message: a negative purchase amount, an
 * unknown custom field, a reserved idempotency key, a subscription event in
 * the wrong currency -- an agent had a sentence and no field to fix (and a
 * repository's own failure, "did not yield a customer_id", read as the
 * caller's mistake). Measured on a live instance before the change: each of
 * those answered `{"error":true,"message":…,"status":422}` and nothing else.
 *
 * Three things hold it:
 *  - every ValidationException the controller constructs is given field
 *    errors (a second argument that is not `[]`);
 *  - wrap() answers a refusal that names its field (LtvInputException) as a
 *    422 with that field, and anything else a repository throws as a 404,
 *    a 409 or a 500 -- never a 422 without one (executed);
 *  - in the LTV repositories, a bare RuntimeException is a failure of the
 *    server, listed here; a refusal of what was sent is an
 *    LtvInputException, which carries the field.
 */
final class LtvFieldErrorsTest extends TestCase
{
    /**
     * file => the messages its bare RuntimeExceptions start with, each a
     * failure of the server (or a parser not reached from an /ltv route),
     * and why.
     *
     * @var array<string, array<string, string>>
     */
    private const SERVER_FAILURES = [
        '202-config/Ltv/LtvQuery.php' => [
            'At most' => 'LtvController::query() refuses the extra cf.* filters by name before it builds the query',
            'Invalid custom-field filter column' => 'built by LtvController::query() from a field definition',
            'Invalid custom-field filter operator' => 'built by LtvController::query() from a checked bound',
            'Custom-field filter requires a fieldId' => 'built by LtvController::query() from a stored field',
        ],
        '202-config/Ltv/MysqlCustomerFieldRepository.php' => [
            'Failed to encode field options' => 'json_encode() of strings failing is the server\'s',
        ],
        '202-config/Ltv/MysqlCustomerRepository.php' => [
            'Customer alias claim failed and winner not found' => 'a write the database did not keep',
            'Product upsert did not yield a product_id' => 'a write the database did not keep',
            'Customer insert did not yield a customer_id' => 'a write the database did not keep',
        ],
        '202-config/Ltv/MysqlEngagementRepository.php' => [
            'event source must be api or site' => 'an argument of the code, never of a request',
            'Score weights must look like' =>
                'parseScoreWeights(): the preference parser; PreferenceRules names the field',
            'Unknown score component' => 'parseScoreWeights()',
            'Score component' => 'parseScoreWeights()',
            'All score components are required' => 'parseScoreWeights()',
            'Score weights must sum to exactly 100' => 'parseScoreWeights()',
        ],
        '202-config/Ltv/MysqlIntegrationRepository.php' => [
            'config could not be encoded as JSON' =>
                'the body decoded from JSON; encoding it back failing is the server\'s',
        ],
        '202-config/Ltv/MysqlSubscriptionRepository.php' => [
            'Subscription upsert did not yield a subscription_id' => 'a write the database did not keep',
        ],
        '202-config/Ltv/MysqlWebhookRepository.php' => [
            'Failed to encode webhook payload for' => 'an event this install built',
        ],
    ];

    public function testEveryValidationExceptionTheControllerBuildsNamesAField(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/api/v3/Controllers/LtvController.php');
        $sites = self::constructions($source, 'ValidationException');
        self::assertGreaterThan(20, count($sites), 'the scan found the constructions');
        $bare = [];
        foreach ($sites as [$line, $args]) {
            if (count($args) < 2 || preg_replace('/\s+/', '', $args[1]) === '[]') {
                $bare[] = $line;
            }
        }
        self::assertSame([], $bare, 'LtvController lines that build a 422 with no field_errors');
    }

    /**
     * @return iterable<string, array{\Throwable, class-string, ?array<string, string>}>
     */
    public static function thrown(): iterable
    {
        yield 'a refusal naming its field' => [
            new LtvInputException('amount', 'amount must not be negative', 'Must not be negative'),
            ValidationException::class,
            ['amount' => 'Must not be negative'],
        ];
        yield 'a bare RuntimeException is the server\'s' => [
            new \RuntimeException('x'),
            DatabaseException::class,
            null,
        ];
        yield 'a failed query' => [new QueryException('MySQL said no'), DatabaseException::class, null];
        yield 'a missing record' => [new RecordNotFoundException('Field not found'), NotFoundException::class, null];
        yield 'a missing subscription' => [
            new SubscriptionNotFoundException('Subscription not found: s'),
            NotFoundException::class,
            null,
        ];
        yield 'a concurrent write' => [new LtvConflictException('retry the merge'), ConflictException::class, null];
        yield 'a duplicate company' => [new CompanyConflictException('exists'), ConflictException::class, null];
        yield 'an Error' => [new \TypeError('t'), DatabaseException::class, null];
    }

    /**
     * @dataProvider thrown
     * @param class-string $expected
     * @param array<string, string>|null $fields
     */
    public function testWrapNeverAnswersA422WithoutAField(\Throwable $thrown, string $expected, ?array $fields): void
    {
        $controller = (new \ReflectionClass(LtvController::class))->newInstanceWithoutConstructor();
        $wrap = new \ReflectionMethod(LtvController::class, 'wrap');
        try {
            $wrap->invoke($controller, static function () use ($thrown): never {
                throw $thrown;
            });
            self::fail('wrap() returned');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e, get_class($thrown) . ' became ' . get_class($e));
            if ($e instanceof ValidationException) {
                self::assertSame($fields, $e->getFieldErrors());
            }
        }
    }

    public function testInTheLtvRepositoriesABareRuntimeExceptionIsAListedServerFailure(): void
    {
        $unlisted = [];
        $seen = [];
        foreach (glob(SourceScan::repoRoot() . '/202-config/Ltv/*.php') ?: [] as $path) {
            $file = substr($path, strlen(SourceScan::repoRoot()) + 1);
            $source = (string) file_get_contents($path);
            foreach (self::constructions($source, 'RuntimeException') as [$line, $args]) {
                $message = self::leadingLiteral($args[0] ?? '');
                $listed = null;
                foreach (array_keys(self::SERVER_FAILURES[$file] ?? []) as $prefix) {
                    if ($message !== null && str_starts_with($message, $prefix)) {
                        $listed = $prefix;
                        break;
                    }
                }
                if ($listed === null) {
                    $unlisted[] = "$file:$line " . ($message ?? '(a message this scan cannot read)');
                } else {
                    $seen[$file][$listed] = true;
                }
            }
        }

        self::assertSame(
            [],
            $unlisted,
            "A bare RuntimeException in an LTV repository reaches an /ltv caller as a 500.\n"
                . 'A refusal of what was sent is an LtvInputException naming the field (a 422 with field_errors);'
                . ' a failure of the server is listed in SERVER_FAILURES with why.'
        );
        foreach (self::SERVER_FAILURES as $file => $prefixes) {
            foreach (array_keys($prefixes) as $prefix) {
                self::assertArrayHasKey(
                    $prefix,
                    $seen[$file] ?? [],
                    "$file no longer throws \"$prefix\": remove it from the list"
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, list<array{int, list<string>}>}>
     */
    public static function shapes(): iterable
    {
        yield 'two arguments' => [
            '<?php throw new ValidationException("m", ["f" => "x"]);',
            'ValidationException',
            [[1, ['"m"', '["f"=>"x"]']]],
        ];
        yield 'fully qualified, one argument' => [
            '<?php throw new \Api\V3\Exception\ValidationException("m");',
            'ValidationException',
            [[1, ['"m"']]],
        ];
        yield 'nested calls and arrays' => [
            '<?php new RuntimeException(sprintf("%s", f(1, 2)), 0);',
            'RuntimeException',
            [[1, ['sprintf("%s",f(1,2))', '0']]],
        ];
        yield 'another class is not it' => ['<?php new MyValidationException("m");', 'ValidationException', []];
    }

    /**
     * @dataProvider shapes
     * @param list<array{int, list<string>}> $expected
     */
    public function testTheScanReadsEachShape(string $source, string $class, array $expected): void
    {
        $squeeze = static fn (string $a): string => (string) preg_replace('/\s+/', '', $a);
        $found = array_map(
            static fn (array $site): array => [$site[0], array_map($squeeze, $site[1])],
            self::constructions($source, $class)
        );
        self::assertSame($expected, $found);
    }

    /**
     * Each `new <Class>(...)` of the class (by its last name segment), with
     * its line and its top-level arguments as source text.
     *
     * @return list<array{int, list<string>}>
     */
    private static function constructions(string $source, string $class): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
        ));
        $sites = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $name = $tokens[$j] ?? null;
            if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $parts = explode('\\', $name[1]);
            if (end($parts) !== $class) {
                continue;
            }
            $k = $j + 1;
            while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (($tokens[$k] ?? null) !== '(') {
                $sites[] = [$tokens[$i][2], []];
                continue;
            }
            $args = [];
            $current = '';
            $depth = 0;
            for ($k++; $k < $count; $k++) {
                $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $opensInterpolation = is_array($tokens[$k])
                    && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
                if (in_array($text, ['(', '[', '{'], true) || $opensInterpolation) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    $args[] = trim($current);
                    $current = '';
                    continue;
                }
                $current .= $text;
            }
            if (trim($current) !== '') {
                $args[] = trim($current);
            }
            $sites[] = [$tokens[$i][2], $args];
        }

        return $sites;
    }

    /** The text a message argument starts with, when it starts with a string literal. */
    private static function leadingLiteral(string $argument): ?string
    {
        if (preg_match('/^(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*)/s', $argument, $m) !== 1) {
            return null;
        }

        return stripslashes(substr($m[1], 1, $m[1][0] === '\'' ? -1 : null));
    }
}
