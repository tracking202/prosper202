package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strings"
	"unicode/utf8"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// The MCP tool catalogue: a curated list of CLI commands, one tool each.
// `p202 mcp` (stdio, on the user's computer) and the hosted relay (this CLI
// built for js/wasm, run per call) both serve it, and both run a call the
// same way: `p202 mcp call <tool> --input -` with the JSON arguments on
// stdin, which builds the command line with mcpToolArgv and runs it. A
// tool's input schema is generated from its command's flags, so the two
// cannot drift.

// mcpCatalogSchema versions the `p202 mcp tools` JSON.
const mcpCatalogSchema = 1

// mcpMaxResultChars bounds what one tool call returns. Claude Code warns
// past 10,000 tokens and refuses past 25,000 by default; JSON runs about
// three characters a token. A larger answer is refused with how to ask for
// less, never cut short (a cut-off list reads as the whole list).
const mcpMaxResultChars = 60000

// mcpMaxInputBytes bounds one call's JSON arguments.
const mcpMaxInputBytes = 256 * 1024

type mcpKind string

const (
	mcpRead   mcpKind = "read"
	mcpCreate mcpKind = "create"
	mcpUpdate mcpKind = "update" // changes or replaces what is there
	mcpDelete mcpKind = "delete"
)

var (
	// A record id as the list commands show it. Ids go into the request's
	// path, so nothing but digits may (a model reads click keywords and
	// referers, which anyone can write).
	mcpNumericID = regexp.MustCompile(`^[0-9]{1,20}$`)
	mcpChangeID  = regexp.MustCompile(`^chg_[A-Za-z0-9]{1,64}$`)
	// ppc_network_id's report filters also take none, for the clicks with
	// no traffic source.
	mcpNumericOrNone = regexp.MustCompile(`^(?:[0-9]{1,20}|none)$`)
)

// mcpIDFlagPattern is what an *_id flag takes. An id goes into a filter or
// a request path (report_breakeven's into campaigns/<id>), so a model can
// send nothing else there.
func mcpIDFlagPattern(property string) (*regexp.Regexp, string) {
	if property == "ppc_network_id" {
		return mcpNumericOrNone, "a numeric id, or none"
	}
	return mcpNumericID, "a numeric id"
}

// mcpArg is a positional argument of a tool's command, in order.
type mcpArg struct {
	Name        string
	Description string
	// Required is false only where the command takes the value or a flag
	// instead (a delete takes an id or --ids).
	Required bool
	Pattern  *regexp.Regexp
	// Form says what Pattern accepts, in the refusal.
	Form string
}

func idArg(name, description string, required bool) mcpArg {
	return mcpArg{Name: name, Description: description, Required: required, Pattern: mcpNumericID, Form: "a numeric id"}
}

func changeArg() mcpArg {
	return mcpArg{Name: "change_id", Description: "Change id (chg_..., from change_list).", Required: true, Pattern: mcpChangeID, Form: "a change id such as chg_1a2b3c"}
}

type mcpToolSpec struct {
	Name    string   // tool name: letters, digits and underscores
	Command []string // the command's path below p202
	Title   string
	Kind    mcpKind
	Args    []mcpArg
	// Omit lists flags of the command the tool does not take, beyond
	// mcpOmittedFlags.
	Omit []string
	// Required lists flags the command insists on in its own code, which
	// cobra's annotation (and so the generated schema) does not know.
	Required []string
	// Note is added to the command's own summary in the description.
	Note string
	// NotStaged: the tool acts on proposed changes themselves, so a server
	// that only proposes (--staged) does not offer it. Applying a proposal
	// is a person's decision; a model that could stage a write and then
	// apply it would make --staged mean nothing (CLAUDE.md #14).
	NotStaged bool
	// NotHosted: the tool reads an area the keys made for the hosted MCP
	// server do not cover (AiConnect::READ_AREAS in api/v3), so that server
	// does not offer it (mcpCatalog.HostedExcludes).
	NotHosted bool
	// HostedOmit lists flags the hosted server leaves out of the tool, beyond
	// Omit: a hosted call reads a bounded amount of the install's answers,
	// so a flag that reads every page can only be refused there. The
	// catalogue names them (mcpCatalog.HostedOmits) for the relay, which
	// lists the tools from a native build, and a hosted run refuses them.
	HostedOmit []string
}

// mcpHostedRun is true in the hosted relay's engine (the js/wasm build,
// hostedBuild); a variable so the native tests can run as the engine does.
var mcpHostedRun = hostedBuild

