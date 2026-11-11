package cmd

import (
	"encoding/json"
	"fmt"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// p202 click not-fraud <id>: Visitors' Not fraud button
// (POST /clicks/{id}/not-fraud).
var clickNotFraudCmd = &cobra.Command{
	Use:   "not-fraud <id>",
	Short: "Count a click IPQualityScore filtered again, and always count its address (Visitors > Not fraud)",
	Long: "For a click filtered for its fraud score (filter_reason fraud_score): the click, and your\n" +
		"other clicks from the same address that were filtered for their score (200 a call), count\n" +
		"again in every report, and the address goes on your click filter list as always count, so\n" +
		"IPQualityScore is not asked about it again.\n\n" +
		"Find the clicks with `p202 click list --filter-reason fraud_score`. See the list with\n" +
		"`p202 click-filter get`, and take an address off it with `p202 click-filter list remove`.\n" +
		"A click one of your own rules filtered is changed with `p202 click-filter`, not here.\n" +
		"Needs a role with access_to_personal_settings and a key that may write clicks.",
	Example: "  p202 click list --filter-reason fraud_score\n" +
		"  p202 click not-fraud 940001\n" +
		"  p202 click not-fraud 940001 --json",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := validateID(args[0])
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("clicks/"+id+"/not-fraud", nil)
		if err != nil {
			if isNotFoundErr(err) {
				return withHint(err, "Only a click of yours filtered for its fraud score can be marked: list them with `p202 click list --filter-reason fraud_score`.")
			}
			return err
		}
		if jsonOutput || ndjsonOutput || csvOutput || quietOutput {
			render(data)
			return nil
		}
		var answer struct {
			Data struct {
				Address       string `json:"ip_address"`
				Counted       int    `json:"clicks_counted"`
				More          bool   `json:"more_clicks_filtered"`
				AlwaysCounted bool   `json:"address_always_counted"`
				Note          string `json:"note"`
			} `json:"data"`
		}
		if err := json.Unmarshal(data, &answer); err != nil || answer.Data.Note == "" {
			return &CLIError{Category: "server", ExitCode: ExitServer,
				Message: fmt.Sprintf("the server's answer is not a not-fraud result (%v)", err),
				Hint:    "Check the server with `p202 system health`; rerun with --json to see what it sent."}
		}
		fmt.Printf("%d click(s) from %s count again.\n%s\n", answer.Data.Counted, answer.Data.Address, answer.Data.Note)
		if answer.Data.More {
			fmt.Println("More clicks from this address are still filtered: run the command again on one of them.")
		}
		return nil
	},
}

func init() {
	clickCmd.AddCommand(clickNotFraudCmd)
}
