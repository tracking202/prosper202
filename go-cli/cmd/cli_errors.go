package cmd

import (
	"errors"
	"fmt"
	"strings"

	"p202/internal/api"
)

// Exit codes for automation compatibility.
// These follow a convention where different error categories produce
// distinct exit codes so scripts can differentiate failure types.
const (
	ExitOK             = 0
	ExitValidation     = 1 // bad input, missing flags, invalid args
	ExitAuth           = 2 // authentication or authorization failure
	ExitNetwork        = 3 // connection timeout, DNS failure
	ExitServer         = 4 // API returned 5xx
	ExitPartialFailure = 5 // bulk operation with some successes and some failures
)

// CLIError is a structured error for CLI-level failures.
// It carries a category for the error taxonomy and an optional
// exit code override for automation.
type CLIError struct {
	Category string // "validation", "auth", "network", "server", "partial_failure"
	Message  string
	ExitCode int
	// Hint is the recovery step an operator or agent should take next. It
	// is printed after the message and carried in the JSON error envelope.
	Hint string
	// Cause is the underlying error, when there is one: errors.Is and
	// errors.As reach it through Unwrap, while the category, exit code and
	// hint stay this error's.
	Cause error
}

func (e *CLIError) Error() string {
	return e.Message
}

// Unwrap returns the underlying error (nil when there is none).
func (e *CLIError) Unwrap() error {
	return e.Cause
}

func (e *CLIError) CategoryName() string {
	return e.Category
}

// HintText implements api.Hinted so HintFor surfaces the hint.
func (e *CLIError) HintText() string {
	return e.Hint
}

// WithHint attaches a recovery hint and returns the error for chaining:
//
//	return validationError("--tracker is required").WithHint("run `p202 tracker list` to find ids")
func (e *CLIError) WithHint(format string, args ...interface{}) *CLIError {
	e.Hint = fmt.Sprintf(format, args...)
	return e
}

// hintedError wraps any error with a recovery hint while preserving the
// wrapped error's category and exit code (errors.As sees through it).
type hintedError struct {
	err  error
	hint string
}

func (e *hintedError) Error() string    { return e.err.Error() }
func (e *hintedError) Unwrap() error    { return e.err }
func (e *hintedError) HintText() string { return e.hint }

// withHint attaches a recovery hint to an existing error (typically one
// that wraps an API error with %w). Returns nil for a nil error.
func withHint(err error, format string, args ...interface{}) error {
	if err == nil {
		return nil
	}
	return &hintedError{err: err, hint: fmt.Sprintf(format, args...)}
}

// validationError creates a CLI validation error (bad input, missing flags, etc.).
func validationError(format string, args ...interface{}) *CLIError {
	return &CLIError{
		Category: "validation",
		Message:  fmt.Sprintf(format, args...),
		ExitCode: ExitValidation,
	}
}

// partialFailureError creates an error indicating a bulk operation had some failures.
func partialFailureError(format string, args ...interface{}) *CLIError {
	return &CLIError{
		Category: "partial_failure",
		Message:  fmt.Sprintf(format, args...),
		ExitCode: ExitPartialFailure,
	}
}

// errString safely converts an error to a string, returning "" for nil.
func errString(err error) string {
	if err == nil {
		return ""
	}
	return err.Error()
}

// exitCodeForError returns the appropriate exit code for an error.
// Falls back to ExitValidation (1) for unrecognized errors. Wrapped errors
// (fmt.Errorf with %w, withHint) keep the code of the error they wrap, so
// "fetching data: API error (401)" still exits 2, not 1.
func exitCodeForError(err error) int {
	if err == nil {
		return ExitOK
	}
	var cliErr *CLIError
	if errors.As(err, &cliErr) {
		return cliErr.ExitCode
	}

	switch api.ErrorCategory(err) {
	case "auth":
		return ExitAuth
	case "network":
		return ExitNetwork
	case "server":
		return ExitServer
	case "partial_failure":
		return ExitPartialFailure
	default:
		return ExitValidation
	}
}

