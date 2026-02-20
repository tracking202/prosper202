<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use RuntimeException;

/**
 * Provides a reusable helper for binding mysqli statements while keeping the
 * referenced values alive for the duration of the statement lifecycle.
 */
trait MysqliStatementBinder
{
    /**
     * Cached references used for the most recent mysqli parameter binding.
     *
     * @var array<int, mixed>
     */
    private array $boundParameterValues = [];

    /**
     * @param array<int, mixed> $params
     */
    private function bindStatement(\mysqli_stmt $stmt, string $types, array $params): void
    {
        $this->boundParameterValues = array_values($params);

        $values = [$types];
        foreach ($this->boundParameterValues as $index => &$value) {
            $values[] = &$this->boundParameterValues[$index];
        }
        unset($value);

        if (!call_user_func_array($stmt->bind_param(...), $values)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}

