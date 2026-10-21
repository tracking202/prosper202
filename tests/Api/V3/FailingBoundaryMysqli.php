<?php

declare(strict_types=1);

namespace Tests\Api\V3;

/**
 * A real connection whose begin can be made to fail, and whose commit or
 * rollback can be made to land and then answer false, as one does when the
 * connection drops before the server's answer arrives.
 */
final class FailingBoundaryMysqli extends \mysqli
{
    public int $beginFailsAt = 0;
    public int $commitUnconfirmedAt = 0;
    public int $rollbackFailsAt = 0;
    public int $begins = 0;
    private int $commits = 0;
    private int $rollbacks = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mysqli's own name
    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if (++$this->begins === $this->beginFailsAt) {
            return false;
        }

        return parent::begin_transaction($flags, $name);
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $committed = parent::commit($flags, $name);

        return ++$this->commits === $this->commitUnconfirmedAt ? false : $committed;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $rolledBack = parent::rollback($flags, $name);

        return ++$this->rollbacks === $this->rollbackFailsAt ? false : $rolledBack;
    }
}
