# Prosper202 CLI

Prosper202 includes a command-line interface built on Symfony Console. The CLI provides full access to campaign management, reporting, attribution, user administration, and system diagnostics without needing the web UI.

## Installation

The CLI binary is located at `cli/prosper202` in your Prosper202 installation directory.

```bash
# Make executable (if needed)
chmod +x cli/prosper202

# Run any command
./cli/prosper202 <command> [options] [arguments]
```

## Configuration

Before using the CLI, configure the connection to your Prosper202 instance:

```bash
# Set the base URL of your Prosper202 server
./cli/prosper202 config:set-url https://your-domain.com

# Set your API key
./cli/prosper202 config:set-key YOUR_API_KEY

# Verify connectivity
./cli/prosper202 config:test

# View current configuration (API key is masked)
./cli/prosper202 config:show
```

## Global Options

All commands (except `config:*`) support these options:

| Option | Description |
| ------ | ----------- |
| `--json` | Output raw JSON response instead of formatted table |

## Command Groups

| Group | Description | Documentation |
| ----- | ----------- | ------------- |
| `config:*` | CLI configuration | [Configuration](01-cli-config.md) |
| `click:*` | Click inspection | [Clicks](02-cli-clicks.md) |
| `conversion:*` | Conversion management | [Conversions](03-cli-conversions.md) |
| `report:*` | Performance reports | [Reports](04-cli-reports.md) |
| `rotator:*` | Rotator and rule management | [Rotators](05-cli-rotators.md) |
| `attribution:*` | Attribution models, snapshots, exports | [Attribution](06-cli-attribution.md) |
| `user:*` | User, role, API key, and preference management | [Users](07-cli-users.md) |
| `system:*` | Health checks, diagnostics, administration | [System](08-cli-system.md) |

## Quick Reference

```bash
# List all commands
./cli/prosper202 list

# Get help for any command
./cli/prosper202 help <command>

# Examples
./cli/prosper202 report:summary --period last7
./cli/prosper202 click:list --limit 10 --click_lead 1
./cli/prosper202 system:health
./cli/prosper202 user:list --json
```
