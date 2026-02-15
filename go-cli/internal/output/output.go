package output

import (
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
			if meta, ok := v["meta"].(map[string]interface{}); ok {
				renderMeta(meta)
			}
		} else {
			renderObject(v)
		}
	default:
		fmt.Println(string(data))
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

func renderMeta(meta map[string]interface{}) {
	parts := []string{}
	if page, ok := meta["current_page"]; ok {
		parts = append(parts, fmt.Sprintf("Page %v", page))
	}
	if total, ok := meta["total"]; ok {
		parts = append(parts, fmt.Sprintf("Total: %v", total))
	}
	if lastPage, ok := meta["last_page"]; ok {
		parts = append(parts, fmt.Sprintf("Last page: %v", lastPage))
	}
	if len(parts) > 0 {
		fmt.Printf("\n%s\n", strings.Join(parts, " | "))
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
