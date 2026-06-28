package cmd

import "testing"

func TestCountryCodeFromValue(t *testing.T) {
	cases := map[string]string{
		"United States(US)": "US",
		"Netherlands(NL)":   "NL",
		"US":                "US",
	}
	for in, want := range cases {
		if got := countryCodeFromValue(in); got != want {
			t.Errorf("countryCodeFromValue(%q) = %q, want %q", in, got, want)
		}
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
