package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"

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
	const defaultPageSize = 100
	const maxPages = 10000

	offset := 0
	pageSize := defaultPageSize
	all := make([]map[string]interface{}, 0)
	seenKeys := map[string]struct{}{}

	for page := 0; page < maxPages; page++ {
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
		if len(rows) == 0 {
			break
		}

		added := 0
		for _, row := range rows {
			key := rowDedupeKey(endpoint, row)
			if _, exists := seenKeys[key]; exists {
				continue
			}
			seenKeys[key] = struct{}{}
			all = append(all, row)
			added++
		}

		pg := extractPaginationInfo(data)

		step := pageSize
		if pg.hasLimit && pg.limit > 0 {
			step = pg.limit
		}
		if step <= 0 {
			return nil, fmt.Errorf("pagination stalled for %s: invalid page size at offset %d", endpoint, offset)
		}

		if pg.hasTotal && len(all) >= pg.total {
			break
		}

		if !pg.hasTotal && len(rows) < step {
			break
		}

		if added == 0 {
			return nil, fmt.Errorf("pagination stalled for %s: page at offset %d contained no new rows", endpoint, offset)
		}

		reportedOffset := offset
		if pg.hasOffset {
			reportedOffset = pg.offset
		}
		nextOffset := reportedOffset + step
		if nextOffset <= offset {
			return nil, fmt.Errorf("pagination stalled for %s: next offset %d did not advance from %d", endpoint, nextOffset, offset)
		}

		offset = nextOffset
		pageSize = step
	}

	return all, nil
}

type paginationInfo struct {
	total     int
	limit     int
	offset    int
	hasTotal  bool
	hasLimit  bool
	hasOffset bool
}

func extractPaginationInfo(data []byte) paginationInfo {
	var payload map[string]interface{}
	if err := json.Unmarshal(data, &payload); err != nil {
		return paginationInfo{}
	}
	rawPagination, ok := payload["pagination"].(map[string]interface{})
	if !ok {
		return paginationInfo{}
	}

	info := paginationInfo{}
	if v, ok := intValue(rawPagination["total"]); ok {
		info.total = v
		info.hasTotal = true
	}
	if v, ok := intValue(rawPagination["limit"]); ok {
		info.limit = v
		info.hasLimit = true
	}
	if v, ok := intValue(rawPagination["offset"]); ok {
		info.offset = v
		info.hasOffset = true
	}

	return info
}

func intValue(raw interface{}) (int, bool) {
	switch v := raw.(type) {
	case nil:
		return 0, false
	case int:
		return v, true
	case int64:
		return int(v), true
	case float64:
		return int(v), true
	case float32:
		return int(v), true
	case json.Number:
		parsed, err := v.Int64()
		if err != nil {
			return 0, false
		}
		return int(parsed), true
	case string:
		trimmed := strings.TrimSpace(v)
		if trimmed == "" {
			return 0, false
		}
		parsed, err := strconv.Atoi(trimmed)
		if err != nil {
			return 0, false
		}
		return parsed, true
	default:
		return 0, false
	}
}

func rowDedupeKey(endpoint string, row map[string]interface{}) string {
	candidateFields := []string{"id", "public_id"}
	if fields, exists := syncEntityIDFields[endpoint]; exists {
		candidateFields = append(candidateFields, fields...)
	}
	for _, field := range candidateFields {
		if val := scalarString(row[field]); val != "" {
			return field + ":" + val
		}
	}

	encoded, err := json.Marshal(row)
	if err != nil {
		return fmt.Sprintf("%v", row)
	}
	return "json:" + string(encoded)
}

func init() {
	exportCmd.Flags().StringP("output", "o", "", "Output file path")
	rootCmd.AddCommand(exportCmd)
}