// mcpEveryPage is HostedOmit for a list whose --all reads every page: the
// rows it could return under mcpMaxResultChars are a page or two of --limit
// and --offset, and more is refused. The flags that read every page to
// answer what one page cannot (--url-contains, --min-clicks: a filter;
// --with-stats, --resolve-names: a join) stay, and answer where the
// install's answers fit the hosted read limit.
var mcpEveryPage = []string{"all"}

// mcpOmittedFlags are never tool arguments: --force is the tool approval's
// to give (mcpToolArgv adds it to writes); the rest need a terminal, a
// local file or the config's other profiles.
var mcpOmittedFlags = map[string]bool{
	"force":        true,
	"follow":       true,
	"interval":     true,
	"stop-after":   true,
	"all-profiles": true,
	"profiles":     true,
	// A model does not retry the way a script does; it reads the error.
	"idempotency-key": true,
}

// rareFilters are the report filters few questions need. Every report tool
// would repeat them in the tool list a model reads on each connection, so
// only report_summary, report_breakdown and click_list take them (a
// breakdown by device, browser, ... answers most of what they would).
var rareFilters = []string{"browser-id", "device-type", "ip", "isp-id", "method-of-promotion", "platform-id", "referer", "region-id", "text-ad-id"}

// deleteNote is on every delete tool: the call deletes once approved, so the
// model is told to show the user the preview first.
const deleteNote = "It deletes as soon as the call is approved: call it with dry_run: true first and show the user what it would remove."

const reportFilterNote = "Filter with the *_id arguments (ids from the list tools) and choose the window with period, or time_from and time_to."

