# agentcli — Framework Design Document

## Problem Statement

Every CLI team building tools that AI agents will consume reinvents the same 15+ features: structured JSON errors, machine-readable schema generation, output filtering, secret redaction, TTY safety, dry-run, audit logging. Cobra provides excellent command tree management but zero agent/automation support. There is no Go framework that fills this gap.

## Validation: 40+ CLIs Across 12 Categories

We evaluated the framework design against real-world CLIs spanning every major category:

| Category | Representative CLIs | Verdict |
|----------|-------------------|---------|
| **Infrastructure** | kubectl, terraform, docker, aws | Strong fit for CRUD/flags/errors. Gaps: streaming, file input, advanced output formats |
| **Platform/SaaS** | gh, gcloud, stripe, heroku, vercel | Strong fit. Gaps: aliases, plugins, scoping, streaming events |
| **Database** | psql, mongosh, redis-cli | Partial fit (non-REPL commands). REPLs are out of scope |
| **Dev Toolchain** | git, npm, cargo | Partial fit (porcelain commands). Build DAGs are app-specific |
| **Data/ML** | dvc, mlflow, databricks | Strong fit for resource CRUD. Pipeline DAGs are app-specific |
| **Security** | vault, 1password, sops | Strong fit. Secret handling is core strength |
| **Monitoring** | promtool, datadog, grafana | Strong fit for resource management and validation |
| **Local Utility** | ripgrep, restic, ffmpeg | Partial fit. Stdin streaming and progress are gaps |
| **Network/API** | curl, httpie, grpcurl | Partial fit. Request body I/O is app-specific |
| **Build/CI** | make, bazel, goreleaser | Partial fit. DAG execution is app-specific |
| **Communication** | slack-cli, twilio | Strong fit. CRUD + templates |
| **File/Transfer** | rclone, rsync, s3cmd | Strong fit for commands. Transfer engines are app-specific |

**Key finding**: The framework's sweet spot is CLIs that manage resources via APIs (SaaS platforms, infrastructure, communication, monitoring, security, data platforms). These represent the majority of CLIs being built today and the ones most likely to be consumed by AI agents.

## What's In Scope vs Out of Scope

### In Scope (framework provides)
- Structured JSON error output with categories and exit codes
- Machine-readable command schema/manifest generation
- Output pipeline: format selection, field filtering, truncation, secret redaction
- Agent mode: auto-JSON, redaction, audit, non-interactive safety
- Metadata registry: examples, output fields, related commands, allowed values
- Dry-run support
- Confirmation/force for destructive operations
- Error-to-fix suggestion engine
- Workflow/recipe definitions
- User content field tagging (untrusted data marking)
- Metrics/telemetry emission
- Progress reporting (determinate + indeterminate, agent-mode aware)
- Stdin detection and streaming input
- File input flag (`-f`/`--input-file` with format detection)
- Named configuration profiles
- Hierarchical scoping (project/namespace/region)
- Pluggable output formatters (JSON, YAML, CSV, table, custom)
- Option group mixins (reusable flag groups like TLS, pagination)
- Long-running command support (streaming output, signal handling)
- CRUD command factory (generic over any backend)

### Out of Scope (app-specific, framework provides hooks)
- REPL/interactive mode (too varied across tools)
- Build/pipeline DAG execution (too domain-specific)
- Plugin/extension discovery (varies too much; provide interface only)
- Expression/query languages (jq, JMESPath, etc.)
- Protocol implementations (HTTP, gRPC, SSH)
- File transfer engines
- Auth flows (OAuth, SSO, SAML) — framework handles error reporting only

## Package Structure

```
agentcli/
├── go.mod
├── agentcli.go           # App struct, Install(), Execute()
├── errors.go             # CLIError, exit codes, structured error formatting
├── filter.go             # filterFields, truncateFields
├── redact.go             # Secret field redaction
├── interactive.go        # TTY detection, confirmOrFail
├── metadata.go           # commandMeta registry
├── schema.go             # agent-schema command, Cobra tree walker
├── render.go             # Output pipeline orchestration
├── format.go             # Formatter interface + JSON/CSV/table/YAML built-ins
├── audit.go              # Audit logging
├── dryrun.go             # Dry-run wrapping
├── suggest.go            # Error→fix suggestion engine (configurable rules)
├── scope.go              # Named profiles + hierarchical scoping
├── progress.go           # Progress reporter (bar/spinner, agent-mode JSON events)
├── stream.go             # Streaming output (NDJSON, signal handling)
├── stdin.go              # Stdin pipe detection + streaming reader
├── fileinput.go          # --input-file flag, format detection, stdin support
├── options.go            # Reusable option group mixins (pagination, TLS, retry)
├── crud.go               # Generic CRUD factory
├── metrics.go            # Telemetry emission
├── workflow.go           # Workflow/recipe definitions
├── content.go            # User content field tagging
└── agentcli_test.go      # Tests
```

