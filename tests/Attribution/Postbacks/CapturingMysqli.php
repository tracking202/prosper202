<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

/**
 * A mysqli double whose prepared statements record their SQL, bind types
 * and bound values, so a test can assert what a class would have sent to
 * the database — the (type, value) pairing included (error pattern #7) —
 * without a server.
 */
trait CapturingMysqli
{
    /** @var array<int, array{sql: string, types: string, values: mixed[]}> */
    private array $captured = [];

    /**
     * $selectRows maps an SQL substring to the rows its get_result returns;
     * $insertBehavior is 'ok' or a callable run when an INSERT executes
     * (e.g. to throw a duplicate-key error).
     *
     * @param array<string, array<int, array<string, mixed>>> $selectRows
     */
    private function capturingDb(array $selectRows = [], string|callable $insertBehavior = 'ok'): \mysqli
    {
        $testCase = $this;

        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($testCase, $selectRows, $insertBehavior): \mysqli_stmt {
                /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
                $stmt = $testCase->getMockBuilder(\mysqli_stmt::class)
                    ->disableOriginalConstructor()
                    ->getMock();

                $index = count($testCase->captured);
                $testCase->captured[$index] = ['sql' => $sql, 'types' => '', 'values' => []];

                $stmt->method('bind_param')->willReturnCallback(
                    function (string $types, mixed ...$values) use ($testCase, $index): bool {
                        $testCase->captured[$index]['types'] = $types;
                        $testCase->captured[$index]['values'] = $values;
                        return true;
                    }
                );
                $stmt->method('execute')->willReturnCallback(
                    function () use ($sql, $insertBehavior): bool {
                        if (str_starts_with(ltrim($sql), 'INSERT') && is_callable($insertBehavior)) {
                            return (bool)$insertBehavior();
                        }
                        return true;
                    }
                );
                $stmt->method('get_result')->willReturnCallback(
                    function () use ($testCase, $sql, $selectRows): \mysqli_result {
                        foreach ($selectRows as $pattern => $rows) {
                            if (str_contains($sql, $pattern)) {
                                return $testCase->buildResultMock($pattern, [$pattern => $rows]);
                            }
                        }
                        return $testCase->buildResultMock($sql, []);
                    }
                );
                $stmt->method('close')->willReturn(true);

                return $stmt;
            }
        );

        return $db;
    }

    /**
     * The captured statements whose SQL starts with the given keyword.
     *
     * @return list<array{sql: string, types: string, values: mixed[]}>
     */
    private function capturedStatements(string $keyword): array
    {
        return array_values(array_filter(
            $this->captured,
            static fn(array $c): bool => str_starts_with(ltrim($c['sql']), $keyword)
        ));
    }
}