var mcpToolSpecs = []mcpToolSpec{
	// Reports
	{Name: "report_summary", Command: []string{"report", "summary"}, Title: "Performance totals", Kind: mcpRead, Note: reportFilterNote},
	{Name: "report_breakdown", Command: []string{"report", "breakdown"}, Title: "Performance by dimension", Kind: mcpRead, Note: "The Overview and Analyze pages: choose the dimension with breakdown (campaign, ppc_account, country, keyword, landing_page, device, ...). " + reportFilterNote},
	{Name: "report_timeseries", Command: []string{"report", "timeseries"}, Title: "Performance over time", Kind: mcpRead, Note: reportFilterNote, Omit: rareFilters},
	{Name: "report_daypart", Command: []string{"report", "daypart"}, Title: "Performance by hour of day", Kind: mcpRead, Omit: rareFilters},
	{Name: "report_weekpart", Command: []string{"report", "weekpart"}, Title: "Performance by day of week", Kind: mcpRead, Omit: rareFilters},
	{Name: "report_groups", Command: []string{"report", "groups"}, Title: "Grouped performance", Kind: mcpRead, Omit: rareFilters, Required: []string{"by"}},
	{Name: "report_crosstab", Command: []string{"report", "crosstab"}, Title: "Two-dimension performance table", Kind: mcpRead, Omit: rareFilters, Required: []string{"rows", "cols"}},
	{Name: "report_winners", Command: []string{"report", "winners"}, Title: "Rows to scale", Kind: mcpRead, Omit: rareFilters},
	{Name: "report_losers", Command: []string{"report", "losers"}, Title: "Rows to cut", Kind: mcpRead, Omit: rareFilters},
	{Name: "report_breakeven", Command: []string{"report", "breakeven"}, Title: "Break-even CPC", Kind: mcpRead, Omit: rareFilters, Required: []string{"aff-campaign-id"}},
	{Name: "forecast", Command: []string{"forecast"}, Title: "Forecast performance", Kind: mcpRead, Required: []string{"metric"}},
	{Name: "attribution_breakdown", Command: []string{"attribution", "breakdown"}, Title: "Attributed conversions by dimension", Kind: mcpRead},

	// Campaigns
	{Name: "campaign_list", Command: []string{"campaign", "list"}, Title: "List campaigns", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "campaign_get", Command: []string{"campaign", "get"}, Title: "Get a campaign", Kind: mcpRead, Args: []mcpArg{idArg("id", "Campaign id (from campaign_list).", true)}},
	// Its campaign is the positional; --aff-campaign-id would narrow the
	// offers it compares against to that one campaign, silently.
	{Name: "campaign_optimize", Command: []string{"campaign", "optimize"}, Title: "Diagnose a campaign", Kind: mcpRead, Args: []mcpArg{idArg("campaign_id", "Campaign id (from campaign_list).", true)}, Omit: append([]string{"aff-campaign-id"}, rareFilters...)},
	{Name: "campaign_create", Command: []string{"campaign", "create"}, Title: "Create a campaign", Kind: mcpCreate},
	{Name: "campaign_clone", Command: []string{"campaign", "clone"}, Title: "Clone a campaign", Kind: mcpCreate, Args: []mcpArg{idArg("id", "Id of the campaign to copy (from campaign_list).", true)}},
	{Name: "campaign_update", Command: []string{"campaign", "update"}, Title: "Update a campaign", Kind: mcpUpdate, Args: []mcpArg{idArg("id", "Campaign id (from campaign_list).", true)}},
	{Name: "campaign_delete", Command: []string{"campaign", "delete"}, Title: "Delete a campaign", Kind: mcpDelete, Args: []mcpArg{idArg("id", "Campaign id (from campaign_list); or give ids for several.", false)}, Note: deleteNote},

	// Trackers
	{Name: "tracker_list", Command: []string{"tracker", "list"}, Title: "List trackers", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "tracker_get", Command: []string{"tracker", "get"}, Title: "Get a tracker", Kind: mcpRead, Args: []mcpArg{idArg("id", "Tracker id (from tracker_list).", true)}},
	{Name: "tracker_get_url", Command: []string{"tracker", "get-url"}, Title: "Get a tracking link", Kind: mcpRead, Args: []mcpArg{idArg("id", "Tracker id (from tracker_list).", true)}},
	{Name: "tracker_create_with_url", Command: []string{"tracker", "create-with-url"}, Title: "Create a tracking link", Kind: mcpCreate},
	{Name: "tracker_update", Command: []string{"tracker", "update"}, Title: "Update a tracker", Kind: mcpUpdate, Args: []mcpArg{idArg("id", "Tracker id (from tracker_list).", true)}},
	{Name: "tracker_delete", Command: []string{"tracker", "delete"}, Title: "Delete a tracker", Kind: mcpDelete, Args: []mcpArg{idArg("id", "Tracker id (from tracker_list); or give ids for several.", false)}, Note: "Its tracking links stop working, including any running in ads. " + deleteNote},

	// Landing pages
	{Name: "landing_page_list", Command: []string{"landing-page", "list"}, Title: "List landing pages", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "landing_page_get", Command: []string{"landing-page", "get"}, Title: "Get a landing page", Kind: mcpRead, Args: []mcpArg{idArg("id", "Landing page id (from landing_page_list).", true)}},
	{Name: "landing_page_code", Command: []string{"landing-page", "code"}, Title: "Get landing page tracking code", Kind: mcpRead, Args: []mcpArg{idArg("id", "Landing page id (from landing_page_list).", true)}},
	{Name: "landing_page_create", Command: []string{"landing-page", "create"}, Title: "Create a landing page", Kind: mcpCreate},
	{Name: "landing_page_update", Command: []string{"landing-page", "update"}, Title: "Update a landing page", Kind: mcpUpdate, Args: []mcpArg{idArg("id", "Landing page id (from landing_page_list).", true)}},
	{Name: "landing_page_delete", Command: []string{"landing-page", "delete"}, Title: "Delete a landing page", Kind: mcpDelete, Args: []mcpArg{idArg("id", "Landing page id (from landing_page_list); or give ids for several.", false)}, Note: deleteNote},

	// Traffic sources and categories
	{Name: "ppc_network_list", Command: []string{"ppc-network", "list"}, Title: "List traffic sources", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "ppc_network_create", Command: []string{"ppc-network", "create"}, Title: "Create a traffic source", Kind: mcpCreate},
	{Name: "ppc_account_list", Command: []string{"ppc-account", "list"}, Title: "List traffic source accounts", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "ppc_account_create", Command: []string{"ppc-account", "create"}, Title: "Create a traffic source account", Kind: mcpCreate},
	{Name: "aff_network_list", Command: []string{"aff-network", "list"}, Title: "List categories", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "aff_network_create", Command: []string{"aff-network", "create"}, Title: "Create a category", Kind: mcpCreate},
	{Name: "text_ad_list", Command: []string{"text-ad", "list"}, Title: "List text ads", Kind: mcpRead, HostedOmit: mcpEveryPage},

	// Rotators
	{Name: "rotator_list", Command: []string{"rotator", "list"}, Title: "List rotators", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "rotator_get", Command: []string{"rotator", "get"}, Title: "Get a rotator and its rules", Kind: mcpRead, Args: []mcpArg{idArg("id", "Rotator id (from rotator_list).", true)}},
	{Name: "rotator_stats", Command: []string{"rotator", "stats"}, Title: "Rotator performance", Kind: mcpRead, Args: []mcpArg{idArg("id", "Rotator id (from rotator_list).", true)}, Omit: rareFilters},

	// Clicks and conversions
	{Name: "click_list", Command: []string{"click", "list"}, Title: "List clicks", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "click_get", Command: []string{"click", "get"}, Title: "Get a click", Kind: mcpRead, Args: []mcpArg{idArg("id", "Click id (from click_list).", true)}},
	{Name: "click_conversions", Command: []string{"click", "conversions"}, Title: "Explain a click's conversions", Kind: mcpRead, Args: []mcpArg{idArg("click_id", "Click id (from click_list).", true)}},
	{Name: "conversion_list", Command: []string{"conversion", "list"}, Title: "List conversions", Kind: mcpRead, HostedOmit: mcpEveryPage},
	{Name: "conversion_get", Command: []string{"conversion", "get"}, Title: "Get a conversion", Kind: mcpRead, Args: []mcpArg{idArg("id", "Conversion id (from conversion_list).", true)}},
	{Name: "conversion_postback_url", Command: []string{"conversion", "postback-url"}, Title: "Get the postback URL", Kind: mcpRead},

	// Proposed (staged) changes
	{Name: "change_list", Command: []string{"change", "list"}, Title: "List proposed changes", Kind: mcpRead},
	{Name: "change_show", Command: []string{"change", "show"}, Title: "Show a proposed change", Kind: mcpRead, Args: []mcpArg{changeArg()}},
	{Name: "change_apply", Command: []string{"change", "apply"}, Title: "Apply a proposed change", Kind: mcpUpdate, Args: []mcpArg{changeArg()}, NotStaged: true},
	{Name: "change_discard", Command: []string{"change", "discard"}, Title: "Discard a proposed change", Kind: mcpDelete, Args: []mcpArg{changeArg()}, NotStaged: true},

	// Account
	{Name: "whoami", Command: []string{"whoami"}, Title: "Who this connection acts as", Kind: mcpRead},
	{Name: "system_info", Command: []string{"system", "info"}, Title: "Install information", Kind: mcpRead, NotHosted: true},
}

