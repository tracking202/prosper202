package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// reportPayoutFake is a server that says features.report_payout: GET /reports/payout answers payout(w), and
// GET /campaigns/7 refuses as it refuses a Campaign optimizer (no access_to_setup_section).
type reportPayoutFake struct {
	mu       sync.Mutex
	requests []string
	payout   func(w http.ResponseWriter)
}

func (f *reportPayoutFake) start(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.requests = append(f.requests, r.URL.Path+"?"+r.URL.RawQuery)
		f.mu.Unlock()
		switch {
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			_, _ = w.Write([]byte(`{"data":{"features":{"report_payout":true,"delete_dry_run":true}}}`))
		case strings.HasSuffix(r.URL.Path, "/versions"):
			_, _ = w.Write([]byte(`{"data":{"current":"v3","supported":["v3"]}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/payout"):
			f.payout(w)
		case strings.HasSuffix(r.URL.Path, "/campaigns/7"):
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(`{"error":true,"message":"This account's role does not have the 'access_to_setup_section' permission.","status":403}`))
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(`{"data":{"total_clicks":300,"total_click_throughs":300,"total_leads":6,"total_income":960,"total_cost":450,"total_net":510}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			_, _ = w.Write([]byte(payoutBreakdown))
		default:
			t.Errorf("unexpected request %s", r.URL)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
}

func (f *reportPayoutFake) asked(prefix string) []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []string
	for _, r := range f.requests {
		if strings.Contains(r, prefix) {
			out = append(out, r)
		}
	}
	return out
}

// reportPayoutCommands are every command that reads a campaign's payout, with its MCP tool and the tool's input.
var reportPayoutCommands = []struct {
	args  []string
	tool  string
	input string
}{
	{[]string{"report", "losers", "--aff-campaign-id", "7"}, "report_losers", `{"aff_campaign_id":"7"}`},
	{[]string{"report", "winners", "--aff-campaign-id", "7"}, "report_winners", `{"aff_campaign_id":"7"}`},
	{[]string{"report", "breakeven", "--aff-campaign-id", "7"}, "report_breakeven", `{"aff_campaign_id":"7"}`},
	{[]string{"campaign", "optimize", "7"}, "campaign_optimize", `{"campaign_id":"7"}`},
}

// TestPayoutIsReadFromTheReportsWhereTheServerServesIt (B6OR): the commands read the payout from GET /campaigns/{id},
// which is Setup's, so the Campaign optimizer -- campaign data, no Setup -- was refused by the commands its role is
// named for. A server that says features.report_payout is asked GET /reports/payout instead, and never the record.
func TestPayoutIsReadFromTheReportsWhereTheServerServesIt(t *testing.T) {
	for _, c := range reportPayoutCommands {
		t.Run(c.tool, func(t *testing.T) {
			fake := &reportPayoutFake{payout: func(w http.ResponseWriter) {
				_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":7,"aff_campaign_name":"Offer A","aff_campaign_payout":160,"aff_campaign_currency":"USD"}}`))
			}}
			fake.start(t)
			stdout, stderr, err := executeCommand(append(c.args, "--json")...)
			if err != nil {
				t.Fatalf("%v: %v\n%s", c.args, err, stderr)
			}
			if c.tool == "report_breakeven" && !strings.Contains(stdout, `"breakeven_cpc": 1.6,`) {
				t.Errorf("breakeven did not price overbid at 160 × 1%%: %s", stdout)
			}
			if got := fake.asked("/reports/payout?"); len(got) != 1 || !strings.HasSuffix(got[0], "?aff_campaign_id=7") {
				t.Errorf("payout reads %v, want one GET /reports/payout?aff_campaign_id=7", got)
			}
			if got := fake.asked("/campaigns/7"); len(got) != 0 {
				t.Errorf("the Setup record was asked for: %v", got)
			}
			if _, _, err := mcpExecute(t, "mcp", "call", c.tool, "--input", c.input); err != nil {
				t.Errorf("%s: %v", c.tool, err)
			}
		})
	}
}

// TestPayoutFromTheReportsFailsWhenItCannotBeRead: the reports' answer is refused as the record's was -- a masked
// payout (a role without campaign data) is the auth refusal, never a payout of 0; another account's or a deleted
// campaign is the 404 with its hint; an answer with no number is the server's.
func TestPayoutFromTheReportsFailsWhenItCannotBeRead(t *testing.T) {
	cases := []struct {
		name      string
		answer    func(w http.ResponseWriter)
		exit      int
		hint      string
		inMessage string
	}{
		{"a masked payout", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":7,"aff_campaign_name":"Offer A","aff_campaign_payout":null,"aff_campaign_currency":"USD"},"masked":true}`))
		}, ExitAuth, "access_to_campaign_data", "access_to_campaign_data"},
		{"no such campaign", func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"error":true,"message":"Campaign not found","status":404}`))
		}, ExitValidation, "p202 report breakdown --breakdown campaign", "reading campaign 7's payout"},
		{"a null payout, unmasked", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":7,"aff_campaign_payout":null}}`))
		}, ExitServer, "p202 system health", "GET reports/payout?aff_campaign_id=7 has no payout to read (it has no aff_campaign_payout)"},
		{"a payout that is not a number", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":7,"aff_campaign_payout":"n/a"}}`))
		}, ExitServer, "p202 system health", `aff_campaign_payout is not a number: "n/a"`},
	}
	for _, c := range reportPayoutCommands {
		for _, tc := range cases {
			t.Run(c.tool+"/"+tc.name, func(t *testing.T) {
				fake := &reportPayoutFake{payout: tc.answer}
				fake.start(t)
				stdout, stderr, err := executeCommand(append(c.args, "--json")...)
				if err == nil {
					t.Fatalf("a payout that could not be read priced the rows: %q %q", stdout, stderr)
				}
				if got := exitCodeForError(err); got != tc.exit {
					t.Errorf("exit %d, want %d (%v)", got, tc.exit, err)
				}
				if got := hintFor(err); !strings.Contains(got, tc.hint) {
					t.Errorf("hint %q, want one naming %q", got, tc.hint)
				}
				if !strings.Contains(err.Error(), tc.inMessage) {
					t.Errorf("error %q, want it to say %q", err, tc.inMessage)
				}
				if got := fake.asked("/reports/breakdown"); len(got) != 0 {
					t.Errorf("rows were read with no payout to price them: %v", got)
				}
				if got := fake.asked("/campaigns/7"); len(got) != 0 {
					t.Errorf("the Setup record was asked for: %v", got)
				}
			})
		}
	}
}

// TestAnOlderServersSetupRefusalSaysWhatRemains: a server without features.report_payout reads the payout only from
// the Setup record, so its 403 to a Campaign optimizer names the upgrade and --payout beside the role grant.
func TestAnOlderServersSetupRefusalSaysWhatRemains(t *testing.T) {
	fake := &payoutFake{breakdown: payoutBreakdown, campaign: func(w http.ResponseWriter) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`{"error":true,"message":"This account's role does not have the 'access_to_setup_section' permission.","status":403}`))
	}}
	fake.start(t)
	_, _, err := executeCommand("report", "losers", "--aff-campaign-id", "7", "--json")
	if err == nil || exitCodeForError(err) != ExitAuth {
		t.Fatalf("%v, want the auth refusal", err)
	}
	for _, want := range []string{"1.9.81", "--payout", "p202 user role assign"} {
		if !strings.Contains(hintFor(err), want) {
			t.Errorf("hint %q does not name %q", hintFor(err), want)
		}
	}
}
