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

// Opts controls how a response is rendered. The zero value renders a human
// table to stdout; flags select JSON/CSV/quiet/ndjson and column shaping.
type Opts struct {
	JSON       bool
	CSV        bool
	Quiet      bool     // id-only: one id per line, no header (for scripting)
	NDJSON     bool     // newline-delimited JSON, one row per line
	Wide       bool     // show all columns (no width cap)
	RawHeaders bool     // keep raw API keys as headers instead of friendly names
	Fields     []string // explicit column selection (in order)
}

// friendlyHeaders maps raw API field names to human-readable column headers.
var friendlyHeaders = map[string]string{
	"total_clicks":         "Clicks",
	"total_click_throughs": "Clickthroughs",
	"total_leads":          "Conversions",
	"total_income":         "Revenue",
	"total_cost":           "Cost",
	"total_net":            "Profit",
	"roi":                  "ROI %",
	"epc":                  "EPC",
	"cpa":                  "CPA",
	"avg_cpc":              "Avg CPC",
	"conv_rate":            "Conv %",
	"breakeven_cpc":        "Breakeven CPC",
	"margin":               "Margin",
}

// metricOrder is the fixed business sequence for metric columns so the row
// label stays left and numbers read in a consistent order across commands.
var metricOrder = []string{
	"name", "keyword",
	"total_clicks", "total_click_throughs", "total_leads", "conv_rate",
	"total_income", "total_cost", "total_net", "roi", "epc", "cpa", "avg_cpc",
	"breakeven_cpc", "margin", "verdict", "bucket", "reason",
}

const maxColWidth = 42 // cap wide columns unless --wide

// Render outputs API response data as either raw JSON or a formatted table.
// Retained for the many existing call sites; delegates to RenderWith.
func Render(data []byte, jsonMode bool) {
	RenderWith(data, Opts{JSON: jsonMode})
}

// RenderCSV outputs API response data as CSV. Retained for call sites.
func RenderCSV(data []byte) {
	RenderWith(data, Opts{CSV: true})
}

