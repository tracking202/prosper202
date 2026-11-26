package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"strings"

	"p202/internal/api"
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

// render writes an API payload using the global output flags, and refuses an
// empty one: printing nothing and exiting 0 reads as "no results" to a
// script. The refusal names the request whose answer it was, when the command
// sent one.
func render(data []byte) error {
	if len(bytes.TrimSpace(data)) == 0 {
		return emptyAnswerError()
	}
	output.RenderWith(data, renderOpts())
	if note := otherCurrenciesNote(data); note != "" && !jsonOutput && !ndjsonOutput && !quietOutput {
		fmt.Fprintln(os.Stderr, "Note: "+note)
	}
	return nil
}

// emptyAnswerError is render's refusal of an empty payload. An empty body is
// the server's failure (no success of the API sends one where a command
// prints its answer); a body that held nothing the command prints is too,
// read against what the command expects.
func emptyAnswerError() *CLIError {
	last, sent := api.LastAnswer()
	if !sent {
		return (&CLIError{
			Category: "server",
			Message:  "no output produced: the command had nothing to print and sent no request",
			ExitCode: ExitServer,
		}).WithHint("This is a fault in the CLI, not in your input: report the command line you ran.")
	}
	request := fmt.Sprintf("%s %s", last.Method, last.Path)
	if last.Bytes == 0 {
		return (&CLIError{
			Category: "server",
			Message:  fmt.Sprintf("no output produced: the server answered %s with HTTP %d and an empty body", request, last.Status),
			ExitCode: ExitServer,
		}).WithHint("The API sends a JSON body with every answer a command prints, so the request failed on the server: read its PHP error log for %s, and run `p202 system health`.", request)
	}
	return (&CLIError{
		Category: "server",
		Message:  fmt.Sprintf("no output produced: the server answered %s with HTTP %d and %d bytes, none of which this command prints", request, last.Status, last.Bytes),
		ExitCode: ExitServer,
	}).WithHint("The answer is not the shape this CLI expects: compare the server's version (`p202 system version`) with this CLI's (`p202 --version`), and run `p202 system health`.")
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