// mcpInstructions is the server's instructions to the model.
const mcpInstructions = "Prosper202 is a self-hosted tracker and analytics app for affiliate and paid-traffic marketing. " +
	"These tools read and change this user's own Prosper202 install through its API; the user's role there, and this connection's access, decide what is allowed.\n" +
	"- Find ids with the list tools (campaign_list, tracker_list, ppc_account_list, ...) before a get, update or delete.\n" +
	"- Report tools take period (today, yesterday, last7, last30, thismonth, ...) or time_from and time_to, and filters such as aff_campaign_id or ppc_account_id.\n" +
	"- An error comes back as JSON with a category, a message and usually a hint. Messages and hints name CLI commands and flags (`p202 campaign list`, --aff-campaign-id); the matching tool is campaign_list and the argument aff_campaign_id.\n" +
	"- A delete runs without a further question once the tool call is approved; pass dry_run: true first and show the user what it would remove."

// mcpStagedInstructions is added when the server only proposes changes.
const mcpStagedInstructions = "\n- This connection only proposes changes: a write returns a change id (chg_...) and changes nothing. " +
	"Tell the user; applying a proposal is theirs to do (`p202 change apply <id>`), not this assistant's."

// mcpTool is one tool as MCP lists it.
type mcpTool struct {
	Name        string             `json:"name"`
	Title       string             `json:"title"`
	Description string             `json:"description"`
	InputSchema mcpSchema          `json:"inputSchema"`
	Annotations mcpToolAnnotations `json:"annotations"`
}

type mcpToolAnnotations struct {
	Title           string `json:"title"`
	ReadOnlyHint    bool   `json:"readOnlyHint"`
	DestructiveHint bool   `json:"destructiveHint"`
	IdempotentHint  bool   `json:"idempotentHint"`
	OpenWorldHint   bool   `json:"openWorldHint"`
}

type mcpSchema struct {
	Type                 string                 `json:"type"`
	Properties           map[string]mcpProperty `json:"properties"`
	Required             []string               `json:"required,omitempty"`
	AdditionalProperties bool                   `json:"additionalProperties"`
}

type mcpProperty struct {
	Type        string       `json:"type"`
	Description string       `json:"description,omitempty"`
	Pattern     string       `json:"pattern,omitempty"`
	Enum        []string     `json:"enum,omitempty"`
	Items       *mcpProperty `json:"items,omitempty"`
}

// mcpParam says how one tool argument becomes command-line words.
type mcpParam struct {
	property   string
	flag       string // "" for a positional
	position   int
	kind       string // string, boolean, integer, number, array
	required   bool
	positional bool
	// pattern and form: an *_id flag's values (mcpIDFlagPattern).
	pattern *regexp.Regexp
	form    string
}

