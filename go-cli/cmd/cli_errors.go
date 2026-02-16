package cmd

import (
	"fmt"
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
}

func (e *CLIError) Error() string {
	return e.Message
}

func (e *CLIError) CategoryName() string {
	return e.Category
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
// Falls back to ExitValidation (1) for unrecognized errors.
func exitCodeForError(err error) int {
	if err == nil {
		return ExitOK
	}
	if cliErr, ok := err.(*CLIError); ok {
		return cliErr.ExitCode
	}

	category := ""
	type categorized interface {
		CategoryName() string
	}
	if c, ok := err.(categorized); ok {
		category = c.CategoryName()
	}

	switch category {
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
