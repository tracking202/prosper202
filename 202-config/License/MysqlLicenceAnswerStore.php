<?php

declare(strict_types=1);

namespace Prosper202\License;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * LicenceAnswerStore in the install's database (202_licence_answers,
 * SecretTables::licenceAnswers()), so the record outlives a restart, a
 * redeploy and a cleared temp directory. A failed read or write throws.
 */
final class MysqlLicenceAnswerStore implements LicenceAnswerStore
{
    public function __construct(private readonly \mysqli $db)
    {
    }

    public function lastAnswer(string $keyHash, string $question): ?array
    {
        $conn = new Connection($this->db);
        try {
            $stmt = $conn->prepareRead(
                'SELECT valid_yes, valid_at, paid_yes, paid_at FROM 202_licence_answers WHERE key_sha256 = ? LIMIT 1'
            );
            $conn->bind($stmt, 's', [$keyHash]);
            $row = $conn->fetchOne($stmt);
        } catch (QueryException $e) {
            throw new \RuntimeException('The licence answer record could not be read: ' . $e->getMessage(), 0, $e);
        }
        if ($row === null) {
            return null;
        }
        [$yes, $at] = match ($question) {
            ShellAccessCache::QUESTION_VALID => [$row['valid_yes'] ?? null, $row['valid_at'] ?? null],
            ShellAccessCache::QUESTION_PAID => [$row['paid_yes'] ?? null, $row['paid_at'] ?? null],
            default => throw new \InvalidArgumentException('No such licence question: ' . $question),
        };
        if ($yes === null || $at === null) {
            return null;
        }

        return [(int) $yes === 1, (int) $at];
    }

    public function saveAnswer(string $keyHash, string $question, bool $yes, int $at): void
    {
        $sql = match ($question) {
            ShellAccessCache::QUESTION_VALID => 'INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)'
                . ' VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valid_yes = ?, valid_at = ?',
            ShellAccessCache::QUESTION_PAID => 'INSERT INTO 202_licence_answers (key_sha256, paid_yes, paid_at)'
                . ' VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE paid_yes = ?, paid_at = ?',
            default => throw new \InvalidArgumentException('No such licence question: ' . $question),
        };
        $conn = new Connection($this->db);
        $flag = $yes ? 1 : 0;
        try {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, 'siiii', [$keyHash, $flag, $at, $flag, $at]);
            $conn->executeUpdate($stmt);
        } catch (QueryException $e) {
            throw new \RuntimeException('The licence answer could not be recorded: ' . $e->getMessage(), 0, $e);
        }
    }
}
