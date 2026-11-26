package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"time"
)

// summaryCompare is what `report summary --compare` takes: the server's
// compare values.
var summaryCompare = []string{"previous"}

// timeseriesGroups are the dimensions `report timeseries --group-by` splits
// by, as the server's ReportsController::TIMESERIES_GROUPS lists them.
var timeseriesGroups = []string{"campaign", "aff_network", "ppc_network", "ppc_account", "landing_page", "country"}

// compareAnswerError is a compared summary the CLI cannot read: the server
// answered 200 with something other than both windows.
func compareAnswerError(what string, cause error) error {
	return &CLIError{
		Category: "server",
		ExitCode: ExitServer,
		Message:  "report summary --compare: " + what,
		Hint: "The flags are fine: a server older than compare answers 422 for it, so this one (or a proxy in front of it) " +
			"answered something else. Run the same command with --json to see the raw response, and `p202 system health` to check the server.",
		Cause: cause,
	}
}

// comparedSummaryRows turns a summary answered with compare=previous into
// two rows for a table, this window's and the previous one's, each led by
// its name and bounds (in this machine's zone). The answer's other keys
// stay beside them.
func comparedSummaryRows(data []byte) ([]byte, error) {
	var answer map[string]json.RawMessage
	if err := json.Unmarshal(data, &answer); err != nil {
		return nil, compareAnswerError(fmt.Sprintf("the answer isn't JSON (%v)", err), err)
	}
	type bounds struct {
		From *int64          `json:"time_from"`
		To   *int64          `json:"time_to"`
		Data json.RawMessage `json:"data"`
	}
	var window, previous bounds
	for _, part := range []struct {
		key  string
		into *bounds
	}{{"window", &window}, {"previous", &previous}} {
		raw, ok := answer[part.key]
		if !ok {
			return nil, compareAnswerError("the answer has no `"+part.key+"`", nil)
		}
		if err := json.Unmarshal(raw, part.into); err != nil {
			return nil, compareAnswerError(fmt.Sprintf("`%s` is not a window (%v)", part.key, err), err)
		}
		if part.into.From == nil || part.into.To == nil {
			return nil, compareAnswerError("`"+part.key+"` has no time_from/time_to", nil)
		}
	}
	if previous.Data == nil {
		return nil, compareAnswerError("`previous` has no data", nil)
	}
	row := func(name string, b bounds, figures json.RawMessage) (map[string]interface{}, error) {
		out := map[string]interface{}{}
		dec := json.NewDecoder(bytes.NewReader(figures))
		dec.UseNumber()
		if err := dec.Decode(&out); err != nil {
			return nil, compareAnswerError(fmt.Sprintf("the %s window's figures are not an object (%v)", name, err), err)
		}
		out["window"] = name
		out["time_from"] = time.Unix(*b.From, 0).Format(time.RFC3339)
		out["time_to"] = time.Unix(*b.To, 0).Format(time.RFC3339)
		return out, nil
	}
	current, err := row("current", window, answer["data"])
	if err != nil {
		return nil, err
	}
	before, err := row("previous", previous, previous.Data)
	if err != nil {
		return nil, err
	}
	rows, err := json.Marshal([]map[string]interface{}{current, before})
	if err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	answer["data"] = rows
	delete(answer, "window")
	delete(answer, "previous")
	out, err := json.Marshal(answer)
	if err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	return out, nil
}
