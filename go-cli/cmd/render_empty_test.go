package cmd

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"p202/internal/api"
)

// render refuses an empty payload rather than printing nothing: an exit of 0
// with nothing on stdout reads as "no results" to a script.
func TestRenderRefusesAnEmptyPayload(t *testing.T) {
	api.ForgetLastAnswer()
	for _, data := range [][]byte{nil, {}, []byte("   \n")} {
		var err error
		stdout, stderr := captureBoth(func() { err = render(data) })
		if err == nil {
			t.Fatalf("render(%q) answered nil", data)
		}
		if stdout != "" || stderr != "" {
			t.Errorf("render(%q) printed: stdout %q stderr %q; Execute prints the error", data, stdout, stderr)
		}
		if got := exitCodeForError(err); got != ExitServer {
			t.Errorf("render(%q): exit code %d, want %d", data, got, ExitServer)
		}
		if got := hintFor(err); !strings.Contains(got, "fault in the CLI") {
			t.Errorf("render(%q) with no request sent: hint %q", data, got)
		}
	}
	if err := render([]byte(`{"data":[]}`)); err != nil {
		t.Errorf("render of an empty list refused it: %v", err)
	}
}

func emptyAnswerServer(t *testing.T, body string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/system/version") {
			_, _ = w.Write([]byte(body))
			return
		}
		t.Errorf("unexpected request %s", r.URL)
		w.WriteHeader(http.StatusNotFound)
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv
}

// A server that answers 200 with no body is a failure the command reports,
// naming the request, in the status and the envelope.
func TestAnEmptyAnswerExitsNonZeroNamingTheRequest(t *testing.T) {
	emptyAnswerServer(t, "")

	stdout, stderr, code := runAsMain("system", "version", "--json")
	if code != ExitServer || stdout != "" {
		t.Fatalf("exit %d, stdout %q; want exit %d and nothing on stdout (stderr %q)", code, stdout, ExitServer, stderr)
	}
	var env struct {
		Error struct {
			Category string `json:"category"`
			Message  string `json:"message"`
			Hint     string `json:"hint"`
			ExitCode int    `json:"exit_code"`
		} `json:"error"`
	}
	if err := json.Unmarshal([]byte(stderr), &env); err != nil {
		t.Fatalf("stderr is not one envelope: %v\n%s", err, stderr)
	}
	if env.Error.Category != "server" || env.Error.ExitCode != ExitServer {
		t.Errorf("envelope %+v, want category server and exit code %d", env.Error, ExitServer)
	}
	if !strings.Contains(env.Error.Message, "GET /system/version with HTTP 200 and an empty body") {
		t.Errorf("message %q does not name the request and its empty answer", env.Error.Message)
	}
	if !strings.Contains(env.Error.Hint, "GET /system/version") || !strings.Contains(env.Error.Hint, "p202 system health") {
		t.Errorf("hint %q does not name the request and the next step", env.Error.Hint)
	}

	stdout, stderr, code = runAsMain("system", "version")
	if code != ExitServer || stdout != "" {
		t.Fatalf("table mode: exit %d, stdout %q; want exit %d and nothing on stdout", code, stdout, ExitServer)
	}
	if !strings.HasPrefix(stderr, "Error [server]: no output produced") || strings.Count(stderr, "no output produced") != 1 {
		t.Errorf("table mode: stderr %q, want the error printed once by Execute", stderr)
	}
}

// When the body held something but not what the command prints, the error
// says how much came back rather than calling it empty.
func TestAnAnswerWithNothingToPrintSaysWhatCameBack(t *testing.T) {
	emptyAnswerServer(t, `{"ok":true}`)
	resetCommandState()
	c, err := api.NewFromConfig()
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.Get("system/version", nil); err != nil {
		t.Fatal(err)
	}
	err = render(nil)
	var cliErr *CLIError
	if !errors.As(err, &cliErr) || cliErr.ExitCode != ExitServer {
		t.Fatalf("render(nil) after an answer: %v, want a server CLIError", err)
	}
	if !strings.Contains(cliErr.Message, "GET /system/version with HTTP 200 and 11 bytes") {
		t.Errorf("message %q does not say what came back", cliErr.Message)
	}
	if !strings.Contains(cliErr.Hint, "p202 system version") {
		t.Errorf("hint %q does not name the version check", cliErr.Hint)
	}

	// The next command starts its record afresh.
	api.ForgetLastAnswer()
	if _, sent := api.LastAnswer(); sent {
		t.Error("ForgetLastAnswer left the previous answer")
	}
}
