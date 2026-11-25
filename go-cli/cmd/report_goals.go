package cmd

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"
)

// maxFunnelGoals is the steps one breakdown funnel takes (GoalFunnel::MAX_GOALS).
const maxFunnelGoals = 10

// goalsParam reads `report breakdown --goals`: goal ids in funnel order,
// comma separated. "" when the flag is not set; anything but distinct
// positive ids, at most maxFunnelGoals of them, is refused before a request.
func goalsParam(cmd *cobra.Command) (string, error) {
	raw, _ := cmd.Flags().GetString("goals")
	if !cmd.Flags().Changed("goals") {
		return "", nil
	}
	const hint = "List the goal ids, first step first, e.g. --goals 12,13,14; `p202 goal list` lists the account's goals."
	parts := strings.Split(raw, ",")
	seen := map[uint64]bool{}
	ids := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		id, err := strconv.ParseUint(p, 10, 32)
		if err != nil || id == 0 || strconv.FormatUint(id, 10) != p {
			return "", validationError("--goals takes goal ids in funnel order, comma separated: %q is not a goal id", p).WithHint(hint)
		}
		if seen[id] {
			return "", validationError("--goals names goal %d twice; each step is one goal", id).WithHint(hint)
		}
		seen[id] = true
		ids = append(ids, p)
	}
	if len(ids) > maxFunnelGoals {
		return "", validationError("--goals takes at most %d goals, got %d", maxFunnelGoals, len(ids)).WithHint(hint)
	}
	return strings.Join(ids, ","), nil
}

type funnelStep struct {
	GoalID   json.Number `json:"goal_id"`
	Reached  json.Number `json:"reached"`
	Rate     json.Number `json:"rate"`
	StepRate json.Number `json:"step_rate"`
	DropOff  json.Number `json:"drop_off"`
}

// flattenGoalFunnel puts each row's funnel (`goals`) into columns a table
// and CSV can show, goal_01_reached, goal_01_rate, goal_01_step_rate and
// goal_01_drop_off per step, and returns the note that says which goal each
// step is and what the funnel is over every click (`goal_totals`). An
// answer without a funnel comes back as it was, with no note. --json and
// --ndjson print the server's answer instead.
func flattenGoalFunnel(data []byte) ([]byte, string, error) {
	var top map[string]json.RawMessage
	if err := json.Unmarshal(data, &top); err != nil {
		return nil, "", serverShapeError(err)
	}
	if _, ok := top["goals"]; !ok {
		return data, "", nil
	}
	var answer struct {
		Data  []map[string]json.RawMessage `json:"data"`
		Goals []struct {
			GoalID json.Number `json:"goal_id"`
			Name   string      `json:"name"`
		} `json:"goals"`
		Totals *struct {
			Clicks json.Number  `json:"total_clicks"`
			Goals  []funnelStep `json:"goals"`
		} `json:"goal_totals"`
	}
	if err := json.Unmarshal(data, &answer); err != nil {
		return nil, "", serverShapeError(err)
	}
	for _, row := range answer.Data {
		rawSteps, ok := row["goals"]
		if !ok {
			continue
		}
		var steps []funnelStep
		if err := json.Unmarshal(rawSteps, &steps); err != nil {
			return nil, "", serverShapeError(err)
		}
		delete(row, "goals")
		for i, s := range steps {
			prefix := fmt.Sprintf("goal_%02d_", i+1)
			row[prefix+"reached"] = numberOrNull(s.Reached)
			row[prefix+"rate"] = numberOrNull(s.Rate)
			row[prefix+"step_rate"] = numberOrNull(s.StepRate)
			row[prefix+"drop_off"] = numberOrNull(s.DropOff)
		}
	}
	rows, err := json.Marshal(answer.Data)
	if err != nil {
		return nil, "", err
	}
	top["data"] = rows
	out, err := json.Marshal(top)
	if err != nil {
		return nil, "", err
	}

	names := make([]string, len(answer.Goals))
	for i, g := range answer.Goals {
		names[i] = fmt.Sprintf("goal_%02d is %s (goal %s)", i+1, g.Name, g.GoalID)
	}
	note := "Funnel steps: " + strings.Join(names, ", ") + "."
	if t := answer.Totals; t != nil {
		reached := make([]string, len(t.Goals))
		for i, s := range t.Goals {
			name := "goal " + s.GoalID.String()
			if i < len(answer.Goals) {
				name = answer.Goals[i].Name
			}
			count := s.Reached.String()
			if count == "" {
				count = "hidden" // a role without campaign figures (masked)
			}
			reached[i] = fmt.Sprintf("%s %s (%s%%)", name, count, s.Rate)
		}
		note += fmt.Sprintf(" Over all %s clicks, the rows' and those with no value for the dimension: %s.", t.Clicks, strings.Join(reached, ", "))
	}
	return out, note, nil
}

// numberOrNull is a step's figure as JSON: null where the server sent null
// (a masked answer hides the counts) and json.Number left it empty.
func numberOrNull(n json.Number) json.RawMessage {
	if n == "" {
		return json.RawMessage("null")
	}
	return json.RawMessage(n.String())
}

func serverShapeError(err error) error {
	return &CLIError{
		Category: "server",
		ExitCode: ExitServer,
		Message:  fmt.Sprintf("reading the breakdown's goal funnel: the server's response isn't the breakdown it should be (%v)", err),
		Hint:     "Run the same command with --json to see the raw response, and `p202 system health` to check the server.",
		Cause:    err,
	}
}
