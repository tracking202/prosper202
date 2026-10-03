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
//
// duration_ms carries no omitempty on purpose: an operation that finishes inside
// a millisecond has a genuine duration of 0, and dropping the field left log
// consumers unable to tell "completed instantly" from "never measured".
type Event struct {
	Op       string            `json:"op"`
	Entity   string            `json:"entity,omitempty"`
	Action   string            `json:"action,omitempty"`
	Duration float64           `json:"duration_ms"`
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

// appendTimestamp returns a copy of fields carrying the emission timestamp.
// It must not write into the caller's map: Emit takes Event by value but the
// Fields map is shared with the caller, so stamping it in place mutated data the
// caller still owns — and would be an unsynchronized map write if that caller
// built the event on one of the worker goroutines.
func appendTimestamp(fields map[string]string) map[string]string {
	out := make(map[string]string, len(fields)+1)
	for k, v := range fields {
		out[k] = v
	}
	out["ts"] = time.Now().UTC().Format(time.RFC3339)
	return out
}
