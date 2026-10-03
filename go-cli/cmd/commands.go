package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// `p202 commands` dumps the whole command tree in one call, so an agent can
// load the CLI's surface instead of walking it with dozens of --help calls.
// `p202 search` ranks the same records.

// commandTreeSchema versions the --json shape below.
const commandTreeSchema = 1

type commandTree struct {
	Schema      int           `json:"schema"`
	CLIVersion  string        `json:"cli_version,omitempty"`
	GlobalFlags []flagInfo    `json:"global_flags"`
	Commands    []commandInfo `json:"commands"`
}

type commandInfo struct {
	Path     string     `json:"path"`
	Use      string     `json:"use"`
	Aliases  []string   `json:"aliases,omitempty"`
	Short    string     `json:"short"`
	Long     string     `json:"long,omitempty"`
	Example  string     `json:"example,omitempty"`
	Runnable bool       `json:"runnable"`
	Flags    []flagInfo `json:"flags"`
}

type flagInfo struct {
	Name          string            `json:"name"`
	Shorthand     string            `json:"shorthand,omitempty"`
	Type          string            `json:"type"`
	Default       string            `json:"default"`
	Usage         string            `json:"usage"`
	AllowedValues []string          `json:"allowed_values,omitempty"`
	ValueAliases  map[string]string `json:"value_aliases,omitempty"`
	ValueList     bool              `json:"value_list,omitempty"` // comma-separated, each item one of allowed_values
	Required      bool              `json:"required"`
	Persistent    bool              `json:"persistent,omitempty"` // inherited by the command's subcommands
}

// buildCommandTree walks the tree under root. Hidden commands and flags,
// help, and flags inherited from a parent are left out; each command's own
// persistent flags are listed on it once, root's as global_flags. Cobra
// sorts commands and flags by name, so the order is stable.
func buildCommandTree(root *cobra.Command) commandTree {
	root.InitDefaultCompletionCmd()
	tree := commandTree{Schema: commandTreeSchema, CLIVersion: root.Version, GlobalFlags: []flagInfo{}, Commands: []commandInfo{}}
	root.PersistentFlags().VisitAll(func(f *pflag.Flag) {
		if keepFlag(f) {
			// Every command inherits these; listing them here is the once.
			tree.GlobalFlags = append(tree.GlobalFlags, describeFlag(f, false))
		}
	})
	var walk func(c *cobra.Command)
	walk = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			if !sub.IsAvailableCommand() {
				continue
			}
			tree.Commands = append(tree.Commands, describeCommand(sub))
			walk(sub)
		}
	}
	walk(root)
	return tree
}

func keepFlag(f *pflag.Flag) bool {
	return !f.Hidden && f.Name != "help"
}

func describeCommand(c *cobra.Command) commandInfo {
	info := commandInfo{
		Path:     c.CommandPath(),
		Use:      c.Use,
		Aliases:  c.Aliases,
		Short:    c.Short,
		Long:     strings.TrimSpace(c.Long),
		Example:  strings.TrimSpace(c.Example),
		Runnable: c.Runnable(),
		Flags:    []flagInfo{},
	}
	persistent := c.PersistentFlags()
	c.LocalFlags().VisitAll(func(f *pflag.Flag) {
		if keepFlag(f) {
			info.Flags = append(info.Flags, describeFlag(f, persistent.Lookup(f.Name) == f))
		}
	})
	return info
}

func describeFlag(f *pflag.Flag, persistent bool) flagInfo {
	info := flagInfo{
		Name:       f.Name,
		Shorthand:  f.Shorthand,
		Type:       f.Value.Type(),
		Default:    f.DefValue,
		Usage:      f.Usage,
		Required:   len(f.Annotations[cobra.BashCompOneRequiredFlag]) > 0 || strings.Contains(f.Usage, "(required)"),
		Persistent: persistent,
	}
	if spec := enumSpecs[f]; spec != nil {
		info.AllowedValues = spec.values
		info.ValueList = spec.list
		if len(spec.aliases) > 0 {
			info.ValueAliases = spec.aliases
		}
	}
	return info
}

