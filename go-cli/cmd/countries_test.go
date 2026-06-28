package cmd

import "testing"

func TestCountryCriteriaValue(t *testing.T) {
	if v := countryCriteriaValue("US"); v != "United States(US)" {
		t.Errorf("US -> %q, want United States(US)", v)
	}
	if v := countryCriteriaValue("uk"); v != "United Kingdom(GB)" { // UK normalizes to GB
		t.Errorf("uk -> %q, want United Kingdom(GB)", v)
	}
	if v := countryCriteriaValue("ZZ"); v != "" {
		t.Errorf("unknown code should return empty, got %q", v)
	}
}
