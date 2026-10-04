package cmd

import (
	"math"
	"strings"
	"testing"
)

func TestToFloatNeverReturnsNonFinite(t *testing.T) {
	cases := map[string]struct {
		in   interface{}
		want float64
	}{
		"number":         {1.5, 1.5},
		"int":            {3, 3},
		"numeric string": {"45.50000", 45.5},
		"negative":       {"-1.4", -1.4},
		"missing":        {nil, 0},
		"garbage":        {"abc", 0},
		"NaN string":     {"NaN", 0},
		"Inf string":     {"Inf", 0},
		"-Inf string":    {"-Inf", 0},
		"overflow":       {"1e999", 0},
		"NaN float":      {math.NaN(), 0},
		"Inf float":      {math.Inf(1), 0},
		"bool":           {true, 0},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			got := toFloat(tc.in)
			if math.IsNaN(got) || math.IsInf(got, 0) || got != tc.want {
				t.Errorf("toFloat(%v) = %v, want %v", tc.in, got, tc.want)
			}
		})
	}
}

func TestParseFiniteFloatReportsWhatIsWrong(t *testing.T) {
	if f, err := parseFiniteFloat("12.25"); err != nil || f != 12.25 {
		t.Fatalf("parseFiniteFloat(12.25) = %v, %v", f, err)
	}
	for in, want := range map[interface{}]string{"NaN": "not a finite number", "abc": "not a number", nil: "missing", "+Inf": "not a finite number"} {
		if _, err := parseFiniteFloat(in); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("parseFiniteFloat(%v) err = %v, want %q", in, err, want)
		}
	}
}

func TestCampaignListWithStatsRefusesANonFiniteStat(t *testing.T) {
	f := newStatsFake()
	// strconv.ParseFloat accepts "NaN"; before the fix it reached json.Marshal and the list printed nothing with exit 0.
	f.stats = []map[string]interface{}{statRow(279, "NaN", "4", "45.5000", "12.0000", "33.5000")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--json")
	if err == nil {
		t.Fatalf("want an error for a NaN stat, got output:\n%s", stdout)
	}
	if !strings.Contains(err.Error(), "279") || !strings.Contains(err.Error(), "total_clicks") {
		t.Errorf("error should name the campaign and the field, got %v", err)
	}
	if !strings.Contains(hintFor(err), "report breakdown --breakdown campaign") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("stdout must stay empty on failure, got %q", stdout)
	}
}

func TestCampaignListWithStatsStillZeroFillsCampaignsWithoutAStatsRow(t *testing.T) {
	f := newStatsFake()
	f.stats = []map[string]interface{}{statRow(279, "120", "4", "45.5000", "12.0000", "33.5000")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--json")
	if err != nil {
		t.Fatalf("campaign list --with-stats: %v", err)
	}
	rows := decodeListRows(t, stdout)
	assertStats(t, rows[281], 0, 0, 0, 0, 0)
}
