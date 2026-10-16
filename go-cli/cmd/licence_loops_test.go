package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// A licence that lapses mid-run refuses every later request too: the bulk
// tracker URLs stop at the first refusal and end with it (exit 6, the trial
// hint), with nothing on stdout.
func TestBulkURLsStopsOnTheLicenceRefusal(t *testing.T) {
	var mu sync.Mutex
	urlHits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/versions"):
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			_, _ = w.Write([]byte(`{"data":{"features":{}}}`))
		case r.URL.Path == "/api/v3/trackers":
			rows := []map[string]interface{}{}
			for i := 1; i <= 6; i++ {
				rows = append(rows, map[string]interface{}{"tracker_id": i, "aff_campaign_id": 1})
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": rows})
		case strings.HasSuffix(r.URL.Path, "/url"):
			mu.Lock()
			urlHits++
			mu.Unlock()
			w.WriteHeader(402)
			_, _ = w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence on this install's ClickServer API key. Start a Pro trial or subscription at https://my.tracking202.com/api/customers/subscriptions/support"}`))
		default:
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
	stdout, _, err := executeCommand("tracker", "bulk-urls", "--concurrency", "1")
	assertLicenceRefusal(t, err, "tracker bulk-urls")
	if urlHits != 1 {
		t.Errorf("want one refused URL request, then a stop; got %d", urlHits)
	}
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("stdout must stay empty on a refusal, got %q", stdout)
	}
}

// The URL rewrite stops at the first refusal and ends with it, after
// reporting the rows; nothing after the refused campaign is sent.
func TestReplaceURLStopsOnTheLicenceRefusal(t *testing.T) {
	f := newCampaignFake()
	f.refusePut = true
	setupCampaignFake(t, f)
	_, _, err := executeCommand("campaign", "replace-url", "--match", "http", "--with", "https", "--force")
	assertLicenceRefusal(t, err, "campaign replace-url")
	if len(f.puts) != 1 {
		t.Errorf("want one refused PUT, then a stop; got %d: %+v", len(f.puts), f.puts)
	}
}
