package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var portableEntities = map[string]string{
	"campaigns":     "campaigns",
	"aff-networks":  "aff-networks",
	"ppc-networks":  "ppc-networks",
	"ppc-accounts":  "ppc-accounts",
	"rotators":      "rotators",
	"trackers":      "trackers",
	"landing-pages": "landing-pages",
	"text-ads":      "text-ads",
}

var exportCmd = &cobra.Command{
	Use:   "export <entity|all>",
	Short: "Export entities to JSON",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		target := args[0]
		var payload interface{}

		if target == "all" {
			out := map[string]interface{}{}
			for entityName, endpoint := range portableEntities {
				rows, err := fetchAllRows(c, endpoint)
				if err != nil {
					return fmt.Errorf("export %s failed: %w", entityName, err)
				}
				out[entityName] = rows
			}
			payload = out
		} else {
			endpoint, ok := portableEntities[target]
			if !ok {
				return fmt.Errorf("unsupported entity %q", target)
			}
			rows, err := fetchAllRows(c, endpoint)
			if err != nil {
				return err
			}
			payload = map[string]interface{}{
				"entity": target,
				"data":   rows,
			}
		}

		encoded, err := json.Marshal(payload)
		if err != nil {
			return err
		}

		outputPath, _ := cmd.Flags().GetString("output")
		if outputPath != "" {
			pretty, err := json.MarshalIndent(payload, "", "  ")
			if err != nil {
				return err
			}
			pretty = append(pretty, '\n')
			if err := os.WriteFile(outputPath, pretty, 0600); err != nil {
				return err
			}
			output.Success("Export written to %s", outputPath)
			return nil
		}

		render(encoded)
		return nil
	},
}

func fetchAllRows(c *api.Client, endpoint string) ([]map[string]interface{}, error) {
	return fetchAllRowsWithParams(c, endpoint, nil)
}

func fetchAllRowsWithParams(c *api.Client, endpoint string, baseParams map[string]string) ([]map[string]interface{}, error) {
	const pageSize = 100
	offset := 0
	all := make([]map[string]interface{}, 0)

	for {
		params := map[string]string{
			"limit":  strconv.Itoa(pageSize),
			"offset": strconv.Itoa(offset),
		}
		for key, value := range baseParams {
			if value == "" {
				continue
			}
			params[key] = value
		}
		data, err := c.Get(endpoint, params)
		if err != nil {
			return nil, err
		}
		rows, err := parseDataArray(data)
		if err != nil {
			return nil, err
		}
		all = append(all, rows...)
		if len(rows) < pageSize {
			break
		}
		offset += pageSize
	}

	return all, nil
}

func init() {
	exportCmd.Flags().StringP("output", "o", "", "Output file path")
	rootCmd.AddCommand(exportCmd)
}
