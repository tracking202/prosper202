<?php

declare(strict_types=1);

namespace Tracking202\Apps;

use Prosper202\Database\Connection;

/**
 * The registrations' store icons (202_app_registrations.app_icon), as the
 * pages read and write them. The column is not an API field: a data: URI of
 * up to 40 KB has no place in every registration a list returns.
 */
final class AppIcons
{
    private const DATA_URI = '#^data:image/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+=*$#D';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Keep an icon StoreListing fetched. Only a data: URI of an image type
     * StoreListing accepts is stored, so nothing else can reach an <img src>.
     */
    public function store(int $userId, int $registrationId, string $dataUri): void
    {
        if (preg_match(self::DATA_URI, $dataUri) !== 1) {
            throw new \InvalidArgumentException('AppIcons::store(): not an image data URI');
        }
        $stmt = $this->conn->prepareWrite('UPDATE 202_app_registrations SET app_icon = ? WHERE registration_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'sii', [$dataUri, $registrationId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The icons of these registrations that have one — and only a value
     * that is still an image data URI, whatever the column came to hold.
     *
     * @param list<int> $registrationIds
     * @return array<int, string> registration_id => data: URI
     */
    public function forApps(int $userId, array $registrationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->conn->prepareRead('SELECT registration_id, app_icon FROM 202_app_registrations WHERE user_id = ? AND app_icon IS NOT NULL AND registration_id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ')');
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($ids)), [$userId, ...$ids]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $icon = (string)$row['app_icon'];
            if (preg_match(self::DATA_URI, $icon) === 1) {
                $out[(int)$row['registration_id']] = $icon;
            }
        }

        return $out;
    }
}