type mcpCatalog struct {
	Schema         int       `json:"schema"`
	CLIVersion     string    `json:"cli_version,omitempty"`
	MaxResultChars int       `json:"max_result_chars"`
	Instructions   string    `json:"instructions"`
	Tools          []mcpTool `json:"tools"`
	// StagedExcludes are the tools a server that only proposes changes does
	// not offer (mcpToolSpec.NotStaged), for a host that serves several
	// kinds of connection from one list (the hosted relay).
	StagedExcludes     []string `json:"staged_excludes"`
	StagedInstructions string   `json:"staged_instructions"`
	MaxInputBytes      int      `json:"max_input_bytes"`
	// HostedExcludes are the tools the hosted relay never offers
	// (mcpToolSpec.NotHosted): its keys cannot reach what they read.
	HostedExcludes []string `json:"hosted_excludes"`
	// HostedOmits are the arguments, by tool, that the hosted relay leaves
	// out of the tools it lists (mcpToolSpec.HostedOmit).
	HostedOmits map[string][]string `json:"hosted_omits"`
}

// mcpFlagKinds maps pflag value types to JSON types. A curated command with
// a flag of any other type fails TestMCPCatalogBuilds.
var mcpFlagKinds = map[string]string{
	"string":      "string",
	"bool":        "boolean",
	"int":         "integer",
	"int64":       "integer",
	"float64":     "number",
	"stringArray": "array",
	"stringSlice": "array",
	"duration":    "string",
}

func findMCPSpec(name string) (mcpToolSpec, bool) {
	for _, s := range mcpToolSpecs {
		if s.Name == name {
			return s, true
		}
	}
	return mcpToolSpec{}, false
}

func (s mcpToolSpec) readOnly() bool { return s.Kind == mcpRead }

// offered reports whether a server with these policies lists the tool.
func (s mcpToolSpec) offered(readOnly, staged bool) bool {
	return (!readOnly || s.readOnly()) && (!staged || !s.NotStaged)
}

// command finds the spec's command under root.
func (s mcpToolSpec) command(root *cobra.Command) (*cobra.Command, error) {
	c, rest, err := root.Find(s.Command)
	if err != nil || c == root || len(rest) != 0 || !c.Runnable() {
		return nil, fmt.Errorf("tool %s: no runnable command `p202 %s`", s.Name, strings.Join(s.Command, " "))
	}
	return c, nil
}

// mcpIsAlias: an alias flag ("Alias for --breakdown") is the same argument twice.
func mcpIsAlias(f *pflag.Flag) bool {
	return strings.HasPrefix(f.Usage, "Alias for ") || strings.HasPrefix(f.Usage, "Alias of ")
}

// params lists the tool's arguments: its positionals, then each flag the
// command defines itself (not inherited), minus the omitted ones.
func (s mcpToolSpec) params(c *cobra.Command) ([]mcpParam, map[string]*pflag.Flag, error) {
	var params []mcpParam
	flags := map[string]*pflag.Flag{}
	seen := map[string]bool{}
	for i, a := range s.Args {
		params = append(params, mcpParam{property: a.Name, position: i, kind: "string", required: a.Required, positional: true})
		seen[a.Name] = true
	}
	omit := map[string]bool{}
	for _, f := range s.Omit {
		omit[f] = true
	}
	required := map[string]bool{}
	for _, f := range s.Required {
		required[f] = true
	}
	hostedOmit := map[string]bool{}
	for _, f := range s.HostedOmit {
		if fl := c.LocalFlags().Lookup(f); fl == nil || !keepFlag(fl) || mcpOmittedFlags[f] || omit[f] || mcpIsAlias(fl) || required[f] {
			return nil, nil, fmt.Errorf("tool %s: HostedOmit names --%s, which is not an optional flag the tool takes", s.Name, f)
		}
		hostedOmit[f] = mcpHostedRun
	}
	var err error
	c.LocalFlags().VisitAll(func(f *pflag.Flag) {
		if err != nil || !keepFlag(f) || mcpOmittedFlags[f.Name] || omit[f.Name] || mcpIsAlias(f) || hostedOmit[f.Name] {
			return
		}
		kind, ok := mcpFlagKinds[f.Value.Type()]
		if !ok {
			err = fmt.Errorf("tool %s: flag --%s has type %s, which no tool argument maps to", s.Name, f.Name, f.Value.Type())
			return
		}
		prop := strings.ReplaceAll(f.Name, "-", "_")
		if seen[prop] {
			err = fmt.Errorf("tool %s: argument %s is both a positional and a flag", s.Name, prop)
			return
		}
		seen[prop] = true
		flags[prop] = f
		p := mcpParam{property: prop, flag: f.Name, kind: kind, required: required[f.Name]}
		if kind == "string" && strings.HasSuffix(prop, "_id") {
			p.pattern, p.form = mcpIDFlagPattern(prop)
		}
		params = append(params, p)
	})
	for name := range required {
		if c.LocalFlags().Lookup(name) == nil || omit[name] {
			return nil, nil, fmt.Errorf("tool %s: required flag --%s is not one the tool takes", s.Name, name)
		}
	}
	return params, flags, err
}

