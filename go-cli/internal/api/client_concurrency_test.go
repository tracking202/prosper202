package api

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// Client is shared across goroutines by callers (cmd/crud.go fans out bulk
// tracker-URL fetches over a worker pool with one client, and cmd/shell.go
// keeps a long-lived client). ensureCapabilities() lazily MUTATES baseURL,
// capabilities, capabilitiesLoaded and capabilitiesErr, while do() reads
// baseURL on every request — so a capability lookup racing a request is a
// data race on the same fields.
//
// Run with -race; this fails loudly if the guard around that state regresses.
func TestConcurrentRequestsAndCapabilityLookupsAreRaceFree(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"bulk":{"enabled":true}}}`))
		default:
			_, _ = w.Write([]byte(`{"data":{"ok":true}}`))
		}
	}))
	defer srv.Close()

	c := newClient(srv.URL, "test-api-key-1234")

	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := c.Get("trackers/1/url", nil); err != nil {
				t.Errorf("Get: %v", err)
			}
		}()
		wg.Add(1)
		go func() {
			defer wg.Done()
			c.SupportsCapability("bulk", "enabled")
		}()
	}
	wg.Wait()
}

// The version segment from /api/versions is interpolated into every subsequent
// request path, so a hostile or buggy server must not be able to steer it.
func TestNegotiateVersionRejectsNonNumericVersions(t *testing.T) {
	cases := []struct {
		name      string
		preferred string
		wantPath  string // expected path prefix used for the capabilities call
	}{
		{"numeric is accepted", "v4", "/api/v4/"},
		{"traversal is rejected", "3/../../admin", "/api/v3/"},
		{"query injection is rejected", "3?evil=1", "/api/v3/"},
		{"garbage is rejected", "not-a-version", "/api/v3/"},
		{"empty is rejected", "", "/api/v3/"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			var capabilitiesPath string
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				if r.URL.Path == "/api/versions" {
					_, _ = w.Write([]byte(`{"data":{"preferred":"` + tc.preferred + `"}}`))
					return
				}
				if strings.HasSuffix(r.URL.Path, "/capabilities") {
					capabilitiesPath = r.URL.Path
				}
				_, _ = w.Write([]byte(`{"data":{}}`))
			}))
			defer srv.Close()

			c := newClient(srv.URL, "test-api-key-1234")
			c.ensureCapabilities()

			if !strings.HasPrefix(capabilitiesPath, tc.wantPath) {
				t.Fatalf("capabilities requested %q, want prefix %q", capabilitiesPath, tc.wantPath)
			}
		})
	}
}
