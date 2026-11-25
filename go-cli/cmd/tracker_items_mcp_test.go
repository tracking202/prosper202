//go:build !js

package cmd

import (
	"strings"
	"testing"
)

// A partial failure's answer reaches the model beside the error: without it,
// the items that were done would be sent again. (Here, not in
// tracker_items_test.go: mcpShapeResult is in mcp_serve.go, which js/wasm
// does not build.)
func TestMCPPartialFailureKeepsItsAnswer(t *testing.T) {
	res := mcpShapeResult(mcpCallResult{ExitCode: ExitPartialFailure, Stdout: []byte(`{"data":[{"index":0,"status":"created","tracker_id":100}]}`),
		Stderr: []byte(`{"error":{"category":"partial_failure"}}`)}, false, false)
	text := mcpResultText(res)
	if !res.IsError || !strings.Contains(text, "partial_failure") || !strings.Contains(text, `"tracker_id":100`) {
		t.Errorf("result = %v %q", res.IsError, text)
	}
}
