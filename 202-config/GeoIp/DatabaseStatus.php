<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * What the GeoIP City file is: current, stale (older than the threshold),
 * missing, or unreadable. Only a file whose metadata was read has a build
 * date and an age; the other two carry the reason instead, so neither can
 * be shown as fresh (CLAUDE.md #11).
 */
final class DatabaseStatus
{
    public const CURRENT = 'current';
    public const STALE = 'stale';
    public const MISSING = 'missing';
    public const UNREADABLE = 'unreadable';

    private function __construct(
        public readonly string $state,
        public readonly string $path,
        public readonly int $staleAfterDays,
        public readonly ?string $databaseType,
        public readonly ?int $builtAt,
        public readonly ?int $ageDays,
        public readonly ?string $reason,
    ) {
    }

    public static function missing(string $path, int $staleAfterDays, string $reason): self
    {
        return new self(self::MISSING, $path, $staleAfterDays, null, null, null, $reason);
    }

    public static function unreadable(string $path, int $staleAfterDays, ?string $type, string $reason): self
    {
        return new self(self::UNREADABLE, $path, $staleAfterDays, $type, null, null, $reason);
    }

    public static function built(string $path, int $staleAfterDays, string $type, int $builtAt, int $now): self
    {
        $age = max(0, $now - $builtAt);
        $stale = $age > $staleAfterDays * 86400;

        return new self(
            $stale ? self::STALE : self::CURRENT,
            $path,
            $staleAfterDays,
            $type,
            $builtAt,
            intdiv($age, 86400),
            $stale ? 'Built on ' . gmdate('Y-m-d', $builtAt) . ', ' . intdiv($age, 86400) . ' days ago: older than '
                . $staleAfterDays . ' days, so address blocks allocated or moved since read as unplaced or in the'
                . ' wrong country.' : null,
        );
    }

    /** Whether lookups can read the file at all. */
    public function readable(): bool
    {
        return $this->builtAt !== null;
    }

    public function builtOn(): ?string
    {
        return $this->builtAt === null ? null : gmdate('Y-m-d', $this->builtAt);
    }

    /**
     * For GET /system/info and Account › Settings.
     *
     * @return array{
     *     state: string, path: string, database_type: ?string, built_at: ?int, built_on: ?string,
     *     age_days: ?int, stale_after_days: int, reason: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'path' => $this->path,
            'database_type' => $this->databaseType,
            'built_at' => $this->builtAt,
            'built_on' => $this->builtOn(),
            'age_days' => $this->ageDays,
            'stale_after_days' => $this->staleAfterDays,
            'reason' => $this->reason,
        ];
    }

    /**
     * For the health probes, one of which answers without a key: the state
     * and the date, never the path or a reader's error.
     *
     * @return array{state: string, built_at: ?int, built_on: ?string, age_days: ?int, stale_after_days: int}
     */
    public function healthFields(): array
    {
        return [
            'state' => $this->state,
            'built_at' => $this->builtAt,
            'built_on' => $this->builtOn(),
            'age_days' => $this->ageDays,
            'stale_after_days' => $this->staleAfterDays,
        ];
    }

    /** The health probes' warning, or null for a current file. Names no path. */
    public function warning(): ?string
    {
        return match ($this->state) {
            self::CURRENT => null,
            self::STALE => 'The GeoIP database was built on ' . $this->builtOn() . ', ' . $this->ageDays
                . ' days ago (stale after ' . $this->staleAfterDays . '): visitors in address blocks allocated or moved'
                . ' since read as unplaced or in the wrong country. Set up its refresh in Account › Settings › GeoIP'
                . ' database.',
            self::MISSING => 'There is no GeoIP database where lookups read it, so no visitor can be placed.'
                . ' Account › Settings › GeoIP database says where it belongs.',
            default => 'The GeoIP database cannot be read, so no visitor can be placed.'
                . ' Account › Settings › GeoIP database says why.',
        };
    }
}
