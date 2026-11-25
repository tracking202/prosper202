package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"strings"

	"p202/internal/output"
)

// renderOpts builds output.Opts from the global output flags.
func renderOpts() output.Opts {
	opts := output.Opts{
		JSON:       jsonOutput,
		CSV:        csvOutput,
		Quiet:      quietOutput,
		NDJSON:     ndjsonOutput,
		Wide:       wideOutput,
		RawHeaders: rawHeaders,
		Compact:    compactJSON,
	}
	if strings.TrimSpace(fieldsFlag) != "" {
		for _, f := range strings.Split(fieldsFlag, ",") {
			if f = strings.TrimSpace(f); f != "" {
				opts.Fields = append(opts.Fields, f)
			}
		}
	}
	return opts
}

// render writes an API payload using the global output flags.
//
// An empty payload reaching here means the caller could not build one — several
// callers assemble theirs with json.Marshal and would otherwise pass nil on
// failure. Rendering nil prints nothing and exits 0, which reads as "no results"
// rather than "we failed to encode the results", so report it explicitly.
func render(data []byte) {
	if len(bytes.TrimSpace(data)) == 0 {
		fmt.Fprintln(os.Stderr, "Error: no output produced — the response payload could not be encoded.")
		return
	}
	output.RenderWith(data, renderOpts())
	if jsonOutput || ndjsonOutput || quietOutput {
		return
	}
	for _, note := range []string{otherCurrenciesNote(data), unmatchedConversionsNote(data)} {
		if note != "" {
			fmt.Fprintln(os.Stderr, "Note: "+note)
		}
	}
}

// unmatchedConversionsNote is what a table says under a summary whose window
// holds conversions the inbound log refused because they named no click here
// (`unmatched_conversions`, from 1.9.81). They have no campaign, so the count
// is the account's whatever the summary filters by. "" when there are none,
// when a campaign-data mask hid the count, or for any other answer.
func unmatchedConversionsNote(data []byte) string {
	var answer struct {
		Unmatched *struct {
			Conversions *json.Number `json:"conversions"`
			Complete    bool         `json:"complete"`
		} `json:"unmatched_conversions"`
	}
	if err := json.Unmarshal(data, &answer); err != nil || answer.Unmatched == nil || answer.Unmatched.Conversions == nil {
		return ""
	}
	n, err := answer.Unmatched.Conversions.Int64()
	if err != nil || n <= 0 {
		return ""
	}
	count := answer.Unmatched.Conversions.String()
	if !answer.Unmatched.Complete {
		count = "at least " + count
	}
	noun := "conversions"
	if n == 1 && answer.Unmatched.Complete {
		noun = "conversion"
	}
	return fmt.Sprintf("%s %s received in this period named no click here, so no report counts them (they have no campaign, so this is the account's count whatever the filters). `p202 conversion inbound-log --outcome refused` lists them with what to do about each.",
		count, noun)
}

// otherCurrenciesNote is what a table says under an answer whose money is
// not all in one currency: the server lists, as `other_currencies`, the
// revenue of the period that its ledger recorded in another currency than
// the account's (`currency`), and the answer's totals add those amounts as
// recorded. "" for any other answer. Under --json the list itself is there.
func otherCurrenciesNote(data []byte) string {
	var answer struct {
		Currency string `json:"currency"`
		Data     json.RawMessage
		Others   []struct {
			Currency    *string     `json:"currency"`
			Conversions json.Number `json:"conversions"`
			Amount      json.Number `json:"amount"`
		} `json:"other_currencies"`
	}
	if err := json.Unmarshal(data, &answer); err != nil || len(answer.Others) == 0 {
		return ""
	}
	account := answer.Currency
	if account == "" {
		// The summary states its currency inside data.
		var inner struct {
			Currency string `json:"currency"`
		}
		if json.Unmarshal(answer.Data, &inner) == nil {
			account = inner.Currency
		}
	}
	if account == "" {
		account = "the account's currency"
	}
	parts := make([]string, 0, len(answer.Others))
	for _, o := range answer.Others {
		if o.Currency != nil && *o.Currency != "" {
			parts = append(parts, fmt.Sprintf("%s %s (%s conversions)", *o.Currency, o.Amount, o.Conversions))
		} else {
			parts = append(parts, fmt.Sprintf("%s with no currency recorded (%s conversions)", o.Amount, o.Conversions))
		}
	}
	return fmt.Sprintf("not all revenue in this period is in %s. The account also recorded %s. Totals add these amounts as recorded; nothing converts them. `p202 conversion list` shows each conversion's own currency.",
		account, strings.Join(parts, ", "))
}
