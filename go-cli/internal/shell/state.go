package shell

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"
)

// State holds session variables for the interactive shell.
// Variables store raw JSON output from commands for later reference.
// It is not safe for concurrent use: the shell executes commands
// sequentially on a single goroutine.
type State struct {
	vars map[string]json.RawMessage
}

// NewState creates an empty session state.
func NewState() *State {
	return &State{
		vars: make(map[string]json.RawMessage),
	}
}

// Set stores a variable. The name should not include the $ prefix.
func (s *State) Set(name string, value json.RawMessage) {
	s.vars[name] = value
}

// SetLast stores the special $_ variable (last command output).
func (s *State) SetLast(value json.RawMessage) {
	s.Set("_", value)
}

// Get retrieves a variable by name (without $ prefix).
// Returns nil and false if the variable doesn't exist.
func (s *State) Get(name string) (json.RawMessage, bool) {
	v, ok := s.vars[name]
	return v, ok
}

// Delete removes a variable. Returns true if it existed.
func (s *State) Delete(name string) bool {
	if _, ok := s.vars[name]; ok {
		delete(s.vars, name)
		return true
	}
	return false
}

// Names returns sorted variable names.
func (s *State) Names() []string {
	names := make([]string, 0, len(s.vars))
	for k := range s.vars {
		names = append(names, k)
	}
	sort.Strings(names)
	return names
}

// Count returns the number of stored variables.
func (s *State) Count() int {
	return len(s.vars)
}

// FormatVarsList returns a human-readable listing of all variables.
func (s *State) FormatVarsList() string {
	if len(s.vars) == 0 {
		return "No variables stored.\n"
	}
	var b strings.Builder
	for _, name := range s.Names() {
		raw := s.vars[name]
		preview := string(raw)
		if len(preview) > 80 {
			preview = preview[:77] + "..."
		}
		preview = strings.ReplaceAll(preview, "\n", " ")
		fmt.Fprintf(&b, "$%s = %s\n", name, preview)
	}
	return b.String()
}
