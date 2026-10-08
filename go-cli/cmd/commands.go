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
	// NotInCLI are the web UI pages (and tasks) no command does, with where
	// they are done instead; see searchTasks.
	NotInCLI []taskInfo `json:"not_in_cli,omitempty"`
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
	// Tasks are the command lines this command runs for a task, with the
	// web UI pages that do the same and the words people use for it.
	Tasks []taskInfo `json:"tasks,omitempty"`
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
	tree.NotInCLI = tasksNotInCLI()
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
		Tasks:    tasksFor(c.CommandPath()),
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
	out.NotInCLI = nil // pages with no command sit under none
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
		"flags are listed once under global_flags. Name a command to list only its subtree.\n\n" +
		"--brief is the catalog an agent reads to choose a command: every command on one\n" +
		"line (its path, summary and the web UI page it does, with that page's command line),\n" +
		"and the pages no command does; about 6,000 tokens. It prints as text even where an\n" +
		"agent would get JSON by default; --json gives it as JSON. Then `p202 <command> --help`\n" +
		"for the flags. To rank commands by your words instead, use `p202 search <words>`.",
	Example: "  p202 commands --brief\n  p202 commands --json\n  p202 commands report\n  p202 commands --quiet",
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
		if brief, _ := cmd.Flags().GetBool("brief"); brief && !quietOutput {
			return writeBriefCatalog(os.Stdout, tree)
		}
		return writeCommandTree(os.Stdout, tree)
	},
}

// briefCommand is a command as the --brief catalog lists it.
type briefCommand struct {
	Command string      `json:"command"`
	Summary string      `json:"summary"`
	UI      []briefPage `json:"ui,omitempty"`
}

type briefPage struct {
	Page string `json:"page"`
	Run  string `json:"run,omitempty"` // the page's command line, when it is not the bare command
}

type briefElsewhere struct {
	Page    string `json:"page"`
	Instead string `json:"instead"`
	Hint    string `json:"hint,omitempty"`
}

// writeBriefCatalog is --brief: one line per command an agent can run, and
// the pages no command does. An agent that reads it chooses better than any
// keyword ranking: on 30 phrasings written before anything was tuned, a
// model given only this catalog chose the right command first for all 30,
// where the best keyword search found 16 (search_rank.go). At this CLI's
// size the catalog costs about 6,000 tokens, where `commands --json` is
// about 120,000; Cloudflare's cf, at 2,900 commands, cannot hand an agent
// its catalog, which is why it searches.
func writeBriefCatalog(w io.Writer, tree commandTree) error {
	var cmds []briefCommand
	for _, c := range tree.Commands {
		if !c.Runnable {
			continue
		}
		b := briefCommand{Command: c.Path, Summary: c.Short}
		for _, t := range c.Tasks {
			for _, p := range t.UIPages {
				page := briefPage{Page: p}
				if t.Run != c.Path {
					page.Run = t.Run
				}
				b.UI = append(b.UI, page)
			}
		}
		cmds = append(cmds, b)
	}
	var elsewhere []briefElsewhere
	for _, t := range tree.NotInCLI {
		name := ""
		if len(t.UIPages) > 0 {
			name = t.UIPages[0]
		} else if len(t.Phrases) > 0 {
			name = t.Phrases[0]
		}
		elsewhere = append(elsewhere, briefElsewhere{Page: name, Instead: t.Instead, Hint: t.Hint})
	}
	// The catalog is read by a model, so an agent gets the text even where
	// it would get JSON by default: the same catalog as one-line JSON is a
	// quarter longer (31,384 bytes against 24,961), the difference all keys
	// and quotes. --json, --ndjson, P202_OUTPUT or a config default still
	// give JSON, for a program that parses it.
	asJSON := (jsonOutput || ndjsonOutput) && !strings.HasPrefix(outputSource, sourceAgent)
	switch {
	case asJSON && ndjsonOutput:
		for _, c := range cmds {
			if err := writeJSONNoEscape(w, c); err != nil {
				return err
			}
		}
		for _, e := range elsewhere {
			if err := writeJSONNoEscape(w, map[string]interface{}{"not_in_cli": e}); err != nil {
				return err
			}
		}
		return nil
	case asJSON:
		if cmds == nil {
			cmds = []briefCommand{}
		}
		if elsewhere == nil {
			elsewhere = []briefElsewhere{}
		}
		return writeJSONNoEscape(w, map[string]interface{}{"commands": cmds, "not_in_cli": elsewhere})
	}
	for _, c := range cmds {
		line := c.Command + " — " + c.Summary
		if len(c.UI) > 0 {
			var pages []string
			for _, p := range c.UI {
				if p.Run != "" {
					pages = append(pages, p.Page+" = "+p.Run)
				} else {
					pages = append(pages, p.Page)
				}
			}
			line += " [UI: " + strings.Join(pages, "; ") + "]"
		}
		fmt.Fprintln(w, line)
	}
	for _, e := range elsewhere {
		line := "(no command) " + e.Page + " — " + e.Instead
		if e.Hint != "" {
			line += ". " + e.Hint
		}
		fmt.Fprintln(w, line)
	}
	return nil
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
		if len(tree.NotInCLI) > 0 {
			return writeJSONNoEscape(w, map[string]interface{}{"not_in_cli": tree.NotInCLI})
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

// writeJSONNoEscape writes v as JSON without HTML escaping, so usage text
// with <placeholders> reads as written: indented for --json, on one line for
// --ndjson and for an agent that gets JSON by default (compactJSON), as every
// other command prints it.
func writeJSONNoEscape(w io.Writer, v interface{}) error {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	if jsonOutput && !compactJSON {
		enc.SetIndent("", "  ")
	}
	return enc.Encode(v)
}

func init() {
	commandsCmd.Flags().Bool("brief", false, "One line per command (path, summary, the UI page it does): the catalog an agent reads to choose a command")
	rootCmd.AddCommand(commandsCmd)
}
