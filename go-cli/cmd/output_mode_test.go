package cmd

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
)

// Two rows, so NDJSON (two lines) and compact JSON (one line) differ.
const outputModeRows = `{"data":[{"aff_campaign_id":7,"aff_campaign_name":"A & B","aff_campaign_url":"https://x.example/?a=1&b=2"},{"aff_campaign_id":8,"aff_campaign_name":"C","aff_campaign_url":"https://y.example/"}]}`

// setupOutputModeServer configures a temp HOME against a server that answers
// every GET with outputModeRows. defaults, when non-nil, become the profile's.
func setupOutputModeServer(t *testing.T, defaults map[string]string) string {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(outputModeRows))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	profile := map[string]interface{}{"url": srv.URL, "api_key": "test-key-123456"}
	if defaults != nil {
		profile["defaults"] = defaults
	}
	writeTestConfigWithProfiles(t, tmp, "default", map[string]map[string]interface{}{"default": profile})
	return tmp
}

// outputKind names the format of a `campaign list` stdout.
func outputKind(t *testing.T, stdout string) string {
	t.Helper()
	trimmed := strings.TrimRight(stdout, "\n")
	lines := strings.Split(trimmed, "\n")
	switch {
	case trimmed == "7\n8":
		return "quiet"
	case json.Valid([]byte(trimmed)) && len(lines) == 1:
		return "json-compact"
	case json.Valid([]byte(trimmed)):
		return "json-pretty"
	case len(lines) == 2 && json.Valid([]byte(lines[0])) && json.Valid([]byte(lines[1])):
		return "ndjson"
	case len(lines) > 1 && strings.HasPrefix(lines[1], "---"):
		return "table"
	case len(lines) == 3 && strings.HasPrefix(lines[0], "aff_campaign_id") && strings.HasPrefix(lines[1], "7"):
		return "csv"
	}
	t.Fatalf("unrecognised output:\n%s", stdout)
	return ""
}

func TestAgentDetectionSelectsCompactJSON(t *testing.T) {
	for _, name := range agentEnvVars {
		t.Run(name, func(t *testing.T) {
			setupOutputModeServer(t, nil)
			t.Setenv(name, "1")
			stdout, stderr, err := executeCommand("campaign", "list")
			if err != nil {
				t.Fatalf("campaign list: %v", err)
			}
			if got := outputKind(t, stdout); got != "json-compact" {
				t.Fatalf("with %s set, got %s, want json-compact:\n%s", name, got, stdout)
			}
			if !strings.Contains(stdout, `"aff_campaign_name":"A & B"`) {
				t.Errorf("compact JSON should keep & unescaped: %s", stdout)
			}
			if stderr != "" {
				t.Errorf("auto-JSON must keep stderr quiet, got %q", stderr)
			}
		})
	}
}

func TestNoAgentKeepsTables(t *testing.T) {
	cases := map[string]string{
		"nothing set":         "",
		"AI_AGENT empty":      "AI_AGENT=",
		"CLAUDECODE=0":        "CLAUDECODE=0",
		"GEMINI_CLI=false":    "GEMINI_CLI=false",
		"editor terminal var": "TERM_PROGRAM=vscode",
	}
	for label, kv := range cases {
		t.Run(label, func(t *testing.T) {
			setupOutputModeServer(t, nil)
			if kv != "" {
				k, v, _ := strings.Cut(kv, "=")
				t.Setenv(k, v)
			}
			stdout, _, err := executeCommand("campaign", "list")
			if err != nil {
				t.Fatalf("campaign list: %v", err)
			}
			if got := outputKind(t, stdout); got != "table" {
				t.Fatalf("got %s, want table:\n%s", got, stdout)
			}
		})
	}
}

