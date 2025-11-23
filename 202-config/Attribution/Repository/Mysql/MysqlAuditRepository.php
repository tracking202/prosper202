<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use RuntimeException;

final class MysqlAuditRepository implements AuditRepositoryInterface
{
    public function __construct(private readonly mysqli $connection)
    {
    }

    public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
    {
        $sql = 'INSERT INTO 202_attribution_audit (user_id, model_id, action, metadata, created_at) VALUES (?, ?, ?, ?, ?)';
        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare attribution audit insert: ' . $this->connection->error);
        }

        $payload = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR);
        $createdAt = time();
        $modelParam = $modelId;
        $stmt->bind_param('iissi', $userId, $modelParam, $action, $payload, $createdAt);
        $stmt->execute();
        $stmt->close();
    }
}