Single Go module: `github.com/tracking202/agentcli`. One import path. Progressive disclosure via the App config struct.

## Core Types

### App — The Central Configuration

```go
type App struct {
    // Required
    Root        *cobra.Command
    Name        string
    Version     string
    Description string

    // Output
    Formatters   map[string]Formatter  // "json", "yaml", "csv", "table" built-in
    DefaultFormat string               // "table" for humans, auto-"json" in agent mode

    // Security
    SecretFields  []string             // field name patterns to redact
    IDPatterns    []string             // field patterns for --id-only extraction
    BlockedCmds   []string             // commands blocked in --agent-mode
    UserContent   map[string][]string  // entity → untrusted field names

    // Error handling
    SuggestRules  []SuggestRule        // error pattern → fix message
    ExitCodes     map[string]int       // category → exit code (defaults provided)
    ErrorClassifier func(error) *CLIError // optional app-specific error classification

    // Persistence
    AuditLogPath  string               // where to write audit log
    ConfigDir     string               // base directory for config/profiles

    // Profiles & Scoping
    Scopes        []ScopeDefinition    // e.g., {Name:"project", Flag:"--project", EnvVar:"MYAPP_PROJECT"}
    ProfileFields []string             // fields stored per profile (url, token, region, ...)

    // Workflows
    Workflows     map[string]Workflow

    // Metrics
    MetricsEnvVar string               // env var to enable metrics (e.g., "MYAPP_METRICS")

    // Optional callbacks
    OnBeforeCommand func(cmd *cobra.Command, args []string) error
    OnAfterCommand  func(cmd *cobra.Command, args []string, err error)
}
```

### Formatter — Pluggable Output Formatting

```go
// Formatter renders structured data to stdout.
type Formatter interface {
    // Format writes the data to w in this format.
    Format(w io.Writer, data []byte) error
    // Name returns the format identifier (e.g., "json", "yaml").
    Name() string
}
```

