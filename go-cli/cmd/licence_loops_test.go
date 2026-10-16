package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
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
	f.refuseFrom = 1
	setupCampaignFake(t, f)
	_, _, err := executeCommand("campaign", "replace-url", "--match", "http", "--with", "https", "--force")
	assertLicenceRefusal(t, err, "campaign replace-url")
	if len(f.puts) != 1 {
		t.Errorf("want one refused PUT, then a stop; got %d: %+v", len(f.puts), f.puts)
	}
}

// A proposal is not a change: the refusal counts what was staged apart from
// what was updated.
func TestReplaceURLRefusalCountsStagedApart(t *testing.T) {
	f := newCampaignFake()
	f.stageAll = true
	f.refuseFrom = 2
	setupCampaignFake(t, f)
	_, _, err := executeCommand("campaign", "replace-url", "--match", "http", "--with", "https", "--force")
	assertLicenceRefusal(t, err, "campaign replace-url")
	if len(f.puts) != 2 {
		t.Fatalf("want one staged PUT, one refused, then a stop; got %d", len(f.puts))
	}
	if !strings.Contains(err.Error(), "updated and 1 staged before it") || !strings.Contains(err.Error(), "(0 of ") {
		t.Errorf("the refusal should count 0 updated and 1 staged: %v", err)
	}
}

// A refusal after another failure is not every failure's cause: the run is
// a partial failure that keeps what it fetched, not the licence refusal
// (licenceRefusal's rule), so its exit code and hint do not promise that a
// licence would fix it.
func TestBulkURLsWithAnotherFailureIsNotTheLicenceRefusal(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/versions"):
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			_, _ = w.Write([]byte(`{"data":{"features":{}}}`))
		case r.URL.Path == "/api/v3/trackers":
			rows := []map[string]interface{}{}
			for i := 1; i <= 4; i++ {
				rows = append(rows, map[string]interface{}{"tracker_id": i})
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": rows})
		case r.URL.Path == "/api/v3/trackers/1/url":
			_, _ = w.Write([]byte(`{"data":{"tracker_url":"https://t.example/1"}}`))
		case r.URL.Path == "/api/v3/trackers/2/url":
			w.WriteHeader(500)
			_, _ = w.Write([]byte(`{"message":"server error"}`))
		default:
			w.WriteHeader(402)
			_, _ = w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence on this install's ClickServer API key. Start a Pro trial or subscription at https://my.tracking202.com/api/customers/subscriptions/support"}`))
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
	stdout, _, err := executeCommand("tracker", "bulk-urls", "--concurrency", "1")
	if err == nil {
		t.Fatal("want a partial failure")
	}
	if got := exitCodeForError(err); got == ExitLicence {
		t.Errorf("exit code %d is the licence refusal's; another failure came first: %v", got, err)
	}
	if !strings.Contains(stdout, "https://t.example/1") {
		t.Errorf("the fetched row was dropped: %q", stdout)
	}
	if !strings.Contains(err.Error(), "licence") {
		t.Errorf("the refusal is not named: %v", err)
	}
}

func TestReplaceURLWithAnotherFailureIsNotTheLicenceRefusal(t *testing.T) {
	f := newCampaignFake()
	f.refuseFrom = 2
	f.failPut = map[string]bool{}
	for _, row := range f.rows {
		f.failPut[strconv.Itoa(row["aff_campaign_id"].(int))] = true
	}
	setupCampaignFake(t, f)
	_, _, err := executeCommand("campaign", "replace-url", "--match", "http", "--with", "https", "--force")
	if err == nil {
		t.Fatal("want a partial failure")
	}
	if got := exitCodeForError(err); got == ExitLicence {
		t.Errorf("exit code %d is the licence refusal's; a 500 came first: %v", got, err)
	}
	if len(f.puts) != 2 {
		t.Errorf("want a failed PUT, a refused one, then a stop; got %d", len(f.puts))
	}
	if !strings.Contains(err.Error(), "before a licence refusal") {
		t.Errorf("the refusal is not named: %v", err)
	}
}
