package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Attribution exports: every flag is checked before a client is built (no
// config at all here, as in CI), ids and times go out as JSON numbers, the
// download writes the bytes it received and nothing on failure, and every
// refusal the server can give leaves the agent a next step.
func TestAttributionExportCommandsValidateBeforeTheyConnect(t *testing.T) {
	setTestHome(t, t.TempDir())

	for _, tc := range []struct {
		name    string
		args    []string
		message string
		hint    string
	}{
		{"create by an unknown dimension", []string{"attribution", "export", "create", "--group-by", "planet"}, `invalid --group-by "planet"; valid: campaign`, ""},
		{"create with a model name", []string{"attribution", "export", "create", "--model", "linear"}, `invalid --model "linear"`, "p202 attribution model list"},
		{"create comparing a model with itself", []string{"attribution", "export", "create", "--model", "2", "--compare-model", "2"}, "--compare-model must differ from --model", ""},
		{"create with a period and a range", []string{"attribution", "export", "create", "--period", "last7", "--time-to", "5"}, "--period and --time-from/--time-to are exclusive", "--period last30"},
		{"create with a date for run-at", []string{"attribution", "export", "create", "--run-at", "tomorrow"}, `invalid --run-at "tomorrow": unix time in seconds`, "date -d"},
		{"create with an http webhook", []string{"attribution", "export", "create", "--webhook-url", "http://hooks.example.com/"}, "exports are only sent over https", "public address"},
		{"create with a secret and no webhook", []string{"attribution", "export", "create", "--webhook-secret", "abcdefghijklmnopq"}, "--webhook-secret needs --webhook-url", ""},
		{"list by an unknown status", []string{"attribution", "export", "list", "--status", "done"}, `invalid --status "done"; valid: pending, running, completed, failed`, ""},
		{"list with a huge limit", []string{"attribution", "export", "list", "--limit", "1000"}, "invalid --limit 1000; 1 to 200", ""},
		{"get a non-id", []string{"attribution", "export", "get", "latest"}, `export id must be a positive whole number, got "latest"`, "p202 attribution export list"},
		{"download a non-id", []string{"attribution", "export", "download", "0"}, `export id must be a positive whole number, got "0"`, "p202 attribution export list"},
		{"retry a non-id", []string{"attribution", "export", "retry", "1.5"}, "export id must be a positive whole number", ""},
		{"delete a non-id", []string{"attribution", "export", "delete", "x", "--force"}, `export id must be a positive whole number, got "x"`, ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error for %v", tc.args)
			}
			if !strings.Contains(err.Error(), tc.message) {
				t.Errorf("message = %q, want it to contain %q", err.Error(), tc.message)
			}
			assertValidationError(t, err)
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d", code, ExitValidation)
			}
			if tc.hint != "" {
				if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
					t.Errorf("hint = %q, want it to contain %q", hint, tc.hint)
				}
			}
		})
	}
}

func TestAttributionExportCreateSendsNumbers(t *testing.T) {
	var body map[string]interface{}
	var path string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		if err := json.Unmarshal(raw, &body); err != nil {
			t.Errorf("body is not JSON: %s", raw)
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"export_id":5,"status":"pending","webhook_secret":"s"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("attribution", "export", "create", "--group-by", "keyword", "--model", "3", "--compare-model", "4",
		"--time-from", "1780000000", "--time-to", "1790000000", "--run-at", "1790003600", "--webhook-url", "https://hooks.example.com/p202",
		"--webhook-secret", "0123456789abcdef0123"); err != nil {
		t.Fatalf("create: %v", err)
	}
	if !strings.HasSuffix(path, "/attribution/exports") {
		t.Errorf("path = %q", path)
	}
	for field, want := range map[string]float64{"model_id": 3, "compare_model_id": 4, "time_from": 1780000000, "time_to": 1790000000, "run_at": 1790003600} {
		if v, ok := body[field].(float64); !ok || v != want {
			t.Errorf("%s = %#v, want the number %v (the API refuses a string)", field, body[field], want)
		}
	}
	if body["group_by"] != "keyword" || body["webhook_url"] != "https://hooks.example.com/p202" || body["webhook_secret"] != "0123456789abcdef0123" {
		t.Errorf("body = %#v", body)
	}
	if _, sent := body["period"]; sent {
		t.Errorf("period sent with an explicit range")
	}
}

func TestAttributionExportRefusedWebhookSaysWhatToDo(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(422)
		w.Write([]byte(`{"error":true,"message":"webhook_url refused","status":422,"field_errors":{"webhook_url":"127.0.0.1, which is a loopback address. Webhooks are only sent to public addresses."}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "export", "create", "--webhook-url", "https://127.0.0.1/h")
	if err == nil {
		t.Fatal("expected the 422 to surface")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "P202_WEBHOOK_ALLOW_NETWORKS") {
		t.Errorf("hint = %q", hint)
	}
}

func TestAttributionExportDownloadWritesTheFile(t *testing.T) {
	csv := "key,name\n1,\"Campaign, one\"\n"
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/attribution/exports/7/download"):
			w.Header().Set("Content-Type", "text/csv; charset=utf-8")
			w.Write([]byte(csv))
		case strings.HasSuffix(r.URL.Path, "/attribution/exports/8/download"):
			w.WriteHeader(409)
			w.Write([]byte(`{"error":true,"message":"Export 8 has no file yet (status pending); it is written when the export runs.","status":409}`))
		default:
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out := filepath.Join(tmp, "export.csv")
	if _, _, err := executeCommand("attribution", "export", "download", "7", "--output", out); err != nil {
		t.Fatalf("download: %v", err)
	}
	got, err := os.ReadFile(out)
	if err != nil || string(got) != csv {
		t.Fatalf("file = %q (%v), want the bytes served", got, err)
	}

	missing := filepath.Join(tmp, "missing.csv")
	_, _, err = executeCommand("attribution", "export", "download", "8", "--output", missing)
	if err == nil {
		t.Fatal("expected the 409 to surface")
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 attribution export get 8") {
		t.Errorf("hint = %q", hint)
	}
	if _, statErr := os.Stat(missing); !os.IsNotExist(statErr) {
		t.Errorf("a failed download left a file behind")
	}
}
