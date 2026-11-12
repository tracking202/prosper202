package cmd

import (
	"sort"
	"strings"

	"github.com/spf13/cobra"
)

// Deferred MCP tools: tools past what the always-loaded catalogue's budget
// holds (TestMCPCatalogStaysInBudget). `p202 mcp` lists get_more_tools
// instead, the hosted connector's contract ("when no tool fits what the
// user asked, call get_more_tools with the task"): it matches the task
// against the deferred tools' names, titles, descriptions and Words, loads
// the matches into the session (the client hears notifications/tools/
// list_changed) and answers their names, one-line descriptions and input
// schemas. A call to a deferred tool that was never loaded is loaded and
// run, so a client that ignores list_changed is not stuck.
//
// Deferred tools are local only (NotHosted): the hosted relay lists the
// always-loaded catalogue and has its own get_more_tools. `p202 mcp call`
// runs them like any other tool, and `p202 mcp tools --deferred` lists them.
//
// To add one: append its spec here, with Words a person would use for the
// task (TestMCPDeferredToolsAreFoundByTheirTask asks each tool's first Word).

// mcpGetMoreTools is the always-loaded tool that loads deferred ones.
const mcpGetMoreTools = "get_more_tools"

// mcpDeferredToolSpecs is the one registration list of deferred tools.
var mcpDeferredToolSpecs = []mcpToolSpec{
	// Destinations (Setup › Destinations): where conversions are sent from this server.
	{Name: "destination_list", Command: []string{"destination", "list"}, Title: "Destinations and their status", Kind: mcpRead, NotHosted: true,
		Note:  "GA4, Google Ads, ChatGPT Ads: settings, status, what waits for you.",
		Words: []string{"destinations", "conversions api", "server-side conversions", "ga4", "google ads", "chatgpt ads", "openai"}},
	{Name: "destination_deliveries", Command: []string{"destination", "deliveries"}, Title: "A destination's delivery log", Kind: mcpRead, NotHosted: true,
		Args: []mcpArg{idArg("id", "Destination id (from destination_list).", true)}, Omit: []string{"conv-id"},
		Note:  destinationDeliveriesNote,
		Words: []string{"delivery log", "failed conversions", "withheld conversions", "why a conversion did not reach the ad platform", "destinations"}},
	{Name: "destination_test", Command: []string{"destination", "test"}, Title: "Test a destination", Kind: mcpCreate, NotHosted: true, NotStaged: true, OpenWorld: true,
		Args: []mcpArg{idArg("id", "Destination id (from destination_list).", true)},
		// --real sends an event the platform saves and counts; the page asks before it, a tool call cannot.
		Omit:  []string{"real"},
		Words: []string{"test destination", "test fire", "validate destination", "destinations"}},
	// ChatGPT Ads connections (Setup › Destinations › ChatGPT Ads): an ad account reached with its Advertiser API key.
	{Name: "ad_connection_list", Command: []string{"ad-connection", "list"}, Title: "ChatGPT Ads connections", Kind: mcpRead, NotHosted: true,
		Note:  "Each ad account's id, name, currency, time zone, status and what setup made.",
		Words: []string{"chatgpt ads connection", "openai ads account", "advertiser api key", "chatgpt ads"}},
	{Name: "ad_connection_check", Command: []string{"ad-connection", "check"}, Title: "Check a ChatGPT Ads connection", Kind: mcpUpdate, NotHosted: true, NotStaged: true, OpenWorld: true, Refreshes: true,
		Args:  []mcpArg{idArg("id", "Ad connection id (from ad_connection_list).", true)},
		Note:  "The answer is recorded on the connection (status, last_error); nothing is changed at OpenAI.",
		Words: []string{"is my chatgpt ads key working", "check chatgpt ads connection", "chatgpt ads"}},

	// The spend ledger: what ad platforms billed (ChatGPT Ads' hourly pull).
	{Name: "ad_spend", Command: []string{"spend", "list"}, Title: "What ad platforms billed, per ad", Kind: mcpRead, NotHosted: true, Keep: []string{"interval"},
		Note: "Per day or hour and ad: impressions, clicks, spend, your clicks that carried the ad, and spend no click carried (unallocated); reaches says how far it goes. No rows: the ad account's hourly pull may be off.",
		Words: []string{"ad spend", "what did chatgpt ads cost", "openai ads spend", "spend per ad", "cost per ad", "ad platform billed",
			"unallocated spend", "spend ledger"}},
	{Name: "ad_spend_sync_status", Command: []string{"spend", "sync", "list"}, Title: "ChatGPT Ads spend sync status", Kind: mcpRead, NotHosted: true,
		Words: []string{"spend sync", "is my spend sync working", "spend sync status", "last spend pull", "spend sync error", "chatgpt ads sync", "openai ads sync"}},
}

