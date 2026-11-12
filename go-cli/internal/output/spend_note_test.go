package output

import (
	"bytes"
	"io"
	"os"
	"strings"
	"testing"
)

func stderrOf(t *testing.T, f func()) string {
	t.Helper()
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	old := os.Stderr
	os.Stderr = w
	f()
	os.Stderr = old
	_ = w.Close()
	var b bytes.Buffer
	_, _ = io.Copy(&b, r)
	return b.String()
}

func TestASpendLedgerReportSaysHowFarItsCostReaches(t *testing.T) {
	answer := []byte(`{"data":{"total_cost":4.4},"spend_ledger":{"reaches":1794297600,` +
		`"note":"Spend data reaches 2026-11-11 02:00 (America/New_York). Clicks after that are costed at their CPC.",` +
		`"unallocated":{"total_cost":2.25}}}`)
	got := stderrOf(t, func() { noteSpendLedger(answer) })
	if !strings.Contains(got, "Spend data reaches 2026-11-11 02:00") || !strings.Contains(got, "unallocated, in no row): 2.25") {
		t.Fatalf("stderr = %q", got)
	}
	if got := stderrOf(t, func() { noteSpendLedger([]byte(`{"data":{"total_cost":1}}`)) }); got != "" {
		t.Fatalf("a report without the ledger printed %q", got)
	}
}
