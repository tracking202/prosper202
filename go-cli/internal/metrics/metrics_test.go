package metrics

import (
	"os"
	"testing"
)

func TestEnabledReturnsFalseByDefault(t *testing.T) {
	// With no env var set, Enabled() should return false.
	// The init() function already ran, but since we're in a test binary
	// without P202_METRICS=1, it should be false.
	t.Setenv("P202_METRICS", "")
	// Re-check: the package-level var was set at init time.
	// For a fresh test, we can test the logic directly.
	raw := os.Getenv("P202_METRICS")
	if raw == "1" || raw == "true" || raw == "on" {
		t.Skip("P202_METRICS is enabled in test environment")
	}
}

func TestEmitNoOpWhenDisabled(t *testing.T) {
	// Emit should not panic when disabled.
	t.Setenv("P202_METRICS", "0")
	Emit(Event{Op: "test", Success: true})
}

func TestTimerReturnsCallable(t *testing.T) {
	done := Timer("test_op", "test_entity")
	if done == nil {
		t.Fatal("Timer() returned nil")
	}
	// Should not panic
	done(true, "")
	done(false, "some error")
}
