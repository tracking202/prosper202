package cmd

import (
	"encoding/json"
	"os"
	"testing"
)

// The CLI's copy of the server's rule answers every shared case as the
// server does (tests/Http/HttpUrlPrefixTest.php reads the same file).
func TestCompleteHTTPURLMatchesTheServer(t *testing.T) {
	raw, err := os.ReadFile("testdata/http_url_prefix_cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases [][2]string
	if err := json.Unmarshal(raw, &cases); err != nil {
		t.Fatal(err)
	}
	if len(cases) < 35 {
		t.Fatalf("only %d cases read", len(cases))
	}
	for _, c := range cases {
		if got := completeHTTPURL(c[0]); got != c[1] {
			t.Errorf("completeHTTPURL(%q) = %q, the server stores %q", c[0], got, c[1])
		}
		if got := completeHTTPURL(c[1]); got != c[1] {
			t.Errorf("completing %q twice gave %q", c[1], got)
		}
	}
}

// A source page stored without its scheme and the target's completed page
// are one page with nothing to change, by key and by comparison.
func TestALandingPageStoredWithoutItsSchemeMatchesTheCompletedOne(t *testing.T) {
	source := []map[string]interface{}{{"landing_page_id": "7", "landing_page_url": "lp.example.com/x", "leave_behind_page_url": "offers.example.com/after", "landing_page_nickname": "Spring sale", "aff_campaign_id": "0"}}
	target := []map[string]interface{}{{"landing_page_id": "31", "landing_page_url": "https://lp.example.com/x", "leave_behind_page_url": "https://offers.example.com/after", "landing_page_nickname": "Spring sale", "aff_campaign_id": "0"}}
	sourceLookups := buildEntityLookups(map[string][]map[string]interface{}{"landing-pages": source})
	targetLookups := buildEntityLookups(map[string][]map[string]interface{}{"landing-pages": target})

	sourceKey := naturalKeyForEntity("landing-pages", source[0], sourceLookups)
	if targetKey := naturalKeyForEntity("landing-pages", target[0], targetLookups); sourceKey != targetKey || sourceKey != "https://lp.example.com/x" {
		t.Fatalf("keys %q and %q, want both https://lp.example.com/x", sourceKey, targetKey)
	}
	if sourceLookups.landingPages["7"] != targetLookups.landingPages["31"] {
		t.Errorf("a tracker naming the page would not find it on the target: %q vs %q", sourceLookups.landingPages["7"], targetLookups.landingPages["31"])
	}
	a := normalizeComparableRecord("landing-pages", source[0], sourceLookups)
	b := normalizeComparableRecord("landing-pages", target[0], targetLookups)
	if !comparableEqual(a, b) {
		t.Errorf("the pages read as changed: %v", changedFields(a, b))
	}
	plan := diffEntity("landing-pages", source, target, sourceLookups, targetLookups)
	for _, key := range []string{"only_in_source_count", "only_in_target_count", "changed_count"} {
		if n, ok := plan[key].(int); !ok || n != 0 {
			t.Errorf("the plan's %s is %v, want 0", key, plan[key])
		}
	}

	// A null leave-behind stays null: nothing is invented for it.
	null := normalizeComparableRecord("landing-pages", map[string]interface{}{"landing_page_url": "https://lp.example.com/x", "leave_behind_page_url": nil}, sourceLookups)
	if null["leave_behind_page_url"] != nil {
		t.Errorf("a null leave-behind URL became %v", null["leave_behind_page_url"])
	}
}