// Precedence: explicit flag > P202_OUTPUT > config output.format > agent > table.
func TestOutputPrecedence(t *testing.T) {
	cases := []struct {
		name     string
		agent    bool
		env      string
		defaults map[string]string
		args     []string
		want     string
	}{
		{name: "agent, --json stays pretty", agent: true, args: []string{"--json"}, want: "json-pretty"},
		{name: "agent, --ndjson", agent: true, args: []string{"--ndjson"}, want: "ndjson"},
		{name: "agent, --csv", agent: true, args: []string{"--csv"}, want: "csv"},
		{name: "agent, -q", agent: true, args: []string{"-q"}, want: "quiet"},
		{name: "agent, --table", agent: true, args: []string{"--table"}, want: "table"},
		{name: "agent, --wide keeps the table", agent: true, args: []string{"--wide"}, want: "table"},
		{name: "agent, --fields keeps the table", agent: true, args: []string{"--fields", "aff_campaign_id"}, want: "table"},
		{name: "agent, --raw-headers keeps the table", agent: true, args: []string{"--raw-headers"}, want: "table"},
		{name: "agent, P202_OUTPUT=table", agent: true, env: "table", want: "table"},
		{name: "agent, P202_OUTPUT=json is pretty", agent: true, env: "json", want: "json-pretty"},
		{name: "P202_OUTPUT=json", env: "json", want: "json-pretty"},
		{name: "P202_OUTPUT=ndjson", env: "ndjson", want: "ndjson"},
		{name: "P202_OUTPUT=csv", env: "csv", want: "csv"},
		{name: "P202_OUTPUT is case-insensitive", agent: true, env: " TABLE ", want: "table"},
		{name: "flag beats P202_OUTPUT", env: "csv", args: []string{"--json"}, want: "json-pretty"},
		{name: "--table beats P202_OUTPUT", agent: true, env: "json", args: []string{"--table"}, want: "table"},
		{name: "config default json", defaults: map[string]string{outputDefaultKey: "json"}, want: "json-pretty"},
		{name: "config default beats agent", agent: true, defaults: map[string]string{outputDefaultKey: "table"}, want: "table"},
		{name: "P202_OUTPUT beats config default", env: "ndjson", defaults: map[string]string{outputDefaultKey: "table"}, want: "ndjson"},
		{name: "flag beats config default", defaults: map[string]string{outputDefaultKey: "json"}, args: []string{"--table"}, want: "table"},
		{name: "--fields does not override P202_OUTPUT=csv", env: "csv", args: []string{"--fields", "aff_campaign_id"}, want: "csv"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			setupOutputModeServer(t, tc.defaults)
			if tc.agent {
				t.Setenv("CLAUDECODE", "1")
			}
			if tc.env != "" {
				t.Setenv(outputEnvVar, tc.env)
			}
			stdout, _, err := executeCommand(append([]string{"campaign", "list"}, tc.args...)...)
			if err != nil {
				t.Fatalf("campaign list %v: %v", tc.args, err)
			}
			if got := outputKind(t, stdout); got != tc.want {
				t.Fatalf("got %s, want %s:\n%s", got, tc.want, stdout)
			}
		})
	}
}

func TestTableFlagConflictsWithOtherFormats(t *testing.T) {
	for _, other := range []string{"--json", "--ndjson", "--csv", "--quiet"} {
		t.Run(other, func(t *testing.T) {
			setupOutputModeServer(t, nil)
			_, _, err := executeCommand("campaign", "list", "--table", other)
			if err == nil || !strings.Contains(err.Error(), "--table and "+other) {
				t.Fatalf("want a --table/%s conflict, got %v", other, err)
			}
			if exitCodeForError(err) != ExitValidation {
				t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
			}
		})
	}
}

// Under auto-JSON a failure is the JSON envelope on stderr and nothing on
// stdout, exactly as under --json; --table brings the text lines back.
func TestAutoJSONErrorEnvelope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"message":"Invalid API key"}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	t.Setenv("AI_AGENT", "claude-code_2-1-285_agent")

	stdout, _, err := executeCommand("campaign", "list")
	if err == nil {
		t.Fatal("expected a 401 error")
	}
	if stdout != "" {
		t.Errorf("stdout must stay empty on failure, got %q", stdout)
	}
	var buf bytes.Buffer
	printError(&buf, err)
	if strings.Count(buf.String(), "\n") != 1 {
		t.Errorf("envelope should be one line, got %q", buf.String())
	}
	var env map[string]map[string]interface{}
	if jErr := json.Unmarshal(buf.Bytes(), &env); jErr != nil {
		t.Fatalf("auto-JSON error is not a JSON envelope: %v\n%s", jErr, buf.String())
	}
	if env["error"]["category"] != "auth" || env["error"]["exit_code"] != float64(ExitAuth) || env["error"]["command"] != "p202 campaign list" {
		t.Errorf("envelope = %v", env["error"])
	}

	_, _, err = executeCommand("campaign", "list", "--table")
	if err == nil {
		t.Fatal("expected a 401 error")
	}
	buf.Reset()
	printError(&buf, err)
	if !strings.HasPrefix(buf.String(), "Error [auth]: ") {
		t.Errorf("--table should print the text error, got %q", buf.String())
	}
}