// subtree keeps the commands at or under path ("p202 report").
func (t commandTree) subtree(path string) commandTree {
	out := t
	out.Commands = []commandInfo{}
	for _, c := range t.Commands {
		if c.Path == path || strings.HasPrefix(c.Path, path+" ") {
			out.Commands = append(out.Commands, c)
		}
	}
	return out
}

var commandsCmd = &cobra.Command{
	Use:   "commands [command...]",
	Short: "List every command and flag (with allowed values) in one call; --json for agents",
	Long: "Dumps the command tree: for each command its path, aliases, description, examples\n" +
		"and flags (name, shorthand, type, default, usage, allowed values, required). Global\n" +
		"flags are listed once under global_flags. Name a command to list only its subtree.\n" +
		"To find a command by what you want to do, use `p202 search <words>`.",
	Example: "  p202 commands --json\n  p202 commands report\n  p202 commands --quiet",
	RunE: func(cmd *cobra.Command, args []string) error {
		if csvOutput {
			return validationError("p202 commands has no CSV form").WithHint("Use --json (one document), --ndjson (one command per line) or --quiet (paths only).")
		}
		tree := buildCommandTree(cmd.Root())
		words := strings.Fields(strings.Join(args, " "))
		if len(words) > 0 && words[0] == cmd.Root().Name() {
			words = words[1:]
		}
		if len(words) > 0 {
			target, rest, err := cmd.Root().Find(words)
			if err != nil || len(rest) > 0 || target == cmd.Root() {
				return validationError("no command %q", strings.Join(words, " ")).
					WithHint("Run `p202 commands` for every command, or `p202 search %s` to find one by what it does.", strings.Join(words, " "))
			}
			tree = tree.subtree(target.CommandPath())
		}
		return writeCommandTree(os.Stdout, tree)
	},
}

func writeCommandTree(w io.Writer, tree commandTree) error {
	switch {
	case jsonOutput:
		return writeJSONNoEscape(w, tree)
	case ndjsonOutput:
		if err := writeJSONNoEscape(w, map[string]interface{}{"global_flags": tree.GlobalFlags}); err != nil {
			return err
		}
		for _, c := range tree.Commands {
			if err := writeJSONNoEscape(w, c); err != nil {
				return err
			}
		}
		return nil
	case quietOutput:
		for _, c := range tree.Commands {
			fmt.Fprintln(w, c.Path)
		}
		return nil
	}
	var globals []string
	for _, f := range tree.GlobalFlags {
		globals = append(globals, flagLabel(f))
	}
	fmt.Fprintf(w, "Global flags: %s\n\n", strings.Join(globals, ", "))
	for _, c := range tree.Commands {
		depth := strings.Count(c.Path, " ") - 1
		indent := strings.Repeat("  ", depth)
		name := c.Path[strings.LastIndex(c.Path, " ")+1:]
		if len(c.Aliases) > 0 {
			name += " (" + strings.Join(c.Aliases, ", ") + ")"
		}
		fmt.Fprintf(w, "%s%s — %s\n", indent, name, c.Short)
		if len(c.Flags) > 0 {
			var names []string
			for _, f := range c.Flags {
				names = append(names, flagLabel(f))
			}
			fmt.Fprintf(w, "%s    %s\n", indent, strings.Join(names, " "))
		}
	}
	fmt.Fprintln(w, "\n`p202 <command> --help` shows a command's flags in full; `p202 commands --json` has them all.")
	return nil
}

func flagLabel(f flagInfo) string {
	label := "--" + f.Name
	if f.Shorthand != "" {
		label = "-" + f.Shorthand + "/" + label
	}
	return label
}

// writeJSONNoEscape writes v as indented JSON without HTML escaping, so
// usage text with <placeholders> reads as written.
func writeJSONNoEscape(w io.Writer, v interface{}) error {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	if jsonOutput {
		enc.SetIndent("", "  ")
	}
	return enc.Encode(v)
}

func init() {
	rootCmd.AddCommand(commandsCmd)
}
