package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"
)

// featureServer advertises the capabilities the safety flags require and
// records every request path+query it receives.
type featureServer struct {
	*httptest.Server
	mu   sync.Mutex
	reqs []string
}

func newFeatureServer(t *testing.T) *featureServer {
	t.Helper()
	fs := &featureServer{}
	fs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			_, _ = w.Write([]byte(`{"data":{"features":{"delete_dry_run":true,"staged_writes":true}}}`))
			return
		}
		fs.mu.Lock()
		q := r.URL.RawQuery
		entry := r.Method + " " + r.URL.Path
		if q != "" {
			entry += "?" + q
		}
		fs.reqs = append(fs.reqs, entry)
		fs.mu.Unlock()
		_, _ = w.Write([]byte(`{"data":{"change_id":"chg_aabbccddeeff001122334455","would_delete":1}}`))
	}))
	t.Cleanup(fs.Close)
	return fs
}

func (fs *featureServer) seen() []string {
	fs.mu.Lock()
	defer fs.mu.Unlock()
	return append([]string(nil), fs.reqs...)
}

// The five hand-rolled deletes were collapsed onto one runner while master was
// independently adding --dry-run and --staged to each copy. Both had to survive
// the merge: these are the consolidated call sites master's own tests do not
// reach, including the nested one whose endpoint is built from a parent id.
func TestConsolidatedDeletesCarryDryRunAndStaged(t *testing.T) {
	cases := []struct {
		name     string
		args     []string
		wantPath string
	}{
		{"rotator dry-run", []string{"rotator", "delete", "7", "--dry-run"}, "DELETE /api/v3/rotators/7?dry_run=1"},
		{"conversion dry-run", []string{"conversion", "delete", "7", "--dry-run"}, "DELETE /api/v3/conversions/7?dry_run=1"},
		{"rotator rule dry-run", []string{"rotator", "rule-delete", "3", "9", "--dry-run"}, "DELETE /api/v3/rotators/3/rules/9?dry_run=1"},
		{"rotator bulk dry-run", []string{"rotator", "delete", "--ids", "7,8", "--dry-run"}, "DELETE /api/v3/rotators/8?dry_run=1"},
		{"rotator staged", []string{"rotator", "delete", "7", "--staged"}, "DELETE /api/v3/rotators/7?staged=1"},
		{"conversion staged", []string{"conversion", "delete", "7", "--staged"}, "DELETE /api/v3/conversions/7?staged=1"},
		{"rotator rule staged", []string{"rotator", "rule-delete", "3", "9", "--staged"}, "DELETE /api/v3/rotators/3/rules/9?staged=1"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := newFeatureServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")
			t.Cleanup(func() { api.SetStagedMode(false) })

			if _, _, err := executeCommand(tc.args...); err != nil {
				t.Fatalf("unexpected error: %v", err)
			}

			var found bool
			for _, req := range srv.seen() {
				if req == tc.wantPath {
					found = true
				}
			}
			if !found {
				t.Fatalf("expected a request %q, got %v", tc.wantPath, srv.seen())
			}
		})
	}
}

// A preview is still a DELETE request. Id validation therefore has to run
// before the dry-run and staged branches, not after them, or an invalid id
// reaches the server on exactly the paths added to make deletes safer.
func TestInvalidIDIsRejectedBeforePreviewOrStaging(t *testing.T) {
	for _, flag := range []string{"--dry-run", "--staged"} {
		t.Run(flag, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := newFeatureServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")
			t.Cleanup(func() { api.SetStagedMode(false) })

			_, _, err := executeCommand("rotator", "delete", "", flag)
			if err == nil {
				t.Fatalf("expected a validation error, got nil (requests: %v)", srv.seen())
			}
			if !strings.Contains(err.Error(), "ID") {
				t.Fatalf("error should name the invalid ID, got %q", err)
			}
			for _, req := range srv.seen() {
				if strings.HasPrefix(req, "DELETE") {
					t.Fatalf("a DELETE reached the server despite the invalid id: %s", req)
				}
			}
		})
	}
}
