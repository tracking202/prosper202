package cmd

import (
	"os"
	"strconv"
	"strings"
)

// How the output format is chosen when no format flag is given:
// P202_OUTPUT, then the profile's output.format default, then compact JSON
// when an AI agent runs p202, then tables. Explicit flags always win.
const (
	outputEnvVar     = "P202_OUTPUT"
	outputDefaultKey = "output.format"

	sourceFlag    = "flag"
	sourceEnv     = outputEnvVar
	sourceConfig  = "config"
	sourceAgent   = "agent"
	sourceDefault = "default"
)

var outputFormats = []string{"json", "table", "ndjson", "csv"}

// agentEnvVars are set by AI coding agents on the commands they run. Editor
// terminals a person types into (TERM_PROGRAM, CURSOR_TRACE_ID) are not
// agents and are deliberately absent; docs/cli-agent.md lists the evidence.
var agentEnvVars = []string{
	"AI_AGENT",                       // cross-tool convention (Claude Code, Copilot, ...)
	"CLAUDECODE",                     // Claude Code
	"GEMINI_CLI",                     // Gemini CLI
	"CODEX_SANDBOX",                  // Codex, under Seatbelt
	"CODEX_SANDBOX_NETWORK_DISABLED", // Codex shell tool
	"CODEX_THREAD_ID",                // Codex
	"CURSOR_AGENT",                   // Cursor CLI agent
}

var (
	tableOutput bool // --table
	// compactJSON prints JSON on one line: set only when JSON was chosen
	// because an agent was detected, never for --json.
	compactJSON bool
	// outputSource says why the format is what it is (config show reports it).
	outputSource = sourceDefault
	// outputImplicit: the format came from P202_OUTPUT, config or an agent,
	// so the shell re-derives it per command instead of pinning it.
	outputImplicit bool
)

// outputFlags is what the command line asked for.
type outputFlags struct {
	json, ndjson, csv, quiet, table bool
	// --wide, --raw-headers, --fields: they only shape a table, so asking
	// for one keeps the table an agent would otherwise not get.
	tableShaping bool
}

func (f outputFlags) explicit() bool {
	return f.json || f.ndjson || f.csv || f.quiet || f.table
}

func parsedOutputFlags() outputFlags {
	return outputFlags{
		json: jsonOutput, ndjson: ndjsonOutput, csv: csvOutput, quiet: quietOutput, table: tableOutput,
		tableShaping: wideOutput || rawHeaders || strings.TrimSpace(fieldsFlag) != "",
	}
}

// outputFlagsFromArgs reads the format flags from raw arguments, for errors
// Cobra raises before it parses any flag.
func outputFlagsFromArgs(args []string) outputFlags {
	var f outputFlags
	for _, a := range args {
		if a == "--" {
			break
		}
		if !strings.HasPrefix(a, "-") {
			continue
		}
		name, value, hasValue := strings.Cut(a, "=")
		on := true
		if hasValue {
			on, _ = strconv.ParseBool(value)
		}
		switch strings.ReplaceAll(name, "_", "-") {
		case "--json":
			f.json = on
		case "--ndjson":
			f.ndjson = on
		case "--csv":
			f.csv = on
		case "--quiet", "-q":
			f.quiet = on
		case "--table":
			f.table = on
		case "--wide", "--raw-headers":
			f.tableShaping = f.tableShaping || on
		case "--fields":
			f.tableShaping = f.tableShaping || !hasValue || strings.TrimSpace(value) != ""
		}
	}
	return f
}

func outputFlagConflict(f outputFlags) error {
	if f.json && f.csv {
		return validationError("--json and --csv cannot be used together").WithHint("Pick one output mode.")
	}
	if !f.table {
		return nil
	}
	for _, other := range []struct {
		name string
		set  bool
	}{{"--json", f.json}, {"--ndjson", f.ndjson}, {"--csv", f.csv}, {"--quiet", f.quiet}} {
		if other.set {
			return validationError("--table and %s cannot be used together", other.name).WithHint("Pick one output mode.")
		}
	}
	return nil
}

// resolveOutputMode settles the output globals before a command runs. An
// error still leaves a format chosen, so the error prints in it.
func resolveOutputMode(f outputFlags) error {
	jsonOutput, ndjsonOutput, csvOutput, quietOutput, tableOutput = f.json, f.ndjson, f.csv, f.quiet, f.table
	compactJSON, outputImplicit = false, false
	outputSource = sourceFlag
	if err := outputFlagConflict(f); err != nil {
		return err
	}
	if f.explicit() {
		return nil
	}
	format, source, err := implicitOutputFormat(f.tableShaping)
	outputSource = source
	outputImplicit = source != sourceDefault
	switch format {
	case "json":
		jsonOutput = true
		compactJSON = strings.HasPrefix(source, sourceAgent)
	case "ndjson":
		ndjsonOutput = true
	case "csv":
		csvOutput = true
	}
	return err
}

func implicitOutputFormat(tableShaping bool) (format, source string, err error) {
	if raw := strings.TrimSpace(os.Getenv(outputEnvVar)); raw != "" {
		if f, ok := parseOutputFormat(raw); ok {
			return f, sourceEnv, nil
		}
		err = validationError("%s=%q is not an output format; use one of: %s", outputEnvVar, raw, strings.Join(outputFormats, ", ")).
			WithHint("Set %s to one of those values, or unset it.", outputEnvVar)
	}
	if raw := strings.TrimSpace(getConfigDefault("output", "format")); raw != "" {
		if f, ok := parseOutputFormat(raw); ok {
			return f, sourceConfig, err
		}
		// Not while running `p202 config`, which is how it gets fixed.
		if err == nil && !strings.HasPrefix(activeCommandPath, "p202 config") {
			err = validationError("config default %s=%q is not an output format; use one of: %s", outputDefaultKey, raw, strings.Join(outputFormats, ", ")).
				WithHint("Fix it with `p202 config set-default %s table`, or remove it with `p202 config unset-default %s`.", outputDefaultKey, outputDefaultKey)
		}
	}
	if !tableShaping {
		if name, ok := detectAgent(); ok {
			return "json", sourceAgent + ":" + name, err
		}
	}
	return "table", sourceDefault, err
}

func parseOutputFormat(raw string) (string, bool) {
	v := strings.ToLower(strings.TrimSpace(raw))
	for _, f := range outputFormats {
		if v == f {
			return v, true
		}
	}
	return "", false
}

// detectAgent returns the first agent marker set in the environment.
func detectAgent() (string, bool) {
	for _, name := range agentEnvVars {
		switch strings.ToLower(strings.TrimSpace(os.Getenv(name))) {
		case "", "0", "false", "no", "off":
			continue
		}
		return name, true
	}
	return "", false
}

// outputFormatName names the resolved format for config show.
func outputFormatName() string {
	switch {
	case quietOutput:
		return "quiet"
	case ndjsonOutput:
		return "ndjson"
	case jsonOutput && compactJSON:
		return "json (compact)"
	case jsonOutput:
		return "json"
	case csvOutput:
		return "csv"
	}
	return "table"
}

// execChildEnv keeps exec's children on the parent's format: JSON crosses
// as --json, anything else as P202_OUTPUT=table, so a child never switches
// to JSON on its own under a parent printing text.
func execChildEnv(call execCall) []string {
	if call.ForceJSON {
		return nil
	}
	return []string{outputEnvVar + "=table"}
}
