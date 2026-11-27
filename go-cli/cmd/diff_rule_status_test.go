package cmd

import (
	"reflect"
	"testing"
)

func TestARuleWithNoStatusComparesAsThePausedRuleASyncMakesOfIt(t *testing.T) {
	rule := func(status interface{}) interface{} {
		return []interface{}{map[string]interface{}{"rule_name": "legacy", "splittest": float64(0), "status": status}}
	}
	paused := normalizeRulesForComparison(rule(float64(0)), entityLookups{})
	for name, status := range map[string]interface{}{"null": nil, "the digit 0": "0", "a value the redirects do not read": float64(2)} {
		if got := normalizeRulesForComparison(rule(status), entityLookups{}); !reflect.DeepEqual(got, paused) {
			t.Errorf("status %s: got %v, want the paused rule %v", name, got, paused)
		}
	}
	for name, status := range map[string]interface{}{"1": float64(1), "the digit 1": "1"} {
		if got := normalizeRulesForComparison(rule(status), entityLookups{}); reflect.DeepEqual(got, paused) {
			t.Errorf("status %s compares as paused: %v", name, got)
		}
	}
}
