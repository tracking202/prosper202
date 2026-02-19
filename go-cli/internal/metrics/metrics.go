// Package metrics provides lightweight structured telemetry for CLI operations.
// Events are emitted as single-line JSON to stderr when P202_METRICS=1.
// This is designed for log aggregation in CI/CD and automation contexts.
package metrics

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"
)

var enabled bool

func init() {
	raw := strings.TrimSpace(strings.ToLower(os.Getenv("P202_METRICS")))
	enabled = raw == "1" || raw == "true" || raw == "on"
}

// Enabled returns whether metrics emission is active.
func Enabled() bool {
	return enabled
}

// Event represents a single telemetry event.
type Event struct {
	Op       string            `json:"op"`
	Entity   string            `json:"entity,omitempty"`
	Action   string            `json:"action,omitempty"`
	Duration float64           `json:"duration_ms,omitempty"`
	Count    int               `json:"count,omitempty"`
	Success  bool              `json:"success"`
	Error    string            `json:"error,omitempty"`
	Fields   map[string]string `json:"fields,omitempty"`
}

// Emit writes a metrics event to stderr as JSON.
// No-op if P202_METRICS is not enabled.
func Emit(e Event) {
	if !enabled {
		return
	}
	e.Fields = appendTimestamp(e.Fields)
	data, err := json.Marshal(e)
	if err != nil {
		return
	}
	fmt.Fprintf(os.Stderr, "[metrics] %s\n", string(data))
}

// Timer starts a timer and returns a function that emits the elapsed duration.
// Usage:
//
//	done := metrics.Timer("diff", "rotators")
//	// ... do work ...
//	done(true, "")
func Timer(op, entity string) func(success bool, errMsg string) {
	start := time.Now()
	return func(success bool, errMsg string) {
		Emit(Event{
			Op:       op,
			Entity:   entity,
			Duration: float64(time.Since(start).Milliseconds()),
			Success:  success,
			Error:    errMsg,
		})
	}
}

func appendTimestamp(fields map[string]string) map[string]string {
	if fields == nil {
		fields = map[string]string{}
	}
	fields["ts"] = time.Now().UTC().Format(time.RFC3339)
	return fields
}
