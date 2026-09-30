package cmd

import (
	"sort"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// A flag that takes one value from a fixed set is declared with enumFlag.
// Its help text and its invalid-value error are both generated from the
// enumSpec that validation reads, so the three cannot drift, and the root
// PersistentPreRunE checks every such flag the caller set before any
// command runs (so before any client is built). `p202 commands --json`
// reports the list as allowed_values.

// binaryValues is the value set of a 0/1 switch flag.
var binaryValues = []string{"0", "1"}

// enumSpec is the set of values a flag accepts.
type enumSpec struct {
	values  []string          // accepted values, in display order
	aliases map[string]string // extra spellings -> the value they mean
	fold    bool              // match case-insensitively (only where the command normalizes case itself)
	list    bool              // a comma-separated list, each item one of values
	hint    string            // recovery step printed with an invalid value
	// server is a /capabilities path whose list, when the server sends
	// one, is authoritative for a value the built-in list lacks.
	server []string
}

type enumOption func(*enumSpec)

// enumAliases accepts extra spellings. Entries whose target is not one of
// the values (a shared alias table) and identity entries are skipped.
func enumAliases(m map[string]string) enumOption {
	return func(s *enumSpec) {
		for alias, target := range m {
			if alias != target && containsString(s.values, target) && !containsString(s.values, alias) {
				if s.aliases == nil {
					s.aliases = map[string]string{}
				}
				s.aliases[alias] = target
			}
		}
	}
}

func enumFoldCase() enumOption { return func(s *enumSpec) { s.fold = true } }
func enumList() enumOption     { return func(s *enumSpec) { s.list = true } }

func enumHint(hint string) enumOption { return func(s *enumSpec) { s.hint = hint } }

func enumServerList(path ...string) enumOption {
	return func(s *enumSpec) { s.server = path }
}

// sortedKeys lists a map's keys in order, for a value set held as a map.
func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func newEnum(values []string, opts ...enumOption) *enumSpec {
	s := &enumSpec{values: values}
	for _, o := range opts {
		o(s)
	}
	return s
}

// enumSpecs maps each enum flag to its spec. Keyed by the flag itself, so
// a spec reached through an inherited flag set is still found.
var enumSpecs = map[*pflag.Flag]*enumSpec{}

// enumFlag declares that --name on cmd takes a value from spec. The usage
// text gets the list: in place of "{values}" when the usage has it,
// otherwise appended after a colon.
func enumFlag(cmd *cobra.Command, name string, spec *enumSpec) {
	f := cmd.Flags().Lookup(name)
	if f == nil {
		panic("enumFlag: " + cmd.CommandPath() + " has no flag --" + name)
	}
	if strings.Contains(f.Usage, "{values}") {
		f.Usage = strings.ReplaceAll(f.Usage, "{values}", spec.describe())
	} else {
		f.Usage += ": " + spec.describe()
	}
	enumSpecs[f] = spec
}

// describe lists the values, then the aliases, for help and errors.
func (s *enumSpec) describe() string {
	out := strings.Join(s.values, ", ")
	if len(s.aliases) > 0 {
		out += " (aliases: " + strings.Join(s.aliasPairs(), ", ") + ")"
	}
	if s.list {
		out += "; comma-separated for several"
	}
	return out
}

func (s *enumSpec) aliasPairs() []string {
	pairs := make([]string, 0, len(s.aliases))
	for alias, target := range s.aliases {
		pairs = append(pairs, alias+"="+target)
	}
	sort.Strings(pairs)
	return pairs
}

// resolve returns the value v means, and whether it is accepted.
func (s *enumSpec) resolve(v string) (string, bool) {
	v = strings.TrimSpace(v)
	for _, allowed := range s.values {
		if v == allowed || (s.fold && strings.EqualFold(v, allowed)) {
			return allowed, true
		}
	}
	for alias, target := range s.aliases {
		if v == alias || (s.fold && strings.EqualFold(v, alias)) {
			return target, true
		}
	}
	return v, false
}

// enumServerValues fetches the server's list at a /capabilities path. It is
// a variable so tests can stand in for the server.
var enumServerValues = func(path []string) ([]string, bool) {
	c, err := api.NewFromConfig()
	if err != nil {
		return nil, false
	}
	raw, ok := c.Capability(path...)
	if !ok {
		return nil, false
	}
	items, ok := raw.([]interface{})
	if !ok || len(items) == 0 {
		return nil, false
	}
	values := make([]string, 0, len(items))
	for _, item := range items {
		str, ok := item.(string)
		if !ok {
			return nil, false
		}
		values = append(values, str)
	}
	return values, true
}

// check returns nil when raw is accepted for --flag, else a validation
// error whose message lists every accepted value. An empty value is left
// to refuseEmptyStringFlags.
func (s *enumSpec) check(flag, raw string) *CLIError {
	items := []string{raw}
	if s.list {
		items = strings.Split(raw, ",")
	}
	for _, item := range items {
		if strings.TrimSpace(item) == "" {
			continue
		}
		if _, ok := s.resolve(item); ok {
			continue
		}
		// Built-in list first, so a typo fails without a server. Only
		// then may the server's own list vouch for the value.
		if len(s.server) > 0 {
			if remote, ok := enumServerValues(s.server); ok {
				if containsString(remote, strings.TrimSpace(item)) {
					continue
				}
				served := newEnum(remote, enumAliases(s.aliases))
				served.list, served.hint = s.list, s.hint
				return served.invalid(flag, item, " (this server's list)")
			}
		}
		return s.invalid(flag, item, "")
	}
	return nil
}

func (s *enumSpec) invalid(flag, item, source string) *CLIError {
	hint := s.hint
	if hint == "" {
		hint = "If none of these fits, `p202 search <what you want to do>` finds commands by task."
	}
	if s.list {
		return validationError("--%s takes a comma-separated list of%s: %s; got %q", flag, source, s.describe(), strings.TrimSpace(item)).WithHint("%s", hint)
	}
	return validationError("--%s must be one of%s: %s; got %q", flag, source, s.describe(), strings.TrimSpace(item)).WithHint("%s", hint)
}

// enumValue returns the value the caller gave --name, resolved through
// aliases and case folding; "" when unset. Validation already ran in
// PersistentPreRunE, so an unresolvable value (a server-only one) is
// returned trimmed, as given.
func enumValue(cmd *cobra.Command, name string) string {
	f := cmd.Flags().Lookup(name)
	if f == nil {
		return ""
	}
	v := strings.TrimSpace(f.Value.String())
	if spec := enumSpecs[f]; spec != nil && v != "" {
		if resolved, ok := spec.resolve(v); ok {
			return resolved
		}
	}
	return v
}

// refuseInvalidEnumFlags returns the error for the first enum flag (in name
// order) the caller set to a value outside its list.
func refuseInvalidEnumFlags(cmd *cobra.Command) error {
	var bad *CLIError
	cmd.Flags().Visit(func(f *pflag.Flag) {
		if bad != nil || !f.Changed {
			return
		}
		if spec := enumSpecs[f]; spec != nil {
			bad = spec.check(f.Name, f.Value.String())
		}
	})
	if bad == nil {
		return nil
	}
	return bad
}
