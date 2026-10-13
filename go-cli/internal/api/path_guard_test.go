package api

import "testing"

func TestCheckAPIPathKeepsTheRequestWhereTheCommandPutIt(t *testing.T) {
	for _, ok := range []string{"campaigns", "campaigns/7", "/campaigns/7", "staged-changes/chg_1a2b/apply", "ltv/subscriptions/sub%2F1/events", "apps/12/installs/abc-def"} {
		if err := checkAPIPath(ok); err != nil {
			t.Errorf("%q refused: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "/", "staged-changes/chg_1/apply?/discard", "campaigns/7#x", "campaigns/../users", "campaigns/./7", "campaigns//7", "campaigns/7/", "campaigns/a b", "campaigns/7\\x", "campaigns/7\n", "campaigns/\x7f"} {
		err := checkAPIPath(bad)
		if err == nil {
			t.Errorf("%q was not refused", bad)
			continue
		}
		if ErrorCategory(err) != "validation" {
			t.Errorf("%q: category %q, want validation", bad, ErrorCategory(err))
		}
	}
}
