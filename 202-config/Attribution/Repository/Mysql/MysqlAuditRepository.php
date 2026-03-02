<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Database\Connection;

final readonly class MysqlAuditRepository implements AuditRepositoryInterface
{
    private Connection $conn;

    /**
     * @param Connection|mysqli $connection Connection instance or legacy mysqli for backwards compatibility
     */
    public function __construct(Connection|mysqli $connection)
    {
        if ($connection instanceof Connection) {
            $this->conn = $connection;
        } else {
            $this->conn = new Connection($connection);
        }
    }

    public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
    {
        $sql = 'INSERT INTO 202_attribution_audit (user_id, model_id, action, metadata, created_at) VALUES (?, ?, ?, ?, ?)';
        $stmt = $this->conn->prepareWrite($sql);

        $payload = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR);
        $createdAt = time();
        $modelParam = $modelId;
        $stmt->bind_param('iissi', $userId, $modelParam, $action, $payload, $createdAt);
        $this->conn->execute($stmt);
        $stmt->close();
    }
}
