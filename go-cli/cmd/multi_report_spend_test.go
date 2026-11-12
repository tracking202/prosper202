package cmd

import (
	"encoding/json"
	"strings"
	"testing"
)

// A --profiles TOTAL that adds a profile whose cost read the spend ledger to
// one costed at CPC says so, and carries what each said under meta.
func TestTheProfilesTotalNamesTheProfilesCostedFromTheLedger(t *testing.T) {
	ledgers := map[string]interface{}{"shop": map[string]interface{}{"reaches_at": "2026-11-11T07:00:00Z"}}
	note := spendLedgerProfilesNote(ledgers, 2)
	if !strings.Contains(note, "shop (spend data reaches 2026-11-11T07:00:00Z)") || !strings.Contains(note, "the other profiles' cost is their clicks' CPC") {
		t.Fatalf("note = %q", note)
	}
	if spendLedgerProfilesNote(map[string]interface{}{}, 2) != "" {
		t.Fatal("a TOTAL with no ledger profile printed a note")
	}
	payload, err := withSpendLedgers([]byte(`{"data":[],"meta":{"warnings":[]}}`), ledgers)
	if err != nil {
		t.Fatal(err)
	}
	var out struct {
		Meta struct {
			SpendLedger map[string]map[string]string `json:"spend_ledger"`
		} `json:"meta"`
	}
	if err := json.Unmarshal(payload, &out); err != nil || out.Meta.SpendLedger["shop"]["reaches_at"] != "2026-11-11T07:00:00Z" {
		t.Fatalf("meta.spend_ledger = %s (%v)", payload, err)
	}
}
