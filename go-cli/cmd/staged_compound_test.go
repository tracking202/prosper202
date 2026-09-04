package cmd

import (
	"strings"
	"testing"
)

// Compound commands (create something, then use it) get a staged-change
// envelope instead of the record under --staged. Recognising it is what keeps
// them from reporting records that do not exist yet, or failing on an id the
// envelope was never going to carry.
func TestStagedChangeIDRecognisesTheEnvelope(t *testing.T) {
	cases := []struct {
		name   string
		body   string
		want   string
		wantOK bool
	}{
		{
			name:   "staged envelope",
			body:   `{"data":{"change_id":"chg_0123456789abcdef01234567","status":"staged","method":"POST","path":"/trackers"}}`,
			want:   "chg_0123456789abcdef01234567",
			wantOK: true,
		},
		{
			name:   "an ordinary created record is not an envelope",
			body:   `{"data":{"tracker_id":42,"aff_campaign_id":7}}`,
			wantOK: false,
		},
		{
			name:   "a record that happens to carry an unrelated change_id is not one",
			body:   `{"data":{"tracker_id":42,"change_id":"12345"}}`,
			wantOK: false,
		},
		{
			name:   "a list response is not an envelope",
			body:   `{"data":[{"change_id":"chg_0123456789abcdef01234567"}]}`,
			wantOK: false,
		},
		{
			name:   "garbage is not an envelope",
			body:   `not json`,
			wantOK: false,
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := stagedChangeID([]byte(tc.body))
			if ok != tc.wantOK {
				t.Fatalf("stagedChangeID(%s) ok = %v, want %v", tc.body, ok, tc.wantOK)
			}
			if ok && got != tc.want {
				t.Errorf("stagedChangeID(%s) = %q, want %q", tc.body, got, tc.want)
			}
		})
	}
}

// The tracker_id failure is reachable only from a genuinely malformed
// response now, and must still be a categorized error with a next step rather
// than a bare fmt.Errorf.
func TestMalformedTrackerCreateIsACategorizedError(t *testing.T) {
	err := validationError("tracker create response did not include tracker_id").
		WithHint("Run `p202 tracker create` and `p202 tracker get-url <id>` separately to see which step fails.")
	if code := exitCodeForError(err); code != 1 {
		t.Errorf("exit code = %d, want 1 (validation)", code)
	}
	if hint := hintFor(err); !strings.Contains(hint, "separately") {
		t.Errorf("hint = %q, want the split-the-steps guidance", hint)
	}
}