var (
	mcpCommandRef = regexp.MustCompile("[`](?:p202 )?([a-z][a-z0-9 -]*?)[`]")
	mcpFlagRef    = regexp.MustCompile(`--([a-z0-9][a-z0-9-]*)`)
)

// mcpText rewrites CLI references in help text into the tool's terms: a
// `p202 campaign list` (or `campaign list`) that is a tool becomes
// campaign_list, and --some-flag becomes some_flag.
func mcpText(s string) string {
	s = mcpCommandRef.ReplaceAllStringFunc(s, func(m string) string {
		path := strings.Join(strings.Fields(mcpCommandRef.FindStringSubmatch(m)[1]), " ")
		for _, spec := range mcpToolSpecs {
			if strings.Join(spec.Command, " ") == path {
				return spec.Name
			}
		}
		return m
	})
	return mcpFlagRef.ReplaceAllStringFunc(s, func(m string) string {
		return strings.ReplaceAll(strings.TrimPrefix(m, "--"), "-", "_")
	})
}

// mcpListsAll reports whether a flag's help already names every value it
// takes (most do, with their aliases); an enum would say it twice in the
// tool list.
func mcpListsAll(text string, values []string) bool {
	for _, v := range values {
		if !regexp.MustCompile(`\b` + regexp.QuoteMeta(v) + `\b`).MatchString(text) {
			return false
		}
	}
	return true
}

// tool builds the MCP listing of one spec.
func (s mcpToolSpec) tool(root *cobra.Command) (mcpTool, error) {
	c, err := s.command(root)
	if err != nil {
		return mcpTool{}, err
	}
	params, flags, err := s.params(c)
	if err != nil {
		return mcpTool{}, err
	}
	schema := mcpSchema{Type: "object", Properties: map[string]mcpProperty{}}
	for _, p := range params {
		if p.required {
			schema.Required = append(schema.Required, p.property)
		}
		if p.positional {
			a := s.Args[p.position]
			schema.Properties[p.property] = mcpProperty{Type: "string", Description: a.Description, Pattern: a.Pattern.String()}
			continue
		}
		info := describeFlag(flags[p.property], false)
		if info.Required && !p.required {
			schema.Required = append(schema.Required, p.property)
		}
		prop := mcpProperty{Type: p.kind, Description: mcpText(info.Usage)}
		if p.pattern != nil {
			prop.Pattern = p.pattern.String()
		}
		if p.kind == "array" {
			prop.Items = &mcpProperty{Type: "string"}
		}
		if len(info.AllowedValues) > 0 && !mcpListsAll(prop.Description, info.AllowedValues) {
			if info.ValueList {
				prop.Description += " Comma-separated, each one of: " + strings.Join(info.AllowedValues, ", ") + "."
			} else if p.kind == "string" {
				prop.Enum = info.AllowedValues
			}
		}
		schema.Properties[p.property] = prop
	}
	sort.Strings(schema.Required)
	desc := strings.TrimSpace(c.Short)
	if !strings.HasSuffix(desc, ".") {
		desc += "."
	}
	if s.Note != "" {
		desc += " " + s.Note
	}
	t := mcpTool{
		Name:        s.Name,
		Title:       s.Title,
		Description: mcpText(desc) + " (CLI: p202 " + strings.Join(s.Command, " ") + ")",
		InputSchema: schema,
		Annotations: mcpToolAnnotations{Title: s.Title},
	}
	switch s.Kind {
	case mcpRead:
		t.Annotations.ReadOnlyHint = true
		t.Annotations.IdempotentHint = true
	case mcpCreate:
	case mcpUpdate:
		t.Annotations.DestructiveHint = true
	case mcpDelete:
		t.Annotations.DestructiveHint = true
		t.Annotations.IdempotentHint = true
	}
	return t, nil
}

