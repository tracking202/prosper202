package cmd

// commandMeta holds agent-friendly metadata for a command that cannot be
// auto-extracted from Cobra's flag definitions.
type commandMeta struct {
	Examples     []string
	OutputFields []string
	Related      []string
	// AllowedValues maps flag names to their valid values for enum-like flags.
	AllowedValues map[string][]string
	// Mutating indicates this command modifies state (supports --dry-run).
	Mutating bool
}

// agentMeta maps fully-qualified command paths (e.g. "campaign list") to their metadata.
var agentMeta = map[string]*commandMeta{}

// registerMeta associates agent-friendly metadata with a command path.
// Call this in init() alongside flag registration.
func registerMeta(cmdPath string, meta commandMeta) {
	agentMeta[cmdPath] = &meta
}