// Errors Cobra raises before PersistentPreRunE (unknown command, wrong
// argument count) still pick the envelope from the environment.
func TestRecoverCommandContextDetectsAgent(t *testing.T) {
	oldPath, oldJSON, oldND := activeCommandPath, jsonOutput, ndjsonOutput
	defer func() { activeCommandPath, jsonOutput, ndjsonOutput = oldPath, oldJSON, oldND }()
	t.Setenv("CLAUDECODE", "1")

	activeCommandPath, jsonOutput, ndjsonOutput = "", false, false
	recoverCommandContext([]string{"campaign", "bogus"})
	if !jsonOutput || !compactJSON {
		t.Errorf("an agent should get the JSON envelope for an unknown command (json=%v compact=%v)", jsonOutput, compactJSON)
	}

	for _, args := range [][]string{
		{"campaign", "get", "--table"},
		{"campaign", "get", "--raw-headers"},
		{"campaign", "get", "--fields=aff_campaign_id"},
	} {
		activeCommandPath, jsonOutput, ndjsonOutput = "", false, false
		recoverCommandContext(args)
		if jsonOutput {
			t.Errorf("%v should keep the text error", args)
		}
	}

	t.Setenv(outputEnvVar, "table")
	activeCommandPath, jsonOutput, ndjsonOutput = "", false, false
	recoverCommandContext([]string{"campaign", "get"})
	if jsonOutput {
		t.Error("P202_OUTPUT=table should keep the text error")
	}
}

func TestInvalidP202OutputIsAValidationError(t *testing.T) {
	setupOutputModeServer(t, nil)
	t.Setenv(outputEnvVar, "yaml")

	stdout, _, err := executeCommand("campaign", "list")
	if err == nil || !strings.Contains(err.Error(), `P202_OUTPUT="yaml"`) {
		t.Fatalf("want a P202_OUTPUT error, got %v", err)
	}
	if stdout != "" {
		t.Errorf("nothing may run with an unusable P202_OUTPUT, got %q", stdout)
	}
	if exitCodeForError(err) != ExitValidation || !strings.Contains(err.Error(), "json, table, ndjson, csv") {
		t.Errorf("exit=%d err=%v", exitCodeForError(err), err)
	}
	if jsonOutput {
		t.Error("without an agent the error prints as text")
	}

	t.Setenv("CLAUDECODE", "1")
	_, _, err = executeCommand("campaign", "list")
	if err == nil || !jsonOutput {
		t.Errorf("an agent should get this error as a JSON envelope (err=%v json=%v)", err, jsonOutput)
	}
}

func TestConfigOutputFormatDefault(t *testing.T) {
	tmp := setupOutputModeServer(t, nil)

	if _, _, err := executeCommand("config", "set-default", outputDefaultKey, "yaml"); err == nil {
		t.Fatal("set-default output.format yaml should be refused")
	}
	if _, _, err := executeCommand("config", "set-default", outputDefaultKey, "JSON"); err != nil {
		t.Fatalf("set-default output.format JSON: %v", err)
	}
	raw := readSavedConfigRaw(t, tmp)
	defaults := raw["profiles"].(map[string]interface{})["default"].(map[string]interface{})["defaults"].(map[string]interface{})
	if defaults[outputDefaultKey] != "json" {
		t.Errorf("stored %v, want json", defaults[outputDefaultKey])
	}
	stdout, _, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list: %v", err)
	}
	if got := outputKind(t, stdout); got != "json-pretty" {
		t.Errorf("got %s, want json-pretty", got)
	}
}

// A hand-edited bad value blocks commands with a fix, but not `p202 config`,
// which is how it gets fixed.
func TestInvalidConfigOutputFormat(t *testing.T) {
	setupOutputModeServer(t, map[string]string{outputDefaultKey: "yml"})

	_, _, err := executeCommand("campaign", "list")
	if err == nil || !strings.Contains(hintFor(err), "p202 config unset-default output.format") {
		t.Fatalf("want an error pointing at unset-default, got %v (hint %q)", err, hintFor(err))
	}
	if _, _, err := executeCommand("config", "unset-default", outputDefaultKey); err != nil {
		t.Fatalf("config unset-default must still work: %v", err)
	}
	if _, _, err := executeCommand("campaign", "list"); err != nil {
		t.Fatalf("campaign list after the fix: %v", err)
	}
}

