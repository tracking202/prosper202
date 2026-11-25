package cmd

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
)

// Each country value against one visitor, as the PHP tree's CriterionMatcher
// answers it (tests/Rotator/CountryCriterionValuesTest.php reads the same
// cases): `rotator test` read a value through the last brackets in it and any
// statement but is_not as is, so it routed visitors the redirects did not.
func TestRotatorTestReadsCountryValuesAsTheRedirectsDo(t *testing.T) {
	raw, err := os.ReadFile(repoPath("tests", "fixtures", "rotator-criterion-values.json"))
	if err != nil {
		t.Fatalf("reading the shared vectors: %v", err)
	}
	var vectors struct {
		Visitor struct {
			CountryCode string `json:"country_code"`
		} `json:"countries_visitor"`
		Countries []struct {
			Stored    string `json:"stored"`
			Statement string `json:"statement"`
			Matches   bool   `json:"matches"`
			Why       string `json:"why"`
		} `json:"countries"`
	}
	if err := json.Unmarshal(raw, &vectors); err != nil || len(vectors.Countries) < 20 || vectors.Visitor.CountryCode == "" {
		t.Fatalf("the shared country vectors: %v (%d cases)", err, len(vectors.Countries))
	}
	for _, c := range vectors.Countries {
		data := map[string]interface{}{
			"default_campaign": 90007.0,
			"rules": []interface{}{
				map[string]interface{}{
					"rule_name": "country", "status": 1.0,
					"criteria":  []interface{}{map[string]interface{}{"type": "country", "statement": c.Statement, "value": c.Stored}},
					"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0}},
				},
			},
		}
		want := "campaign 90007"
		if c.Matches {
			want = "campaign 90008"
		}
		if _, dest, explain := rotatorDestination(data, vectors.Visitor.CountryCode); dest != want {
			t.Errorf("country %s %q (%s): a visitor from %s goes to %q, want %q; %v",
				c.Statement, c.Stored, c.Why, vectors.Visitor.CountryCode, dest, want, explain)
		}
	}
}

func TestRotatorTestGeoIsAVisitorsCountryCode(t *testing.T) {
	for in, want := range map[string]string{"us": "US", " DE ": "DE", "UK": "GB", "gb": "GB"} {
		if got, err := rotatorTestGeo(in); err != nil || got != want {
			t.Errorf("rotatorTestGeo(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	for _, in := range []string{"USA", "United States", "United States(US)", "U", "1A", ""} {
		_, err := rotatorTestGeo(in)
		if err == nil {
			t.Errorf("rotatorTestGeo(%q) took it; want a refusal", in)
			continue
		}
		if code := exitCodeForError(err); code != 1 {
			t.Errorf("rotatorTestGeo(%q): exit %d, want 1 (validation)", in, code)
		}
		if hint := hintFor(err); !strings.Contains(hint, "p202 rotator criteria-values") {
			t.Errorf("rotatorTestGeo(%q): hint %q names no command that lists codes", in, hint)
		}
	}
}

func TestRotatorTestExplainMarksAValueThatNamesNoCode(t *testing.T) {
	got := describeCountryValues(criterionValues("Germany(DE), de, Germany"))
	want := "Germany(DE) [DE],de [DE],Germany [names no country code, never matches]"
	if got != want {
		t.Errorf("describeCountryValues = %q, want %q", got, want)
	}
}

func TestRotatorDestinationRoutesByGeo(t *testing.T) {
	data := map[string]interface{}{
		"default_campaign": 90007.0,
		"rules": []interface{}{
			map[string]interface{}{
				"rule_name": "US -> SJ", "status": 1.0,
				"criteria":  []interface{}{map[string]interface{}{"type": "country", "statement": "is", "value": "United States(US)"}},
				"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0, "weight": "100"}},
			},
		},
	}
	if rule, dest, _ := rotatorDestination(data, "US"); dest != "campaign 90008" {
		t.Errorf("US should route to campaign 90008, got rule=%q dest=%q", rule, dest)
	}
	if _, dest, _ := rotatorDestination(data, "BR"); dest != "campaign 90007" {
		t.Errorf("BR should fall to default campaign 90007, got %q", dest)
	}
}

func TestRotatorDestinationInactiveRuleSkipped(t *testing.T) {
	data := map[string]interface{}{
		"default_campaign": 90007.0,
		"rules": []interface{}{
			map[string]interface{}{
				"rule_name": "US (paused)", "status": 0.0,
				"criteria":  []interface{}{map[string]interface{}{"type": "country", "statement": "is", "value": "United States(US)"}},
				"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0}},
			},
		},
	}
	if _, dest, _ := rotatorDestination(data, "US"); dest != "campaign 90007" {
		t.Errorf("paused US rule should be skipped -> default, got %q", dest)
	}
}

func TestRotatorIssues(t *testing.T) {
	// no rules and no default -> flagged
	bad := map[string]interface{}{"rules": []interface{}{}}
	if len(rotatorIssues(bad)) == 0 {
		t.Error("ruleless rotator with no default should be flagged")
	}
	// has default campaign -> OK
	ok := map[string]interface{}{"default_campaign": 90007.0, "rules": []interface{}{}}
	if len(rotatorIssues(ok)) != 0 {
		t.Errorf("rotator with a default should be OK, got %v", rotatorIssues(ok))
	}
}

func TestRotatorDestinationEmptyCriteriaMatches(t *testing.T) {
	// rtr.php treats a rule with no criteria as a match.
	data := map[string]interface{}{
		"default_campaign": 90007.0,
		"rules": []interface{}{
			map[string]interface{}{
				"rule_name": "catch-all", "status": 1.0,
				"criteria":  []interface{}{},
				"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0}},
			},
		},
	}
	if _, dest, _ := rotatorDestination(data, "BR"); dest != "campaign 90008" {
		t.Errorf("empty-criteria rule should match any geo, got %q", dest)
	}
}

func TestRotatorDestinationMultiCountryValue(t *testing.T) {
	data := map[string]interface{}{
		"default_campaign": 90007.0,
		"rules": []interface{}{
			map[string]interface{}{
				"rule_name": "tier1", "status": 1.0,
				"criteria":  []interface{}{map[string]interface{}{"type": "country", "statement": "is", "value": "United States(US),Canada(CA)"}},
				"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0}},
			},
		},
	}
	if _, dest, _ := rotatorDestination(data, "CA"); dest != "campaign 90008" {
		t.Errorf("CA should match a US,CA rule, got %q", dest)
	}
	if _, dest, _ := rotatorDestination(data, "BR"); dest != "campaign 90007" {
		t.Errorf("BR should fall to default, got %q", dest)
	}
}
