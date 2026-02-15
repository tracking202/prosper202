package output

import (
	"bytes"
	"encoding/json"
	"io"
	"os"
	"strings"
	"testing"
)

// captureStdout captures everything written to os.Stdout during fn().
func captureStdout(t *testing.T, fn func()) string {
	t.Helper()

	oldStdout := os.Stdout
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatalf("os.Pipe() error: %v", err)
	}
	os.Stdout = w

	fn()

	w.Close()
	os.Stdout = oldStdout

	var buf bytes.Buffer
	io.Copy(&buf, r)
	r.Close()

	return buf.String()
}

func TestRenderJSONModePrettyPrints(t *testing.T) {
	input := `{"id":1,"name":"test"}`
	out := captureStdout(t, func() {
		Render([]byte(input), true)
	})

	// Pretty-printed JSON should have indentation
	if !strings.Contains(out, "  ") {
		t.Errorf("JSON mode should produce indented output, got:\n%s", out)
	}

	// Verify it's valid JSON
	var parsed interface{}
	trimmed := strings.TrimSpace(out)
	if err := json.Unmarshal([]byte(trimmed), &parsed); err != nil {
		t.Errorf("output is not valid JSON: %v\noutput:\n%s", err, out)
	}

	// Verify the values are preserved
	obj, ok := parsed.(map[string]interface{})
	if !ok {
		t.Fatalf("expected map, got %T", parsed)
	}
	if obj["id"] != float64(1) {
		t.Errorf("id = %v, want 1", obj["id"])
	}
	if obj["name"] != "test" {
		t.Errorf("name = %v, want %q", obj["name"], "test")
	}
}

func TestRenderJSONModeWithArray(t *testing.T) {
	input := `[{"id":1},{"id":2}]`
	out := captureStdout(t, func() {
		Render([]byte(input), true)
	})

	// Should contain indentation (pretty-printed)
	if !strings.Contains(out, "  ") {
		t.Errorf("JSON mode should produce indented output, got:\n%s", out)
	}

	var parsed interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &parsed); err != nil {
		t.Errorf("output is not valid JSON: %v", err)
	}
}

func TestRenderJSONModeWithInvalidJSON(t *testing.T) {
	input := `not json`
	out := captureStdout(t, func() {
		Render([]byte(input), true)
	})

	// Should output raw data followed by newline
	if !strings.Contains(out, "not json") {
		t.Errorf("expected raw data in output, got:\n%s", out)
	}
}

func TestRenderTableWithArrayOfObjects(t *testing.T) {
	input := `[{"id":1,"name":"Campaign A"},{"id":2,"name":"Campaign B"}]`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	// Should contain table headers
	if !strings.Contains(out, "id") {
		t.Errorf("table should contain 'id' header, got:\n%s", out)
	}
	if !strings.Contains(out, "name") {
		t.Errorf("table should contain 'name' header, got:\n%s", out)
	}

	// Should contain separator line (dashes)
	if !strings.Contains(out, "--") {
		t.Errorf("table should contain separator line, got:\n%s", out)
	}

	// Should contain data values
	if !strings.Contains(out, "Campaign A") {
		t.Errorf("table should contain 'Campaign A', got:\n%s", out)
	}
	if !strings.Contains(out, "Campaign B") {
		t.Errorf("table should contain 'Campaign B', got:\n%s", out)
	}

	// id should appear before name (id is moved to front)
	idIdx := strings.Index(out, "id")
	nameIdx := strings.Index(out, "name")
	if idIdx >= nameIdx {
		t.Errorf("'id' column should appear before 'name', id at %d, name at %d", idIdx, nameIdx)
	}
}

func TestRenderTableWithDataAndMeta(t *testing.T) {
	input := `{
		"data": [
			{"id":1,"name":"Item 1"},
			{"id":2,"name":"Item 2"}
		],
		"meta": {
			"current_page": 1,
			"total": 50,
			"last_page": 5
		}
	}`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	// Should render the data table
	if !strings.Contains(out, "Item 1") {
		t.Errorf("should contain 'Item 1', got:\n%s", out)
	}
	if !strings.Contains(out, "Item 2") {
		t.Errorf("should contain 'Item 2', got:\n%s", out)
	}

	// Should render pagination info
	if !strings.Contains(out, "Page 1") {
		t.Errorf("should contain 'Page 1', got:\n%s", out)
	}
	if !strings.Contains(out, "Total: 50") {
		t.Errorf("should contain 'Total: 50', got:\n%s", out)
	}
	if !strings.Contains(out, "Last page: 5") {
		t.Errorf("should contain 'Last page: 5', got:\n%s", out)
	}
}

func TestRenderSingleObject(t *testing.T) {
	input := `{"id":42,"name":"My Campaign","status":"active"}`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	// Single object renders as key-value pairs
	if !strings.Contains(out, "id:") {
		t.Errorf("should contain 'id:', got:\n%s", out)
	}
	if !strings.Contains(out, "42") {
		t.Errorf("should contain '42', got:\n%s", out)
	}
	if !strings.Contains(out, "name:") {
		t.Errorf("should contain 'name:', got:\n%s", out)
	}
	if !strings.Contains(out, "My Campaign") {
		t.Errorf("should contain 'My Campaign', got:\n%s", out)
	}
	if !strings.Contains(out, "status:") {
		t.Errorf("should contain 'status:', got:\n%s", out)
	}
	if !strings.Contains(out, "active") {
		t.Errorf("should contain 'active', got:\n%s", out)
	}
}