// destinationDeliveriesNote names only keys the install's answer carries
// (DestinationsController::deliveries; TestMCPDeferredNotesNameKeysTheAnswerCarries):
// it has no truncated, unlike the report tools' pageNote.
const destinationDeliveriesNote = "Why each failed or was withheld. meta.summary counts each status; " +
	"pagination.total counts every row the filters match, so more remain while it is past offset plus the rows returned."

// mcpAllToolSpecs is every tool `p202 mcp call` can run.
func mcpAllToolSpecs() []mcpToolSpec {
	return append(append([]mcpToolSpec{}, mcpToolSpecs...), mcpDeferredToolSpecs...)
}

func findMCPDeferredSpec(name string) (mcpToolSpec, bool) {
	for _, s := range mcpDeferredToolSpecs {
		if s.Name == name {
			return s, true
		}
	}
	return mcpToolSpec{}, false
}

// buildMCPDeferredTools builds the deferred tools a server with these
// policies offers, as buildMCPCatalog builds the always-loaded ones.
func buildMCPDeferredTools(root *cobra.Command, readOnly, staged bool) ([]mcpTool, error) {
	tools := []mcpTool{}
	for _, s := range mcpDeferredToolSpecs {
		if !s.offered(readOnly, staged) {
			continue
		}
		t, err := s.tool(root)
		if err != nil {
			return nil, err
		}
		tools = append(tools, t)
	}
	return tools, nil
}

// mcpMoreToolsWords are the words of a task that say nothing about a tool.
var mcpMoreToolsWords = map[string]bool{
	"a": true, "an": true, "and": true, "are": true, "as": true, "at": true, "be": true, "by": true, "can": true, "do": true,
	"does": true, "for": true, "from": true, "get": true, "how": true, "i": true, "in": true, "is": true, "it": true, "list": true,
	"me": true, "my": true, "of": true, "on": true, "or": true, "our": true, "please": true, "show": true, "that": true, "the": true,
	"their": true, "them": true, "this": true, "to": true, "tool": true, "tools": true, "was": true, "what": true, "which": true,
	"why": true, "with": true, "you": true, "your": true,
}

// mcpTaskWords splits text into lower-case words, a trailing plural s off,
// and the words that say nothing about a tool left out.
func mcpTaskWords(text string) []string {
	fields := strings.FieldsFunc(strings.ToLower(text), func(r rune) bool {
		return (r < 'a' || r > 'z') && (r < '0' || r > '9')
	})
	out := []string{}
	for _, w := range fields {
		if len(w) > 3 && strings.HasSuffix(w, "s") && !strings.HasSuffix(w, "ss") {
			w = strings.TrimSuffix(w, "s")
		}
		if len(w) < 2 || mcpMoreToolsWords[w] {
			continue
		}
		out = append(out, w)
	}
	return out
}

// mcpMatchDeferred ranks the tools by how many of the task's words their
// name, title, description and Words hold, best first; a tool sharing none
// is left out. Ties keep the registration order.
func mcpMatchDeferred(task string, specs []mcpToolSpec, tools []mcpTool) []mcpTool {
	words := mcpTaskWords(task)
	type scored struct {
		tool  mcpTool
		score int
		order int
	}
	var hits []scored
	for i, t := range tools {
		var spec mcpToolSpec
		for _, s := range specs {
			if s.Name == t.Name {
				spec = s
			}
		}
		index := map[string]bool{}
		for _, w := range mcpTaskWords(t.Name + " " + t.Title + " " + t.Description + " " + strings.Join(spec.Words, " ")) {
			index[w] = true
		}
		score := 0
		for _, w := range words {
			if index[w] {
				score++
			}
		}
		if score > 0 {
			hits = append(hits, scored{t, score, i})
		}
	}
	sort.SliceStable(hits, func(a, b int) bool { return hits[a].score > hits[b].score })
	out := make([]mcpTool, 0, len(hits))
	for _, h := range hits {
		out = append(out, h.tool)
	}
	return out
}

// mcpOneLine is a tool's description cut to its first sentence.
func mcpOneLine(t mcpTool) string {
	d := t.Description
	if i := strings.Index(d, ". "); i >= 0 {
		d = d[:i+1]
	}
	return d
}
