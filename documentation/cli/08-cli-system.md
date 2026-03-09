# CLI System Commands

System health checks, diagnostics, and administration.

## system:health

Check system health. Verifies database connectivity and API availability.

```bash
./cli/prosper202 system:health
```

## system:version

Show Prosper202, PHP, MySQL, and API version information.

```bash
./cli/prosper202 system:version
```

## system:errors

Show recent MySQL errors.

```bash
./cli/prosper202 system:errors [--limit 20]
```

| Option | Default | Description |
| ------ | ------- | ----------- |
| `--limit`, `-l` | 20 | Number of errors to show |

## system:cron

Show cron job status and recent execution logs.

```bash
./cli/prosper202 system:cron
```

## system:dataengine

Show data engine job status and count of pending dirty hours.

```bash
./cli/prosper202 system:dataengine
```

## system:db-stats

Show database table row counts and storage sizes.

```bash
./cli/prosper202 system:db-stats
```
