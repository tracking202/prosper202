package output

import (
	"encoding/csv"
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strings"
	"text/tabwriter"
)

// Render outputs API response data as either raw JSON or a formatted table.
func Render(data []byte, jsonMode bool) {
	if jsonMode {
		var parsed interface{}
		if json.Unmarshal(data, &parsed) == nil {
			pretty, err := json.MarshalIndent(parsed, "", "  ")
			if err != nil {
				fmt.Fprintln(os.Stderr, "Error formatting JSON:", err)
				os.Stdout.Write(data)
				return
			}
			fmt.Println(string(pretty))
		} else {
			os.Stdout.Write(data)
			fmt.Println()
		}
		return
	}

	var parsed interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		os.Stdout.Write(data)
		fmt.Println()
		return
	}

	switch v := parsed.(type) {
	case []interface{}:
		renderTable(v)
	case map[string]interface{}:
		if items, ok := v["data"].([]interface{}); ok {
			renderTable(items)
			if pg, ok := v["pagination"].(map[string]interface{}); ok {
				renderPagination(pg, jsonMode)
			}
		} else if inner, ok := v["data"].(map[string]interface{}); ok {
			renderObject(inner)
		} else {
			renderObject(v)
		}
	default:
		fmt.Println(string(data))
	}
}

// RenderCSV outputs API response data as CSV.
func RenderCSV(data []byte) {
	var parsed interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		os.Stdout.Write(data)
		if len(data) == 0 || data[len(data)-1] != '\n' {
			fmt.Println()
		}
		return
	}

	switch v := parsed.(type) {
	case []interface{}:
		renderTableCSV(v)
	case map[string]interface{}:
		if items, ok := v["data"].([]interface{}); ok {
			renderTableCSV(items)
		} else if inner, ok := v["data"].(map[string]interface{}); ok {
			renderObjectCSV(inner)
		} else {
			renderObjectCSV(v)
		}
	default:
		os.Stdout.Write(data)
		if len(data) == 0 || data[len(data)-1] != '\n' {
			fmt.Println()
		}
	}
}

// Success prints a success message for void operations (delete, etc).
func Success(format string, args ...interface{}) {
	fmt.Printf(format+"\n", args...)
}

func renderTable(items []interface{}) {
	if len(items) == 0 {
		fmt.Println("No results.")
		return
	}

	keySet := map[string]bool{}
	for _, item := range items {
		if obj, ok := item.(map[string]interface{}); ok {
			for k := range obj {
				keySet[k] = true
			}
		}
	}

	keys := make([]string, 0, len(keySet))
	for k := range keySet {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	// Move "id" to front if present
	for i, k := range keys {
		if k == "id" {
			keys = append([]string{"id"}, append(keys[:i], keys[i+1:]...)...)
			break
		}
	}

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)

	fmt.Fprintln(w, strings.Join(keys, "\t"))

	seps := make([]string, len(keys))
	for i, k := range keys {
		seps[i] = strings.Repeat("-", len(k))
	}
	fmt.Fprintln(w, strings.Join(seps, "\t"))

	for _, item := range items {
		obj, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		vals := make([]string, len(keys))
		for i, k := range keys {
			vals[i] = formatValue(obj[k])
		}
		fmt.Fprintln(w, strings.Join(vals, "\t"))
	}

	w.Flush()
}