// buildMCPCatalog lists the tools a server with these policies offers.
func buildMCPCatalog(root *cobra.Command, readOnly, staged bool) (mcpCatalog, error) {
	cat := mcpCatalog{
		Schema:             mcpCatalogSchema,
		CLIVersion:         root.Version,
		MaxResultChars:     mcpMaxResultChars,
		MaxInputBytes:      mcpMaxInputBytes,
		Instructions:       mcpInstructions,
		StagedInstructions: mcpStagedInstructions,
		Tools:              []mcpTool{},
		StagedExcludes:     []string{},
		HostedExcludes:     []string{},
		HostedOmits:        map[string][]string{},
	}
	if staged {
		cat.Instructions += mcpStagedInstructions
	}
	for _, s := range mcpToolSpecs {
		if s.NotStaged {
			cat.StagedExcludes = append(cat.StagedExcludes, s.Name)
		}
		if s.NotHosted {
			cat.HostedExcludes = append(cat.HostedExcludes, s.Name)
		}
		if len(s.HostedOmit) > 0 {
			c, err := s.command(root)
			if err != nil {
				return mcpCatalog{}, err
			}
			// Checks each name is an optional flag the tool takes.
			if _, _, err := s.params(c); err != nil {
				return mcpCatalog{}, err
			}
			for _, f := range s.HostedOmit {
				cat.HostedOmits[s.Name] = append(cat.HostedOmits[s.Name], strings.ReplaceAll(f, "-", "_"))
			}
		}
		if !s.offered(readOnly, staged) {
			continue
		}
		t, err := s.tool(root)
		if err != nil {
			return mcpCatalog{}, err
		}
		cat.Tools = append(cat.Tools, t)
	}
	return cat, nil
}

// mcpArgError is a refusal of a tool call's arguments, with a hint naming
// what the tool takes.
func mcpArgError(s mcpToolSpec, names []string, format string, args ...interface{}) error {
	return validationError(format, args...).
		WithHint("%s", fmt.Sprintf("%s takes: %s. `p202 mcp tools` lists every tool's arguments.", s.Name, strings.Join(names, ", ")))
}

// mcpDecodeObject reads the arguments as one JSON object with no key given
// twice: with a repeated key the last one would win in silence, so what ran
// would not be what the approval showed.
func mcpDecodeObject(input []byte) (map[string]json.RawMessage, error) {
	args := map[string]json.RawMessage{}
	trimmed := bytes.TrimSpace(input)
	if len(trimmed) == 0 {
		return args, nil
	}
	dec := json.NewDecoder(bytes.NewReader(trimmed))
	dec.UseNumber()
	tok, err := dec.Token()
	if err != nil || tok != json.Delim('{') {
		return nil, errors.New("must be a JSON object")
	}
	for dec.More() {
		keyTok, err := dec.Token()
		if err != nil {
			return nil, errors.New("must be a JSON object")
		}
		key, _ := keyTok.(string)
		var value json.RawMessage
		if err := dec.Decode(&value); err != nil {
			return nil, errors.New("must be a JSON object")
		}
		if _, dup := args[key]; dup {
			return nil, fmt.Errorf("give %s once", key)
		}
		args[key] = value
	}
	if _, err := dec.Token(); err != nil {
		return nil, errors.New("must be a JSON object")
	}
	if _, err := dec.Token(); !errors.Is(err, io.EOF) {
		return nil, errors.New("must be one JSON object")
	}
	return args, nil
}

// mcpToolArgv turns a tool call's JSON arguments into the command line
// that runs it. Everything is checked before a word is built: an argument
// the tool does not take, a value of the wrong JSON type, a null, an id that
// is not an id, or a missing required argument is refused naming the
// argument, never dropped (CLAUDE.md #4). Values go as --flag=value, and
// positionals after "--", so no value can be read as a flag.
func mcpToolArgv(root *cobra.Command, s mcpToolSpec, input []byte) ([]string, error) {
	c, err := s.command(root)
	if err != nil {
		return nil, err
	}
	params, _, err := s.params(c)
	if err != nil {
		return nil, err
	}
	byName := map[string]mcpParam{}
	var names []string
	for _, p := range params {
		byName[p.property] = p
		names = append(names, p.property)
	}
	sort.Strings(names)

	args, err := mcpDecodeObject(input)
	if err != nil {
		return nil, mcpArgError(s, names, "the arguments for %s %s", s.Name, err.Error())
	}
	if mcpHostedRun {
		for _, f := range s.HostedOmit {
			prop := strings.ReplaceAll(f, "-", "_")
			if _, given := args[prop]; given {
				return nil, validationError("%s does not take %s on the hosted server, which limits what one call reads from the install", s.Name, prop).
					WithHint("%s", fmt.Sprintf("Page with limit and offset instead (offset for the next page); `p202 mcp` on your own computer takes %s. %s takes: %s.", prop, s.Name, strings.Join(names, ", ")))
			}
		}
	}
	var unknown []string
	for k := range args {
		if _, ok := byName[k]; !ok {
			unknown = append(unknown, k)
		}
	}
	if len(unknown) > 0 {
		sort.Strings(unknown)
		return nil, mcpArgError(s, names, "%s does not take %s", s.Name, strings.Join(unknown, ", "))
	}

	argv := append([]string{}, s.Command...)
	positionals := make([]string, len(s.Args))
	for _, name := range names {
		p := byName[name]
		raw, given := args[name]
		if !given {
			if p.required {
				return nil, mcpArgError(s, names, "%s needs %s", s.Name, name)
			}
			continue
		}
		words, err := mcpValueWords(s, names, p, raw)
		if err != nil {
			return nil, err
		}
		if p.positional {
			a := s.Args[p.position]
			if !a.Pattern.MatchString(words[0]) {
				return nil, mcpArgError(s, names, "%s: %s must be %s, not %q", s.Name, name, a.Form, mcpClip(words[0]))
			}
			positionals[p.position] = words[0]
			continue
		}
		argv = append(argv, words...)
	}
	if !s.readOnly() && c.Flags().Lookup("force") != nil {
		// The client asked the user before calling a write tool; that
		// approval is the confirmation the command would otherwise prompt for.
		argv = append(argv, "--force")
	}
	var given []string
	for i, v := range positionals {
		if v == "" {
			// A later positional cannot be given without an earlier one.
			for _, later := range positionals[i+1:] {
				if later != "" {
					return nil, mcpArgError(s, names, "%s needs %s when it is given %s", s.Name, s.Args[i].Name, s.Args[i+1].Name)
				}
			}
			break
		}
		given = append(given, v)
	}
	if len(given) > 0 {
		argv = append(argv, "--")
		argv = append(argv, given...)
	}
	return argv, nil
}