func TestConfigShowReportsOutputMode(t *testing.T) {
	setupOutputModeServer(t, nil)

	stdout, _, err := executeCommand("config", "show")
	if err != nil {
		t.Fatalf("config show: %v", err)
	}
	if !strings.Contains(stdout, "Output:      table (default)") {
		t.Errorf("config show should say tables are the default:\n%s", stdout)
	}

	t.Setenv("CLAUDECODE", "1")
	stdout, _, err = executeCommand("config", "show")
	if err != nil {
		t.Fatalf("config show: %v", err)
	}
	if outputKind(t, stdout) != "json-compact" {
		t.Fatalf("an agent should get compact JSON from config show:\n%s", stdout)
	}
	var shown map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &shown); err != nil {
		t.Fatal(err)
	}
	if shown["output_format"] != "json (compact)" || shown["output_source"] != "agent:CLAUDECODE" {
		t.Errorf("output_format=%v output_source=%v", shown["output_format"], shown["output_source"])
	}
	if !strings.HasPrefix(shown["url"].(string), "http://127.0.0.1:") {
		t.Errorf("url = %v", shown["url"])
	}
}

func TestShellUnderAgent(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	t.Setenv("CLAUDECODE", "1")

	// Batch mode emits JSONL; a command's own --table still wins.
	stdout, _, err := executeCommand("shell", "-c", "campaign list; campaign list --table; campaign list")
	if err != nil {
		t.Fatalf("shell: %v", err)
	}
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) != 3 {
		t.Fatalf("want 3 JSONL lines, got %d:\n%s", len(lines), stdout)
	}
	for i, line := range lines {
		var r map[string]interface{}
		if err := json.Unmarshal([]byte(line), &r); err != nil {
			t.Fatalf("line %d is not JSON: %v\n%s", i, err, line)
		}
		_, hasData := r["data"]
		_, hasText := r["output"]
		if wantTable := i == 1; wantTable != hasText || wantTable == hasData {
			t.Errorf("line %d: data=%v output=%v\n%s", i, hasData, hasText, line)
		}
	}

	// --table on the shell holds for every command in it.
	stdout, _, err = executeCommand("shell", "--table", "-c", "campaign list; campaign list")
	if err != nil {
		t.Fatalf("shell --table: %v", err)
	}
	if strings.Count(stdout, "Test Campaign") != 2 || strings.Contains(stdout, "{") {
		t.Errorf("shell --table should print tables:\n%s", stdout)
	}
}

func TestExecChildrenFollowTheParentsFormat(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
	})
	t.Setenv("CLAUDECODE", "1")

	originalRunner := execProfileRunner
	t.Cleanup(func() { execProfileRunner = originalRunner })
	var mu sync.Mutex
	var got []execCall
	execProfileRunner = func(call execCall) execResult {
		mu.Lock()
		got = append(got, call)
		mu.Unlock()
		return execResult{Profile: call.Profile, Stdout: "{}\n"}
	}

	if _, _, err := executeCommand("exec", "--all-profiles", "--", "campaign", "list"); err != nil {
		t.Fatalf("exec: %v", err)
	}
	if _, _, err := executeCommand("exec", "--table", "--all-profiles", "--", "campaign", "list"); err != nil {
		t.Fatalf("exec --table: %v", err)
	}
	if len(got) != 2 || !got[0].ForceJSON || got[1].ForceJSON {
		t.Fatalf("calls = %+v; want JSON forwarded for the agent and not under --table", got)
	}
	if env := execChildEnv(got[0]); len(env) != 0 {
		t.Errorf("a JSON parent passes --json, not env: %v", env)
	}
	if env := execChildEnv(got[1]); len(env) != 1 || env[0] != "P202_OUTPUT=table" {
		t.Errorf("a text parent must pin its children to tables, got %v", env)
	}
}

// The detection list is documented; a marker added in code must be too.
func TestAgentMarkersAreDocumented(t *testing.T) {
	for _, name := range append([]string{outputEnvVar, outputDefaultKey, "--table"}, agentEnvVars...) {
		if !strings.Contains(outputHelp, name) {
			t.Errorf("p202 --help does not mention %s", name)
		}
	}
	doc, err := os.ReadFile(repoPath("docs", "cli-agent.md"))
	if err != nil {
		t.Fatalf("reading docs/cli-agent.md: %v", err)
	}
	for _, name := range append([]string{outputEnvVar, outputDefaultKey, "--table"}, agentEnvVars...) {
		if !strings.Contains(string(doc), "`"+name+"`") {
			t.Errorf("docs/cli-agent.md does not mention `%s`", name)
		}
	}
}