func TestRenderEmptyArray(t *testing.T) {
	input := `[]`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	if !strings.Contains(out, "No results.") {
		t.Errorf("empty array should show 'No results.', got:\n%s", out)
	}
}

func TestRenderEmptyDataArray(t *testing.T) {
	input := `{"data":[],"meta":{"current_page":1,"total":0}}`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	if !strings.Contains(out, "No results.") {
		t.Errorf("empty data array should show 'No results.', got:\n%s", out)
	}
}

func TestSuccess(t *testing.T) {
	out := captureStdout(t, func() {
		Success("Campaign %s deleted.", "42")
	})

	expected := "Campaign 42 deleted.\n"
	if out != expected {
		t.Errorf("Success() output = %q, want %q", out, expected)
	}
}

func TestSuccessNoArgs(t *testing.T) {
	out := captureStdout(t, func() {
		Success("Operation completed.")
	})

	expected := "Operation completed.\n"
	if out != expected {
		t.Errorf("Success() output = %q, want %q", out, expected)
	}
}

func TestFormatValue(t *testing.T) {
	tests := []struct {
		name  string
		input interface{}
		want  string
	}{
		{
			name:  "nil",
			input: nil,
			want:  "",
		},
		{
			name:  "string",
			input: "hello",
			want:  "hello",
		},
		{
			name:  "float64 integer",
			input: float64(42),
			want:  "42",
		},
		{
			name:  "float64 decimal",
			input: float64(3.14),
			want:  "3.14",
		},
		{
			name:  "float64 zero",
			input: float64(0),
			want:  "0",
		},
		{
			name:  "float64 negative integer",
			input: float64(-5),
			want:  "-5",
		},
		{
			name:  "float64 large integer",
			input: float64(1000000),
			want:  "1000000",
		},
		{
			name:  "bool true",
			input: true,
			want:  "true",
		},
		{
			name:  "bool false",
			input: false,
			want:  "false",
		},
		{
			name:  "nested object",
			input: map[string]interface{}{"key": "value"},
			want:  `{"key":"value"}`,
		},
		{
			name:  "nested array",
			input: []interface{}{"a", "b"},
			want:  `["a","b"]`,
		},
		{
			name:  "empty string",
			input: "",
			want:  "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := formatValue(tt.input)
			if got != tt.want {
				t.Errorf("formatValue(%v) = %q, want %q", tt.input, got, tt.want)
			}
		})
	}
}

func TestRenderTableIdColumnFirst(t *testing.T) {
	// Verify that the "id" column is moved to the front even when other
	// columns come alphabetically before it.
	input := `[{"alpha":"a","id":1,"zeta":"z"}]`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	lines := strings.Split(out, "\n")
	if len(lines) < 1 {
		t.Fatal("expected at least one line of output")
	}

	headerLine := lines[0]
	idIdx := strings.Index(headerLine, "id")
	alphaIdx := strings.Index(headerLine, "alpha")
	zetaIdx := strings.Index(headerLine, "zeta")

	if idIdx < 0 || alphaIdx < 0 || zetaIdx < 0 {
		t.Fatalf("expected all columns in header, got: %q", headerLine)
	}
	if idIdx >= alphaIdx {
		t.Errorf("'id' should come before 'alpha' in header, got: %q", headerLine)
	}
	if alphaIdx >= zetaIdx {
		t.Errorf("'alpha' should come before 'zeta' in header, got: %q", headerLine)
	}
}

func TestRenderNonJSONData(t *testing.T) {
	input := `plain text response`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	if !strings.Contains(out, "plain text response") {
		t.Errorf("non-JSON data should be passed through, got:\n%s", out)
	}
}

func TestRenderTableMissingFieldsInSomeRows(t *testing.T) {
	// When some objects have fields others don't, the table should still render
	input := `[{"id":1,"name":"A"},{"id":2,"extra":"value"}]`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	// Should contain all columns: id, extra, name
	if !strings.Contains(out, "id") {
		t.Errorf("should contain 'id' column, got:\n%s", out)
	}
	if !strings.Contains(out, "name") {
		t.Errorf("should contain 'name' column, got:\n%s", out)
	}
	if !strings.Contains(out, "extra") {
		t.Errorf("should contain 'extra' column, got:\n%s", out)
	}
}

func TestRenderMetaPartialFields(t *testing.T) {
	// meta with only some fields present
	input := `{"data":[{"id":1}],"meta":{"total":10}}`
	out := captureStdout(t, func() {
		Render([]byte(input), false)
	})

	if !strings.Contains(out, "Total: 10") {
		t.Errorf("should contain 'Total: 10', got:\n%s", out)
	}
	// Should NOT contain "Page" since current_page is not present
	lines := strings.Split(out, "\n")
	metaFound := false
	for _, line := range lines {
		if strings.Contains(line, "Total:") {
			metaFound = true
			if strings.Contains(line, "Page") {
				t.Errorf("should not contain 'Page' when current_page is absent, got: %q", line)
			}
		}
	}
	if !metaFound {
		t.Error("meta line with 'Total:' not found")
	}
}
