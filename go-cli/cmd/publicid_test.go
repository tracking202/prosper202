package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// A tracker fetched by its public id should 404 on the internal route, then
// resolve via the tracker_id_public filter and retry with the internal id.
func TestTrackerGetResolvesPublicId(t *testing.T) {
	var hitInternal bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/v3/trackers/28996586":
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		case r.URL.Path == "/api/v3/trackers" && r.URL.Query().Get("filter[tracker_id_public]") == "28996586":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"tracker_id":90029,"tracker_id_public":28996586}]}`))
		case r.URL.Path == "/api/v3/trackers/90029":
			hitInternal = true
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"tracker_id":90029,"tracker_id_public":28996586}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"unexpected"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("tracker", "get", "28996586", "--json")
	if err != nil {
		t.Fatalf("tracker get by public id failed: %v", err)
	}
	if !hitInternal {
		t.Error("expected a retry against the internal id route after 404")
	}
	if !strings.Contains(stdout, "90029") {
		t.Errorf("output should contain the resolved tracker, got:\n%s", stdout)
	}
}

func TestNormalizeID(t *testing.T) {
	if got := normalizeID(float64(90029)); got != int64(90029) {
		t.Errorf("normalizeID(90029.0) = %v, want int64 90029", got)
	}
}
