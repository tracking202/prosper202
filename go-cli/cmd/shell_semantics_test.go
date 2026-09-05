package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"testing"

	"p202/internal/shell"
)

func osStdout() *os.File { return os.Stdout }

func print_(s string) { fmt.Print(s) }

// $_ is documented as the last result. After a command that produced no output
// it used to retain the previous command's value and report it as current.
func TestLastResultIsClearedWhenACommandProducesNoOutput(t *testing.T) {
	state := shell.NewState()

	storeResult(state, []byte(`{"data":[{"id":1}]}`))
	first, ok := state.Get("_")
	if !ok || !strings.Contains(string(first), `"id"`) {
		t.Fatalf("first result not stored: %q", first)
	}

	storeResult(state, nil)
	after, ok := state.Get("_")
	if !ok {
		t.Fatal("$_ should still exist after a command with no output")
	}
	if strings.Contains(string(after), `"id"`) {
		t.Fatalf("$_ still holds the previous command's output: %q", after)
	}
	var parsed interface{}
	if err := json.Unmarshal(after, &parsed); err != nil {
		t.Fatalf("$_ should be valid JSON, got %q", after)
	}
	if parsed != nil {
		t.Fatalf("$_ = %v, want null", parsed)
	}
}

// captureStdout must restore os.Stdout even when the wrapped function panics;
// otherwise every later command in the shell session writes into a closed pipe.
func TestCaptureStdoutRestoresStdoutOnPanic(t *testing.T) {
	before := osStdout()

	func() {
		defer func() {
			if recover() == nil {
				t.Error("panic should propagate to the caller")
			}
		}()
		captureStdout(func() { panic("boom") })
	}()

	if osStdout() != before {
		t.Fatal("os.Stdout was not restored after a panic")
	}

	// And capturing must still work afterwards.
	got := captureStdout(func() { print_("still works") })
	if strings.TrimSpace(string(got)) != "still works" {
		t.Fatalf("capture broken after panic: %q", got)
	}
}