// mcpClip shortens a value quoted back in a refusal.
func mcpClip(v string) string {
	if utf8.RuneCountInString(v) <= 80 {
		return v
	}
	return string([]rune(v)[:80]) + "…"
}

// mcpValueWords checks one argument's JSON value against its type and
// returns its command-line words.
func mcpValueWords(s mcpToolSpec, names []string, p mcpParam, raw json.RawMessage) ([]string, error) {
	wrong := func(want string) error {
		return mcpArgError(s, names, "%s: %s must be %s, not %s", s.Name, p.property, want, mcpClip(strings.TrimSpace(string(raw))))
	}
	if string(bytes.TrimSpace(raw)) == "null" {
		if p.required {
			return nil, mcpArgError(s, names, "%s: %s is null; it needs a value", s.Name, p.property)
		}
		return nil, mcpArgError(s, names, "%s: %s is null; leave it out to use the default", s.Name, p.property)
	}
	flagWord := func(v string) string { return "--" + p.flag + "=" + v }
	switch p.kind {
	case "string":
		v, err := mcpStringValue(raw)
		if err != nil {
			return nil, wrong("a string")
		}
		if p.positional {
			return []string{v}, nil
		}
		if p.pattern != nil && !p.pattern.MatchString(v) {
			return nil, mcpArgError(s, names, "%s: %s must be %s, not %q", s.Name, p.property, p.form, mcpClip(v))
		}
		return []string{flagWord(v)}, nil
	case "boolean":
		var b bool
		if err := json.Unmarshal(raw, &b); err != nil {
			return nil, wrong("true or false")
		}
		return []string{flagWord(fmt.Sprint(b))}, nil
	case "integer", "number":
		// json.Number also decodes a quoted number; a string is not a number.
		if t := bytes.TrimSpace(raw); len(t) == 0 || t[0] == '"' {
			return nil, wrong("a number")
		}
		var n json.Number
		dec := json.NewDecoder(bytes.NewReader(raw))
		dec.UseNumber()
		if err := dec.Decode(&n); err != nil {
			return nil, wrong("a number")
		}
		if p.kind == "integer" {
			if _, err := n.Int64(); err != nil {
				return nil, wrong("a whole number")
			}
		}
		return []string{flagWord(n.String())}, nil
	case "array":
		var items []json.RawMessage
		if err := json.Unmarshal(raw, &items); err != nil {
			return nil, wrong("a list of strings")
		}
		var words []string
		for _, item := range items {
			v, err := mcpStringValue(item)
			if err != nil {
				return nil, wrong("a list of strings")
			}
			words = append(words, flagWord(v))
		}
		return words, nil
	}
	return nil, fmt.Errorf("%s: %s has no argument type", s.Name, p.property)
}

// mcpStringValue reads a string argument. A JSON number is taken as its
// literal text (an id or a limit sent as 20 rather than "20"); the command
// checks the value either way.
func mcpStringValue(raw json.RawMessage) (string, error) {
	var v string
	if err := json.Unmarshal(raw, &v); err == nil {
		return v, nil
	}
	t := bytes.TrimSpace(raw)
	if len(t) == 0 || t[0] == '"' {
		return "", errors.New("not a string")
	}
	var n json.Number
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if err := dec.Decode(&n); err != nil {
		return "", err
	}
	return n.String(), nil
}