func renderObject(obj map[string]interface{}) {
	keys := make([]string, 0, len(obj))
	for k := range obj {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	for _, k := range keys {
		fmt.Fprintf(w, "%s:\t%s\n", k, formatValue(obj[k]))
	}
	w.Flush()
}

func renderPagination(pg map[string]interface{}, jsonMode bool) {
	parts := []string{}
	if total, ok := pg["total"]; ok {
		parts = append(parts, fmt.Sprintf("Total: %v", total))
	}
	if limit, ok := pg["limit"]; ok {
		parts = append(parts, fmt.Sprintf("Limit: %v", limit))
	}
	if offset, ok := pg["offset"]; ok {
		parts = append(parts, fmt.Sprintf("Offset: %v", offset))
	}
	if len(parts) > 0 {
		fmt.Printf("\n%s\n", strings.Join(parts, " | "))
	}

	if jsonMode {
		return
	}

	total, hasTotal := numericValue(pg["total"])
	limit, hasLimit := numericValue(pg["limit"])
	offset, hasOffset := numericValue(pg["offset"])
	if !hasTotal || !hasLimit {
		return
	}
	if !hasOffset {
		offset = 0
	}

	shown := limit
	if offset+limit > total {
		shown = total - offset
	}
	if shown < 0 {
		shown = 0
	}
	if shown > 0 && shown < total {
		fmt.Fprintf(os.Stderr, "Warning: Showing %.0f of %.0f results. Use --all to fetch all.\n", shown, total)
	}
}

func numericValue(raw interface{}) (float64, bool) {
	switch v := raw.(type) {
	case nil:
		return 0, false
	case float64:
		return v, true
	case float32:
		return float64(v), true
	case int:
		return float64(v), true
	case int64:
		return float64(v), true
	case int32:
		return float64(v), true
	case uint:
		return float64(v), true
	case uint64:
		return float64(v), true
	case string:
		if strings.TrimSpace(v) == "" {
			return 0, false
		}
		var parsed float64
		_, err := fmt.Sscanf(v, "%f", &parsed)
		if err != nil {
			return 0, false
		}
		return parsed, true
	default:
		return 0, false
	}
}

func renderTableCSV(items []interface{}) {
	if len(items) == 0 {
		return
	}

	keySet := map[string]bool{}
	for _, item := range items {
		if obj, ok := item.(map[string]interface{}); ok {
			for k := range obj {
				keySet[k] = true
			}
		}
	}

	keys := make([]string, 0, len(keySet))
	for k := range keySet {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	// Move "id" to front if present.
	for i, k := range keys {
		if k == "id" {
			keys = append([]string{"id"}, append(keys[:i], keys[i+1:]...)...)
			break
		}
	}

	writer := csv.NewWriter(os.Stdout)
	if err := writer.Write(keys); err != nil {
		fmt.Fprintln(os.Stderr, "Error writing CSV header:", err)
		return
	}

	for _, item := range items {
		obj, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		record := make([]string, len(keys))
		for i, k := range keys {
			record[i] = formatValue(obj[k])
		}
		if err := writer.Write(record); err != nil {
			fmt.Fprintln(os.Stderr, "Error writing CSV row:", err)
			return
		}
	}

	writer.Flush()
	if err := writer.Error(); err != nil {
		fmt.Fprintln(os.Stderr, "Error finalizing CSV output:", err)
	}
}

func renderObjectCSV(obj map[string]interface{}) {
	keys := make([]string, 0, len(obj))
	for k := range obj {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	writer := csv.NewWriter(os.Stdout)
	if err := writer.Write([]string{"field", "value"}); err != nil {
		fmt.Fprintln(os.Stderr, "Error writing CSV header:", err)
		return
	}

	for _, k := range keys {
		if err := writer.Write([]string{k, formatValue(obj[k])}); err != nil {
			fmt.Fprintln(os.Stderr, "Error writing CSV row:", err)
			return
		}
	}

	writer.Flush()
	if err := writer.Error(); err != nil {
		fmt.Fprintln(os.Stderr, "Error finalizing CSV output:", err)
	}
}

func formatValue(v interface{}) string {
	if v == nil {
		return ""
	}
	switch val := v.(type) {
	case string:
		return val
	case float64:
		if val == float64(int64(val)) {
			return fmt.Sprintf("%d", int64(val))
		}
		return fmt.Sprintf("%.2f", val)
	case bool:
		if val {
			return "true"
		}
		return "false"
	case map[string]interface{}, []interface{}:
		data, _ := json.Marshal(val)
		return string(data)
	default:
		return fmt.Sprintf("%v", val)
	}
}