// RenderWith renders data according to opts.
func RenderWith(data []byte, opts Opts) {
	if opts.Quiet {
		renderQuiet(data)
		return
	}
	if opts.NDJSON {
		renderNDJSON(data)
		return
	}
	if opts.JSON {
		renderJSON(data)
		return
	}
	if opts.CSV {
		renderCSVData(data, opts)
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
		renderTable(v, opts)
	case map[string]interface{}:
		if items, ok := v["data"].([]interface{}); ok {
			renderTable(items, opts)
			if pg, ok := v["pagination"].(map[string]interface{}); ok {
				renderPagination(pg)
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

func renderJSON(data []byte) {
	var parsed interface{}
	if json.Unmarshal(data, &parsed) == nil {
		pretty, err := json.MarshalIndent(parsed, "", "  ")
		if err != nil {
			fmt.Fprintln(os.Stderr, "Error formatting JSON:", err)
			os.Stdout.Write(data)
			return
		}
		fmt.Println(string(pretty))
		return
	}
	os.Stdout.Write(data)
	fmt.Println()
}

// renderQuiet prints one id per row (no header) for scripting pipelines.
func renderQuiet(data []byte) {
	for _, obj := range rowsOf(data) {
		if id := idOf(obj); id != "" {
			fmt.Println(id)
		}
	}
}

// renderNDJSON prints one compact JSON object per row.
func renderNDJSON(data []byte) {
	for _, obj := range rowsOf(data) {
		if b, err := json.Marshal(obj); err == nil {
			fmt.Println(string(b))
		}
	}
}

// rowsOf extracts the list of row objects from any of the response shapes.
func rowsOf(data []byte) []map[string]interface{} {
	var parsed interface{}
	if json.Unmarshal(data, &parsed) != nil {
		return nil
	}
	var items []interface{}
	switch v := parsed.(type) {
	case []interface{}:
		items = v
	case map[string]interface{}:
		if arr, ok := v["data"].([]interface{}); ok {
			items = arr
		} else if inner, ok := v["data"].(map[string]interface{}); ok {
			return []map[string]interface{}{inner}
		} else {
			return []map[string]interface{}{v}
		}
	}
	out := make([]map[string]interface{}, 0, len(items))
	for _, it := range items {
		if obj, ok := it.(map[string]interface{}); ok {
			out = append(out, obj)
		}
	}
	return out
}

// idOf returns the best identifier field for a row (id, or a known primary key).
func idOf(obj map[string]interface{}) string {
	for _, k := range []string{"id", "aff_campaign_id", "tracker_id", "ppc_account_id",
		"aff_network_id", "ppc_network_id", "landing_page_id", "text_ad_id", "conv_id",
		"rotator_id", "user_id", "click_id"} {
		if v, ok := obj[k]; ok {
			return formatValue(v)
		}
	}
	return ""
}

// Success prints a success message for void operations (delete, etc) to stderr
// so it does not corrupt piped data on stdout.
func Success(format string, args ...interface{}) {
	fmt.Fprintf(os.Stderr, format+"\n", args...)
}

// orderColumns returns the display column order: id first, then the configured
// business sequence for known fields, then remaining keys alphabetically.
func orderColumns(keys []string, opts Opts) []string {
	if len(opts.Fields) > 0 {
		present := map[string]bool{}
		for _, k := range keys {
			present[k] = true
		}
		out := make([]string, 0, len(opts.Fields))
		for _, f := range opts.Fields {
			if present[f] {
				out = append(out, f)
			}
		}
		return out
	}

	has := map[string]bool{}
	for _, k := range keys {
		has[k] = true
	}
	used := map[string]bool{}
	ordered := make([]string, 0, len(keys))

	if has["id"] {
		ordered = append(ordered, "id")
		used["id"] = true
	}
	for _, k := range metricOrder {
		if has[k] && !used[k] {
			ordered = append(ordered, k)
			used[k] = true
		}
	}
	rest := make([]string, 0)
	for _, k := range keys {
		if !used[k] {
			rest = append(rest, k)
		}
	}
	sort.Strings(rest)
	return append(ordered, rest...)
}

func headerFor(key string, raw bool) string {
	if raw {
		return key
	}
	if h, ok := friendlyHeaders[key]; ok {
		return h
	}
	return key
}

func renderTable(items []interface{}, opts Opts) {
	if len(items) == 0 {
		fmt.Fprintln(os.Stderr, "No results.")
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
	keys = orderColumns(keys, opts)

	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)

	headers := make([]string, len(keys))
	for i, k := range keys {
		headers[i] = truncate(headerFor(k, opts.RawHeaders), opts.Wide)
	}
	fmt.Fprintln(w, strings.Join(headers, "\t"))

	// Separator sized to each column's display width (header vs widest value),
	// so the underline spans the full column instead of just the key length.
	seps := make([]string, len(keys))
	for i, k := range keys {
		width := len([]rune(headers[i]))
		for _, item := range items {
			obj, _ := item.(map[string]interface{})
			cell := truncate(formatValue(obj[k]), opts.Wide)
			if n := len([]rune(cell)); n > width {
				width = n
			}
		}
		seps[i] = strings.Repeat("-", width)
	}
	fmt.Fprintln(w, strings.Join(seps, "\t"))

	for _, item := range items {
		obj, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		vals := make([]string, len(keys))
		for i, k := range keys {
			vals[i] = truncate(formatValue(obj[k]), opts.Wide)
		}
		fmt.Fprintln(w, strings.Join(vals, "\t"))
	}
	w.Flush()
}

func truncate(s string, wide bool) string {
	if wide {
		return s
	}
	r := []rune(s)
	if len(r) <= maxColWidth {
		return s
	}
	return string(r[:maxColWidth-1]) + "…"
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

// renderPagination writes the page summary and the truncation warning to
// stderr, keeping stdout strictly data.
func renderPagination(pg map[string]interface{}) {
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
		fmt.Fprintf(os.Stderr, "%s\n", strings.Join(parts, " | "))
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
		n, err := fmt.Sscanf(v, "%f", &parsed)
		if err != nil || n != 1 {
			return 0, false
		}
		return parsed, true
	default:
		return 0, false
	}
}

func renderCSVData(data []byte, opts Opts) {
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
		renderTableCSV(v, opts)
	case map[string]interface{}:
		if items, ok := v["data"].([]interface{}); ok {
			renderTableCSV(items, opts)
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

func renderTableCSV(items []interface{}, opts Opts) {
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
	keys = orderColumns(keys, opts)

	writer := csv.NewWriter(os.Stdout)
	headers := make([]string, len(keys))
	for i, k := range keys {
		headers[i] = headerFor(k, opts.RawHeaders)
	}
	if err := writer.Write(headers); err != nil {
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