Built-in formatters: `JSONFormatter`, `TableFormatter`, `CSVFormatter`, `YAMLFormatter`, `ValueFormatter` (single field, no headers — like gcloud's `--format=value`).

Apps register custom formatters:
```go
app.Formatters["jsonpath"] = &JSONPathFormatter{}
app.Formatters["go-template"] = &GoTemplateFormatter{}
```

### CLIError — Structured Error Type

```go
type CLIError struct {
    Category    string            // "validation", "auth", "network", "server", etc.
    Message     string
    ExitCode    int
    FieldErrors map[string]string // per-field validation errors
    HTTPStatus  int               // if from HTTP response
    Wrapped     error             // original error
}
```

Default exit codes (overridable via `App.ExitCodes`):
```
0 = OK
1 = Validation
2 = Auth
3 = Network
4 = Server
5 = Partial Failure
6 = Not Found
7 = Agent Blocked
```

Apps extend with custom categories:
```go
app.ExitCodes["rate_limited"] = 8
app.ExitCodes["quota_exceeded"] = 9
```

### SuggestRule — Error Fix Suggestions

```go
type SuggestRule struct {
    Pattern string  // substring or regex to match in error message
    IsRegex bool    // if true, Pattern is a regex
    Fix     string  // suggested fix command or message
}
```

### commandMeta — Rich Command Metadata

```go
type CommandMeta struct {
    Examples      []string
    OutputFields  []string
    Related       []string
    AllowedValues map[string][]string // flag → valid values
    Mutating      bool
    AcceptsStdin  bool                // declares stdin input support
    Streaming     bool                // declares streaming output
    Maturity      string              // "stable", "beta", "alpha"
    Deprecated    string              // deprecation message (empty = not deprecated)
    SinceVersion  string              // version this command was added
    Tags          []string            // arbitrary tags for filtering schema
}
```

### ScopeDefinition — Hierarchical Context

```go
type ScopeDefinition struct {
    Name        string   // "project", "namespace", "region"
    Flag        string   // "--project"
    ShortFlag   string   // "-p"
    EnvVar      string   // "MYAPP_PROJECT"
    Description string
    Required    bool     // must be set (from flag, env, config, or default)
    Default     string
}
```

Resolution order: flag > env var > active profile > default.

### Workflow — Multi-Step Recipe

```go
type Workflow struct {
    Description string
    Steps       []WorkflowStep
    Tags        []string
}

type WorkflowStep struct {
    Command     string // full command string
    Description string // what this step does
    ExtractVars map[string]string // {"aff_network_id": ".data.id"} — JSONPath-ish extraction
}
```

### Progress — Agent-Aware Progress Reporting

```go
type Progress struct {
    app *App
}

// Bar creates a determinate progress bar.
// In agent mode: emits JSON progress events to stderr.
// In interactive mode: renders a terminal progress bar.
func (p *Progress) Bar(total int64, label string) *ProgressBar

// Spinner creates an indeterminate spinner.
func (p *Progress) Spinner(label string) *Spinner
```

Agent-mode progress event (emitted to stderr):
```json
{"type":"progress","label":"Uploading","current":45,"total":100,"percent":45.0,"elapsed_ms":1200}
```

### StreamWriter — Streaming Output

```go
// StreamWriter emits newline-delimited JSON records for long-running commands.
type StreamWriter struct {
    app *App
    w   io.Writer
}

// WriteEvent emits a single structured event.
func (s *StreamWriter) WriteEvent(event interface{}) error

// Close emits the final summary event.
func (s *StreamWriter) Close(summary interface{}) error
```

In agent mode: emits NDJSON. In human mode: formats each event for terminal display.

### OptionGroup — Reusable Flag Mixins

```go
// OptionGroup is a reusable set of flags that can be mixed into commands.
type OptionGroup interface {
    // AddFlags adds this group's flags to the command.
    AddFlags(cmd *cobra.Command)
    // Validate checks that the flags are consistent.
    Validate() error
    // Name returns the group identifier.
    Name() string
}
```

Built-in option groups:
- `PaginationOptions` — `--limit`, `--offset` or `--cursor`, `--all` (auto-paginate)
- `TimeRangeOptions` — `--from`, `--to`, `--period` (last7, last30, today, etc.)
- `RetryOptions` — `--retries`, `--retry-delay`
- `TLSOptions` — `--tls-cert`, `--tls-key`, `--tls-ca`, `--insecure`
- `SortOptions` — `--sort`, `--sort-dir`

Usage:
```go
pager := agentcli.PaginationOptions{}
pager.AddFlags(listCmd)

listCmd.RunE = func(cmd *cobra.Command, args []string) error {
    params := pager.ToParams() // returns map[string]string{"limit": "10", "offset": "0"}
    // ...
}
```

### CRUDClient — Generic Backend Interface

```go
// CRUDClient is the interface any backend must implement for the CRUD factory.
type CRUDClient interface {
    List(ctx context.Context, endpoint string, params map[string]string) ([]byte, error)
    Get(ctx context.Context, endpoint string, id string) ([]byte, error)
    Create(ctx context.Context, endpoint string, body map[string]string) ([]byte, error)
    Update(ctx context.Context, endpoint string, id string, body map[string]string) ([]byte, error)
    Delete(ctx context.Context, endpoint string, id string) ([]byte, error)
}

// CRUDEntity defines an entity for the CRUD factory.
type CRUDEntity struct {
    Name       string      // "campaign"
    Plural     string      // "campaigns"
    Endpoint   string      // "/api/v3/campaigns"
    Fields     []CRUDField
    ListParams []CRUDField
    IDField    string      // "campaign_id" (defaults to "id")
}

// RegisterCRUD generates list/get/create/update/delete commands under parent.
func (a *App) RegisterCRUD(parent *cobra.Command, entity CRUDEntity, client CRUDClient)
```

### FileInput — Declarative File/Stdin Input

```go
// FileInput handles --input-file and stdin reading with format detection.
type FileInput struct {
    // Populated after Resolve()
    Data   []byte
    Format string // "json", "yaml", "csv", detected from extension or content
}

// AddFlag adds --input-file / -f to the command.
func (fi *FileInput) AddFlag(cmd *cobra.Command)

// Resolve reads from the file path or stdin. Call in RunE.
func (fi *FileInput) Resolve() error
```

## Integration with Cobra

### Install() — One-Time Setup

```go
func (a *App) Install() {
    // 1. Register persistent flags on Root:
    //    --agent-mode, --json, --csv, --format, --fields,
    //    --max-field-length, --id-only, --dry-run, --force
    //    + all scope flags

    // 2. Set PersistentPreRunE:
    //    - Agent mode → auto-enable JSON, validate blocked commands
    //    - Resolve active profile and scopes
    //    - Chain with any existing PersistentPreRunE

    // 3. Register "help agent-schema" command

    // 4. Register "config" command group (if profiles enabled):
    //    config set, config get, config list-profiles, config use-profile,
    //    config add-profile, config delete-profile

    // 5. Set SilenceErrors, SilenceUsage on Root
}
```

### Execute() — Structured Error Handling

```go
func (a *App) Execute() {
    if err := a.Root.Execute(); err != nil {
        if a.IsJSONOutput() || a.IsAgentMode() {
            fmt.Fprintln(os.Stdout, a.FormatError(err))
        } else {
            fmt.Fprintln(os.Stderr, "Error:", err)
        }
        os.Exit(a.ExitCodeFor(err))
    }
}
```

### RegisterMeta() — Command Metadata

```go
// RegisterMeta associates agent metadata with a command path.
func (a *App) RegisterMeta(cmdPath string, meta CommandMeta)
```

Called in `init()` alongside command registration — identical pattern to p202.

## Schema Output

`help agent-schema` produces:

```json
{
  "application": "mytool",
  "version": "1.0.0",
  "description": "...",
  "generated_at": "2026-03-05T08:00:00Z",
  "global_flags": { ... },
  "scopes": [
    {"name": "project", "flag": "--project", "env_var": "MYAPP_PROJECT"}
  ],
  "commands": {
    "resource list": {
      "full_command": "mytool resource list",
      "description": "...",
      "long_description": "...",
      "flags": { ... },
      "arguments": [ ... ],
      "examples": [ ... ],
      "output_fields": [ ... ],
      "related": [ ... ],
      "allowed_values": { ... },
      "mutating": false,
      "accepts_stdin": false,
      "streaming": false,
      "maturity": "stable",
      "tags": ["resource-management"],
      "user_content_fields": [ ... ]
    }
  },
  "workflows": { ... },
  "exit_codes": { ... },
  "agent_mode": {
    "description": "...",
    "blocked_commands": [ ... ],
    "safety_features": [ ... ]
  }
}
```

## Output Pipeline

The render pipeline applies transformations in order:

```
Raw JSON bytes
    │
    ├── --id-only? → extract ID, print, done
    │
    ├── inject _meta.user_content_fields (if entity context)
    │
    ├── --fields name,id → keep only named fields
    │
    ├── --max-field-length 200 → truncate strings
    │
    ├── secret redaction (in agent-mode or non-interactive)
    │
    ├── select formatter:
    │   ├── --json → JSONFormatter
    │   ├── --yaml → YAMLFormatter
    │   ├── --csv  → CSVFormatter
    │   ├── --format X → custom formatter X
    │   └── default → TableFormatter (human) or JSONFormatter (agent)
    │
    └── write to stdout
```

Each stage is a `FilterFunc`:
```go
type FilterFunc func(data []byte) []byte
```

Apps can insert custom filters:
```go
app.AddFilter("after-fields", func(data []byte) []byte {
    // custom transformation
    return data
})
```

## Usage Examples

### Minimal: SaaS Platform CLI

```go
func main() {
    root := &cobra.Command{Use: "myplatform", Version: "2.0.0"}

    app := &agentcli.App{
        Root:         root,
        Name:         "myplatform",
        Version:      "2.0.0",
        Description:  "Manage MyPlatform resources",
        SecretFields: []string{"api_key", "token", "secret"},
        IDPatterns:   []string{"id", "*_id"},
        AuditLogPath: "~/.myplatform/agent_audit.log",
        SuggestRules: []agentcli.SuggestRule{
            {Pattern: "401", Fix: "myplatform auth login"},
            {Pattern: "not found", Fix: "Verify the resource ID. List resources: myplatform <type> list --json"},
        },
    }
    app.Install()

    client := myapi.NewClient()
    projectCmd := &cobra.Command{Use: "project", Short: "Manage projects"}
    root.AddCommand(projectCmd)
    app.RegisterCRUD(projectCmd, agentcli.CRUDEntity{
        Name: "project", Plural: "projects", Endpoint: "/api/projects",
        Fields: []agentcli.CRUDField{
            {Name: "name", Desc: "Project name", Required: true},
            {Name: "description", Desc: "Project description"},
        },
    }, client)

    app.Execute()
}
```

Result: 5 commands (project list/get/create/update/delete) with full agent support, schema generation, structured errors, output filtering, dry-run, audit — zero boilerplate.

### Infrastructure CLI with Scoping

```go
app := &agentcli.App{
    Root:    root,
    Name:    "infratool",
    Version: "1.0.0",
    Scopes: []agentcli.ScopeDefinition{
        {Name: "cluster", Flag: "--cluster", EnvVar: "INFRA_CLUSTER", Required: true},
        {Name: "namespace", Flag: "--namespace", ShortFlag: "-n", EnvVar: "INFRA_NAMESPACE", Default: "default"},
    },
    ProfileFields: []string{"cluster", "namespace", "token", "endpoint"},
}
app.Install()
// Now every command inherits --cluster and --namespace
// `infratool config add-profile staging --cluster staging-1 --namespace apps`
// `infratool config use-profile staging`
```

### Monitoring CLI with Streaming

```go
logsCmd := &cobra.Command{
    Use:   "logs",
    Short: "Stream application logs",
    RunE: func(cmd *cobra.Command, args []string) error {
        stream := app.NewStreamWriter()
        defer stream.Close(map[string]interface{}{"status": "stream_ended"})

        ctx := agentcli.SignalContext() // cancels on SIGINT/SIGTERM
        for event := range logSource.Follow(ctx) {
            if err := stream.WriteEvent(event); err != nil {
                return err
            }
        }
        return nil
    },
}
app.RegisterMeta("logs", agentcli.CommandMeta{
    Streaming: true,
    Examples:  []string{"montool logs --follow --format json"},
})
```

### Security CLI with Extra Redaction

```go
app := &agentcli.App{
    Root:         root,
    Name:         "vaultctl",
    SecretFields: []string{"api_key", "token", "password", "secret", "private_key",
                           "client_secret", "passphrase", "encryption_key", "data"},
    BlockedCmds:  []string{"config set-token", "unseal"},
    UserContent: map[string][]string{
        "secret": {"value", "data", "metadata"},
    },
}
```

### Data CLI with Progress + File Input

```go
importCmd := &cobra.Command{
    Use:   "import",
    Short: "Import data from file",
    RunE: func(cmd *cobra.Command, args []string) error {
        fi := agentcli.FileInput{}
        fi.AddFlag(cmd)
        if err := fi.Resolve(); err != nil {
            return err
        }

        records := parseRecords(fi.Data, fi.Format)
        bar := app.Progress().Bar(int64(len(records)), "Importing")
        for i, rec := range records {
            if err := client.Import(rec); err != nil {
                return err
            }
            bar.Set(int64(i + 1))
        }
        bar.Done()
        return app.Render(summary)
    },
}
```

## CLI Archetype Coverage Matrix

| Feature | REST API / SaaS | Infrastructure | Security | Monitoring | Data/ML | Communication | File/Transfer |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Structured errors | Y | Y | Y | Y | Y | Y | Y |
| Schema manifest | Y | Y | Y | Y | Y | Y | Y |
| Output pipeline | Y | Y | Y | Y | Y | Y | Y |
| Agent mode | Y | Y | Y | Y | Y | Y | Y |
| CRUD factory | Y | Y | Y | Y | partial | Y | partial |
| Dry-run | Y | Y | Y | N/A | Y | Y | Y |
| Profiles/scoping | Y | Y | Y | Y | Y | Y | Y |
| Progress | partial | partial | N/A | N/A | Y | N/A | Y |
| Streaming | N/A | Y | N/A | Y | N/A | partial | N/A |
| File input | partial | Y | Y | N/A | Y | partial | Y |
| Option groups | Y | Y | Y | Y | Y | Y | Y |
| Audit | Y | Y | Y | Y | Y | Y | Y |

## What's Explicitly NOT Covered

These patterns emerged from analysis but are deliberately excluded:

1. **REPLs** (psql, mongosh, redis-cli) — Too varied. Recommend: use a REPL library alongside agentcli for the non-REPL commands.

2. **DAG/pipeline execution** (make, bazel, dvc) — Too domain-specific. The workflow feature documents linear recipes; actual DAG execution is app logic.

3. **Expression languages** (jq, JMESPath, JSONPath) — Infinite variety. Framework provides the `--fields` pipeline stage and lets apps register custom formatters that accept expressions.

4. **Plugin/extension systems** (kubectl plugins, gh extensions) — Too many discovery models. Framework provides a `Plugin` interface apps can implement; doesn't prescribe discovery.

5. **Auth flows** (OAuth, SSO, device flow) — Framework reports auth errors and suggests fixes. Actual auth negotiation is app-specific.

6. **Protocol implementations** — HTTP clients, gRPC, SSH are app-specific. Framework's `CRUDClient` interface abstracts over any transport.

## Extraction Path from p202

| p202 file | → agentcli file | Changes needed |
|-----------|-----------------|----------------|
| `cmd/agent_mode.go` | `interactive.go`, `redact.go`, `filter.go`, `dryrun.go`, `audit.go`, `suggest.go` | Replace hardcoded maps with App config fields |
| `cmd/agent_metadata.go` | `metadata.go` | Add new fields (AcceptsStdin, Streaming, Maturity, Tags) |
| `cmd/agent_schema.go` | `schema.go` | Parameterize app name, add new metadata fields to output |
| `cmd/cli_errors.go` | `errors.go` | Make exit code map configurable |
| `cmd/render.go` | `render.go`, `format.go` | Extract Formatter interface, add YAML/value formatters |
| `cmd/root.go` (flags + error handling) | `agentcli.go` | Wrap as App.Install() and App.Execute() |
| `cmd/crud.go` | `crud.go` | Replace api.Client with CRUDClient interface |
| `internal/metrics/` | `metrics.go` | Parameterize env var name |
| `internal/output/` | `format.go` | Extract table/CSV formatters |

## Implementation Phases

### Phase 1: Core (extract what exists)
- `agentcli.go` — App struct, Install(), Execute()
- `errors.go` — CLIError, exit codes, FormatError()
- `interactive.go` — isInteractive, confirmOrFail
- `redact.go` — configurable secret field redaction
- `filter.go` — filterFields, truncateFields
- `render.go` — output pipeline orchestration
- `format.go` — JSON + table + CSV formatters
- `metadata.go` — CommandMeta registry
- `schema.go` — agent-schema command
- `suggest.go` — SuggestRule engine
- `audit.go` — audit logging
- `dryrun.go` — dry-run wrapping
- `content.go` — user content field tagging
- `metrics.go` — telemetry

### Phase 2: Extend (new capabilities)
- `scope.go` — profiles + hierarchical scoping + config commands
- `format.go` — add YAML + value formatters
- `options.go` — PaginationOptions, TimeRangeOptions, SortOptions, TLSOptions
- `crud.go` — generic CRUD factory with CRUDClient interface
- `workflow.go` — enhanced workflow definitions with variable extraction

### Phase 3: Advanced
- `progress.go` — progress bars/spinners, agent-mode JSON events
- `stream.go` — StreamWriter, NDJSON, signal handling
- `stdin.go` — pipe detection, streaming stdin reader
- `fileinput.go` — --input-file with format detection

## Design Decisions

### Why single package, not multiple?
Users import one thing: `agentcli`. They configure one struct: `App`. If they don't set `SecretFields`, redaction is a no-op. If they don't set `Scopes`, no scope flags are added. Progressive disclosure via config, not import paths. We split later if adoption demands it.

### Why config struct, not functional options?
The App struct is readable, discoverable via IDE autocomplete, and serializable for debugging. Functional options add indirection for no benefit here — the config is set once at startup, not varied per-call.

### Why Cobra extension, not replacement?
Cobra has 35k+ GitHub stars, massive ecosystem, and handles command trees perfectly. Replacing it would mean reimplementing arg parsing, help generation, completion, and fighting muscle memory. We add value on top.

### Why interface for CRUDClient, not generic HTTP?
A vault CLI talks to Vault's API. A Kubernetes CLI talks to the API server. A database CLI talks to a DB driver. The 5-method interface (List/Get/Create/Update/Delete) abstracts over any transport while keeping the CRUD factory useful. Apps that don't fit CRUD simply don't use the factory.