// activeCommandPath is the full path ("p202 forecast") of the command that
// ran, recorded by the root PersistentPreRun so error output can name it;
// recoverCommandContext fills it in when Cobra fails before that runs.
var activeCommandPath string

// errorEnvelope builds the machine-readable error record emitted on stderr
// under --json/--ndjson: everything an agent needs to decide what to do next
// without parsing prose.
func errorEnvelope(err error) map[string]interface{} {
	env := map[string]interface{}{
		"message":   err.Error(),
		"exit_code": exitCodeForError(err),
	}
	if category := api.ErrorCategory(err); category != "" {
		env["category"] = category
	} else {
		env["category"] = "validation"
	}
	if hint := hintFor(err); hint != "" {
		env["hint"] = hint
	}
	if activeCommandPath != "" {
		env["command"] = activeCommandPath
	}
	var apiErr *api.APIError
	if errors.As(err, &apiErr) {
		env["http_status"] = apiErr.Status
		if len(apiErr.FieldErrors) > 0 {
			env["field_errors"] = apiErr.FieldErrors
		}
	}
	return map[string]interface{}{"error": env}
}

// unknownInputHint answers Cobra's and pflag's errors for a command or
// flag that does not exist: the caller guessed a name, so point at the
// list and at search, which finds a command by what it does.
func unknownInputHint(err error) string {
	msg := err.Error()
	path := strings.TrimSpace(activeCommandPath)
	if path == "" {
		path = "p202"
	}
	switch {
	case strings.HasPrefix(msg, "unknown command "):
		return fmt.Sprintf("Run `%s --help` for its commands, or `p202 search <what you want to do>` to find one by task (`p202 commands --json` lists them all).", path)
	case strings.HasPrefix(msg, "unknown flag: "), strings.HasPrefix(msg, "unknown shorthand flag: "):
		return fmt.Sprintf("Run `%s --help` for the flags it accepts, or `p202 search <what you want to do>` to find the command that has the one you need.", path)
	}
	return ""
}

// hintFor returns the recovery hint for an error: an explicit hint attached
// by the command or the API layer, else a generic pointer to --help for
// validation errors from a known command.
func hintFor(err error) string {
	if hint := api.HintFor(err); hint != "" {
		return siblingCommandHint(hint)
	}
	if hint := unknownInputHint(err); hint != "" {
		return hint
	}
	if activeCommandPath != "" && exitCodeForError(err) == ExitValidation && api.ErrorCategory(err) != "auth" {
		return fmt.Sprintf("Run `%s --help` for the flags this command accepts.", strings.TrimSpace(activeCommandPath))
	}
	return ""
}

// siblingCommandHint names the command a class-wide hint leaves as "the
// matching `... list`" (or `... update`): the failing command's sibling, when
// the tree has one under that name. `p202 campaign get 9` that answers 404
// says `p202 campaign list`; a command whose group has no such sibling keeps
// the generic words rather than naming a command that does not exist.
func siblingCommandHint(hint string) string {
	if activeCommandPath == "" || !strings.Contains(hint, "`... ") {
		return hint
	}
	parts := strings.Fields(activeCommandPath)
	if len(parts) < 3 {
		return hint
	}
	group, rest, err := rootCmd.Find(parts[1 : len(parts)-1])
	if err != nil || group == nil || len(rest) != 0 {
		return hint
	}
	for _, verb := range []string{"list", "update"} {
		for _, sub := range group.Commands() {
			if sub.Name() == verb {
				named := "`" + sub.CommandPath() + "`"
				hint = strings.ReplaceAll(hint, "the matching `... "+verb+"`", named)
				hint = strings.ReplaceAll(hint, "`... "+verb+"`", named)
				break
			}
		}
	}
	return hint
}
