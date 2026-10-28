<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Database\Connection;

/**
 * The conversion action (Google Ads) or offline conversion goal (Microsoft
 * Advertising) each campaign's conversions are uploaded as: a name per
 * campaign and platform, and the account's name for every campaign without
 * one (202_offline_conversion_names, aff_campaign_id 0). The platforms match
 * a row to the action by its name exactly, capitalisation included, so the
 * name is stored as typed and only refused, never changed.
 */
final class ConversionNames
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';
    public const PLATFORMS = [self::GOOGLE, self::MICROSOFT];
    public const MAX_LENGTH = 100;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * What is wrong with a name, or null when it can be stored: at most 100
     * characters (the column), no control character (a line break would
     * end the row), and not starting with = + - or @, which a spreadsheet
     * opening the file would run as a formula.
     */
    public static function problem(string $name): ?string
    {
        if (trim($name) === '') {
            return 'A conversion name cannot be blank.';
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            return 'A conversion name must be UTF-8 text.';
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_LENGTH) {
            return 'A conversion name is at most ' . self::MAX_LENGTH . ' characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return 'A conversion name cannot hold a tab or a line break.';
        }
        if (str_contains('=+-@', $name[0])) {
            return 'A conversion name cannot start with =, +, - or @, which a spreadsheet would read as a formula.';
        }
        if ($name !== trim($name)) {
            return 'A conversion name cannot start or end with a space: the platform matches it exactly.';
        }

        return null;
    }

    /**
     * The account's names.
     *
     * @return array<string, array{default: ?string, campaigns: array<int, string>}> by platform
     */
    public function read(int $userId): array
    {
        $names = [];
        foreach (self::PLATFORMS as $platform) {
            $names[$platform] = ['default' => null, 'campaigns' => []];
        }
        $stmt = $this->conn->prepareRead(
            'SELECT platform, aff_campaign_id, conversion_name FROM 202_offline_conversion_names WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $platform = StoredRow::text($row, 'platform');
            if (!isset($names[$platform])) {
                throw new \UnexpectedValueException(
                    '202_offline_conversion_names holds platform ' . var_export($platform, true)
                    . ' for account ' . $userId
                );
            }
            $campaignId = StoredRow::int($row, 'aff_campaign_id');
            if ($campaignId === 0) {
                $names[$platform]['default'] = StoredRow::text($row, 'conversion_name');
            } else {
                $names[$platform]['campaigns'][$campaignId] = StoredRow::text($row, 'conversion_name');
            }
        }

        return $names;
    }

    /**
     * Set or clear names: for each platform, the default (key 0) and the
     * campaigns given, a name or '' to clear it. Campaigns not given keep
     * what they have. Every campaign must be the account's (the caller
     * checks, with the campaigns it shows), and every name pass problem().
     *
     * @param array<string, array<int, string>> $names platform => campaign id (0 the default) => name or ''
     */
    public function save(int $userId, array $names, int $now): void
    {
        foreach ($names as $platform => $byCampaign) {
            if (!in_array($platform, self::PLATFORMS, true)) {
                throw new \InvalidArgumentException('not a platform: ' . $platform);
            }
            foreach ($byCampaign as $campaignId => $name) {
                if ($campaignId < 0 || ($name !== '' && self::problem($name) !== null)) {
                    throw new \InvalidArgumentException(
                        'campaign ' . $campaignId . ' has a name that cannot be stored'
                    );
                }
            }
        }
        $this->conn->transaction(function () use ($userId, $names, $now): void {
            foreach ($names as $platform => $byCampaign) {
                foreach ($byCampaign as $campaignId => $name) {
                    if ($name === '') {
                        $stmt = $this->conn->prepareWrite(
                            'DELETE FROM 202_offline_conversion_names'
                            . ' WHERE user_id = ? AND platform = ? AND aff_campaign_id = ?'
                        );
                        $this->conn->bind($stmt, 'isi', [$userId, $platform, $campaignId]);
                    } else {
                        $stmt = $this->conn->prepareWrite(
                            'INSERT INTO 202_offline_conversion_names'
                            . ' (user_id, platform, aff_campaign_id, conversion_name, updated_at)'
                            . ' VALUES (?, ?, ?, ?, ?)'
                            . ' ON DUPLICATE KEY UPDATE conversion_name = VALUES(conversion_name),'
                            . ' updated_at = VALUES(updated_at)'
                        );
                        $this->conn->bind($stmt, 'isisi', [$userId, $platform, $campaignId, $name, $now]);
                    }
                    $this->conn->executeUpdate($stmt);
                }
            }
        });
    }
}
