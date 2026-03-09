# CLI Report Commands

Generate performance reports from the command line.

## Common Options

All report commands share these time-range options:

| Option | Description |
| ------ | ----------- |
| `--period`, `-p` | Shortcut: `today`, `yesterday`, `last7`, `last30`, `last90` |
| `--time_from` | Unix timestamp start (use instead of period) |
| `--time_to` | Unix timestamp end (use instead of period) |
| `--aff_campaign_id` | Filter by campaign |
| `--ppc_account_id` | Filter by PPC account |

## report:summary

Get overall performance summary.

```bash
./cli/prosper202 report:summary --period last30
```

## report:breakdown

Get performance broken down by a dimension.

```bash
./cli/prosper202 report:breakdown [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--breakdown`, `-b` | `campaign` | Dimension (see below) |
| `--sort`, `-s` | `total_clicks` | Sort metric |
| `--sort_dir` | `DESC` | Sort direction (`ASC`/`DESC`) |
| `--limit`, `-l` | 50 | Results per page |
| `--offset`, `-o` | 0 | Pagination offset |

Additional filter options: `--aff_network_id`, `--ppc_network_id`, `--landing_page_id`, `--country_id`.

**Breakdown dimensions:** `campaign`, `aff_network`, `ppc_account`, `ppc_network`, `landing_page`, `keyword`, `country`, `city`, `browser`, `platform`, `device`, `isp`, `text_ad`.

**Sort metrics:** `total_clicks`, `total_leads`, `total_income`, `total_cost`, `total_net`, `roi`, `epc`, `conv_rate`.

### Examples

```bash
# Top countries by revenue
./cli/prosper202 report:breakdown -b country -s total_income --period last7

# Campaign performance sorted by ROI
./cli/prosper202 report:breakdown -b campaign -s roi --period last30

# Landing page breakdown for a specific campaign
./cli/prosper202 report:breakdown -b landing_page --aff_campaign_id 5 --period today
```

## report:timeseries

Get performance over time intervals.

```bash
./cli/prosper202 report:timeseries [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--interval`, `-i` | `day` | Grouping: `hour`, `day`, `week`, `month` |

### Example

```bash
./cli/prosper202 report:timeseries -i hour --period today --aff_campaign_id 5
```

## report:daypart

Get performance by hour of day (0-23).

```bash
./cli/prosper202 report:daypart [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--sort`, `-s` | `hour_of_day` | Sort metric |
| `--sort_dir` | `ASC` | Sort direction |

**Sort options:** `hour_of_day`, `total_clicks`, `total_click_throughs`, `total_leads`, `total_income`, `total_cost`, `total_net`, `epc`, `avg_cpc`, `conv_rate`, `roi`, `cpa`.

## report:weekpart

Get performance by day of week.

```bash
./cli/prosper202 report:weekpart [options]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--sort`, `-s` | `day_of_week` | Sort metric |
| `--sort_dir` | `ASC` | Sort direction |

Same sort options as `report:daypart` (with `day_of_week` replacing `hour_of_day`).
