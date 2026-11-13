package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

// `p202 import ppc-networks` carries a source's ad platform, so the ChatGPT
// Ads source is that source on the other install too, and leaves out an
// empty one, which a server before 1.9.80 would refuse as a field it does
// not know (an empty platform is the field's default there).
func TestImportCarriesTheTrafficSourcePlatform(t *testing.T) {
	var mu sync.Mutex
	var posted []map[string]interface{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPost && r.URL.Path == "/api/v3/ppc-networks" {
			raw, _ := io.ReadAll(r.Body)
			var body map[string]interface{}
			_ = json.Unmarshal(raw, &body)
			mu.Lock()
			posted = append(posted, body)
			mu.Unlock()
			w.WriteHeader(201)
			_, _ = w.Write([]byte(`{"data":{"ppc_network_id":10}}`))
			return
		}
		w.WriteHeader(404)
		_, _ = w.Write([]byte(`{"message":"not found"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	inFile := filepath.Join(tmp, "ppc-networks.json")
	records := `[{"ppc_network_id":3,"user_id":1,"ppc_network_name":"ChatGPT Ads","ppc_network_platform":"chatgpt_ads","ppc_network_deleted":0},` +
		`{"ppc_network_id":4,"user_id":1,"ppc_network_name":"Newsletter","ppc_network_platform":"","ppc_network_deleted":0}]`
	if err := os.WriteFile(inFile, []byte(records), 0600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := executeCommand("import", "ppc-networks", inFile); err != nil {
		t.Fatalf("import ppc-networks: %v", err)
	}
	byName := map[string]map[string]interface{}{}
	for _, body := range posted {
		byName[body["ppc_network_name"].(string)] = body
	}
	if len(byName) != 2 {
		t.Fatalf("posted %v, want both sources", posted)
	}
	if got := byName["ChatGPT Ads"]["ppc_network_platform"]; got != "chatgpt_ads" {
		t.Errorf("ChatGPT Ads source posted with platform %#v, want chatgpt_ads", got)
	}
	if _, sent := byName["Newsletter"]["ppc_network_platform"]; sent {
		t.Errorf("a source on no platform was posted with the field: %v", byName["Newsletter"])
	}
	for name, body := range byName {
		for _, key := range []string{"ppc_network_id", "user_id", "ppc_network_deleted"} {
			if _, sent := body[key]; sent {
				t.Errorf("%s posted with read-only %s", name, key)
			}
		}
	}
}

// sync builds its bodies with the same strip as import.
func TestSyncBodiesKeepTheTrafficSourcePlatform(t *testing.T) {
	kept := stripImmutableFields("ppc-networks", map[string]interface{}{"ppc_network_id": 3, "ppc_network_name": "ChatGPT Ads", "ppc_network_platform": "chatgpt_ads"})
	if kept["ppc_network_platform"] != "chatgpt_ads" {
		t.Errorf("platform dropped: %v", kept)
	}
	plain := stripImmutableFields("ppc-networks", map[string]interface{}{"ppc_network_id": 4, "ppc_network_name": "Newsletter", "ppc_network_platform": ""})
	if _, sent := plain["ppc_network_platform"]; sent {
		t.Errorf("an empty platform is sent: %v", plain)
	}
	other := stripImmutableFields("campaigns", map[string]interface{}{"aff_campaign_name": "A", "ppc_network_platform": ""})
	if _, sent := other["ppc_network_platform"]; !sent {
		t.Errorf("only a traffic source's platform is left out when empty: %v", other)
	}
}

// add-chatgpt-ads posts an empty body to the button's route and prints the answer.
func TestAddChatGptAdsPostsTheButtonsRoute(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		return 201, `{"data":{"ppc_network_id":12,"ppc_account_id":30,"source_created":true,"source_adopted":false,"account_created":true,"variables_added":["campaign_id","ad_id"]}}`
	})
	stdout, _, err := executeCommand("ppc-network", "add-chatgpt-ads", "--json")
	if err != nil {
		t.Fatalf("add-chatgpt-ads: %v", err)
	}
	last := (*seen)[len(*seen)-1]
	if last.Method != http.MethodPost || last.Path != "/api/v3/ppc-networks/chatgpt-ads" {
		t.Errorf("request = %s %s, want POST /api/v3/ppc-networks/chatgpt-ads", last.Method, last.Path)
	}
	if len(last.Body) != 0 {
		t.Errorf("body = %v, want {}", last.Body)
	}
	if !strings.Contains(stdout, `"source_created":true`) && !strings.Contains(stdout, `"source_created": true`) {
		t.Errorf("the answer is printed: %s", stdout)
	}
	if _, _, err := executeCommand("ppc-network", "add-chatgpt-ads", "extra"); err == nil || exitCodeForError(err) != ExitValidation {
		t.Errorf("an argument is refused as a validation error, got %v", err)
	}
}
