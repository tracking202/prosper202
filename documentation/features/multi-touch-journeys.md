# Multi-touch Journey Storage and Hydration

Prosper202 now persists full click journeys for every conversion so attribution strategies can operate on complete paths instead of single-touch snapshots.

## Schema

The `202_conversion_touchpoints` table records the ordered journey for each conversion:

| Column | Description |
| --- | --- |
| `touchpoint_id` | Auto-increment primary key. |
| `conv_id` | Foreign key to `202_conversion_logs.conv_id`. |
| `click_id` | Click identifier contained in the journey. |
| `click_time` | Timestamp of the touch. |
| `position` | Zero-based order of the touch within the journey. |
| `created_at` | Time the journey snapshot was stored. |

Journeys are rebuilt whenever a conversion is logged and can be regenerated safely—existing touchpoints are deleted before new rows are inserted.

## Persistence Flow

1. **Conversion capture**: Pixel endpoints (`gpb.php`, `gpx.php`, `upx.php`) now hydrate a `ConversionJourneyRepository` immediately after writing to `202_conversion_logs`.
2. **Journey lookup**: The repository fetches historic clicks for the same user and campaign inside a 30-day lookback window, ensuring the converting click is always represented.
3. **Cache protection**: Any cached journey payload keyed by `attribution_journey_{conv_id}` is purged so downstream consumers always see the updated multi-touch path.

A CLI backfill script (`202-cronjobs/backfill-conversion-journeys.php`) is available to regenerate journeys for historical conversions:

```bash
php 202-cronjobs/backfill-conversion-journeys.php --start=1696118400 --end=1698720000 --batch-size=1000
```

Use the optional `--user` flag to scope processing to a single account.

## Repository Contract

`MysqlConversionRepository::fetchForUser()` now returns `ConversionRecord` objects with hydrated journeys. When a journey is unavailable (e.g., historical conversion without backfill), `ConversionRecord::getJourney()` will synthesise a last-touch-only path to preserve backwards compatibility.

Touchpoints are always sorted chronologically (breaking ties by `click_id`) and deduplicated server-side to prevent duplicate clicks from appearing in the payload.
