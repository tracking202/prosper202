<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use InvalidArgumentException;

/**
 * The accounts whose stored secret verified a notification, and which of
 * each account's secrets it was. Usually one account; two accounts that
 * sell the same provider campaign hold the same secret, and then the
 * click the notification names decides between them.
 */
final class Verification
{
    /** @var array<int, int> account id => the index of its secret that verified, in account order */
    public readonly array $secretByAccount;

    /** @param array<int, int> $secretByAccount */
    public function __construct(array $secretByAccount)
    {
        if ($secretByAccount === []) {
            throw new InvalidArgumentException('a verification names at least one account');
        }
        ksort($secretByAccount);
        $this->secretByAccount = $secretByAccount;
    }

    public function verifies(int $accountId): bool
    {
        return array_key_exists($accountId, $this->secretByAccount);
    }

    /** @return list<int> */
    public function accounts(): array
    {
        return array_keys($this->secretByAccount);
    }
}
