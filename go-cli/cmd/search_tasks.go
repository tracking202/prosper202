package cmd

import (
	"strings"
)

// searchTask is one thing a person or an agent sets out to do: the command
// line that does it, the web UI pages that do the same, and the words people
// use when they ask for it. `p202 search` ranks a task's pages and phrases
// with a command's own name, and offers the task's command line as the one
// to try; `p202 commands --json` lists each command's tasks.
//
// Search matches words, so it finds a command only when the asker uses the
// words its text uses. Measured before this table: 20 of 39 UI menu labels
// put the right command first as a good match; "spy" did not ("Spy" was a
// word in one flag's help), "realtime traffic", "live clicks" and "tail
// clicks" found no click command at all, and "real-time traffic" found
// `click list` through `--show real`, the filter for human clicks.
//
// Pages are the UI's own menu labels: a top-level label as it reads ("Spy",
// "Settings"), a section strip's label under its section ("Analyze › Text
// Ads"), since Setup and Analyze both have a "Text Ads". The test reads the
// menus (tracking202/_config/sub-menu.php and top.php,
// 202-config/template.php) and holds this table to them both ways.
//
// Phrases say what the task is, the way people ask for it. They are written
// from the task, not copied from a test: TestSearchFindsTheEvalAsksCommand
// measures search against the agent-eval cases' asks, and a phrase lifted
// from an ask would only teach that one ask.
type searchTask struct {
	// Run is the command line, flags included: "p202 click list --follow".
	// Empty for a page the CLI has no command for (Instead says why).
	Run     string
	Pages   []string
	Phrases []string
	// Instead, for a task the CLI does not do, says where it is done; Hint
	// names a command that helps, when one does.
	Instead string
	Hint    string
}

var searchTasks = []searchTask{
	// Visitors and Spy
	{Run: "p202 click list --follow", Pages: []string{"Spy"},
		Phrases: []string{"realtime traffic", "realtime clicks", "live traffic", "live clicks", "watch clicks", "watch traffic",
			"tail clicks", "stream clicks", "monitor traffic", "clicks as they arrive", "clicks as they come in", "incoming clicks"}},
	{Run: "p202 click list", Pages: []string{"Visitors"},
		Phrases: []string{"visitor log", "recent clicks", "click history", "individual clicks"}},

	// Setup
	{Run: "p202 ppc-network list", Pages: []string{"Setup › Traffic Sources"},
		Phrases: []string{"traffic sources", "ad networks"}},
	{Run: "p202 ppc-account list",
		Phrases: []string{"traffic source accounts", "ad accounts"}},
	{Run: "p202 aff-network list", Pages: []string{"Setup › Categories"},
		Phrases: []string{"affiliate networks", "offer categories"}},
	{Run: "p202 campaign list", Pages: []string{"Setup › Campaigns"},
		Phrases: []string{"affiliate offers", "my offers"}},
	{Run: "p202 landing-page list", Pages: []string{"Setup › Landing Pages"}},
	{Run: "p202 text-ad list", Pages: []string{"Setup › Text Ads"}},
	{Run: "p202 rotator list", Pages: []string{"Setup › Redirector"},
		Phrases: []string{"rotators", "split test offers", "redirect rules"}},
	{Run: "p202 app list", Pages: []string{"Setup › Mobile Apps"},
		Phrases: []string{"registered apps"}},
	{Run: "p202 landing-page code", Pages: []string{"Setup › Get LP Code"},
		Phrases: []string{"landing page code", "landing page tracking script", "lp code"}},
	{Run: "p202 tracker create-with-url", Pages: []string{"Setup › Get Links"},
		Phrases: []string{"tracking link", "generate a tracking link", "campaign link"}},
	{Run: "p202 conversion postback-url", Pages: []string{"Setup › Postback/Pixel"},
		Phrases: []string{"postback url", "s2s postback", "server to server postback"}},
	{Run: "p202 conversion pixel",
		Phrases: []string{"conversion pixel", "thank you page pixel", "tracking pixel"}},

	// Overview
	{Run: "p202 report breakdown --breakdown campaign", Pages: []string{"Overview › Campaign Overview"},
		Phrases: []string{"campaign performance", "stats per campaign"}},
	{Run: "p202 report timeseries", Pages: []string{"Overview › Breakdown Analysis"},
		Phrases: []string{"performance over time", "daily stats", "trend"}},
	{Run: "p202 report daypart", Pages: []string{"Overview › Day Parting"},
		Phrases: []string{"dayparting", "time of day"}},
	{Run: "p202 report weekpart", Pages: []string{"Overview › Week Parting"},
		Phrases: []string{"weekparting", "weekday performance"}},
	{Run: "p202 report groups", Pages: []string{"Overview › Group Overview"},
		Phrases: []string{"nested report", "group by several dimensions"}},
	{Run: "p202 dashboard",
		Phrases: []string{"overall performance", "how are we doing", "performance today", "profit and roi"}},

	// Analyze: each page is one breakdown dimension.
	{Run: "p202 report breakdown --breakdown keyword", Pages: []string{"Analyze › Keywords"}},
	{Run: "p202 report breakdown --breakdown text_ad", Pages: []string{"Analyze › Text Ads"}},
	{Run: "p202 report breakdown --breakdown referer", Pages: []string{"Analyze › Referers"}},
	{Run: "p202 report breakdown --breakdown ip", Pages: []string{"Analyze › IPs"}},
	{Run: "p202 report breakdown --breakdown country", Pages: []string{"Analyze › Countries"}},
	{Run: "p202 report breakdown --breakdown region", Pages: []string{"Analyze › Regions"}},
	{Run: "p202 report breakdown --breakdown city", Pages: []string{"Analyze › Cities"}},
	{Run: "p202 report breakdown --breakdown isp", Pages: []string{"Analyze › ISP/Carrier"}},
	{Run: "p202 report breakdown --breakdown landing_page", Pages: []string{"Analyze › Landing Pages"}},
	{Run: "p202 report breakdown --breakdown device", Pages: []string{"Analyze › Devices"}},
	{Run: "p202 report breakdown --breakdown browser", Pages: []string{"Analyze › Browsers"}},
	{Run: "p202 report breakdown --breakdown platform", Pages: []string{"Analyze › Platforms"}},
	{Run: "p202 report breakdown --breakdown c1", Pages: []string{"Analyze › Custom Variables"},
		Phrases: []string{"c1 c2 c3 c4", "custom variable breakdown"}},
	{Run: "p202 ltv summary", Pages: []string{"Analyze › Customer LTV"},
		Phrases: []string{"customer lifetime value"}},
	{Run: "p202 app report", Pages: []string{"Analyze › Mobile Apps"},
		Phrases: []string{"app installs report", "skadnetwork postbacks"}},

	// Update
	{Run: "p202 conversion mark-subids", Pages: []string{"Update › Update Subids"}},
	{Run: "p202 click update-cpc", Pages: []string{"Update › Update CPC"},
		Phrases: []string{"set what clicks cost", "record click cost"}},
	{Run: "p202 conversion reset-subids", Pages: []string{"Update › Reset Campaign Subids"}},
	{Run: "p202 conversion delete-subids", Pages: []string{"Update › Delete Subids"}},
	{Run: "p202 conversion upload-revenue", Pages: []string{"Update › Upload Revenue Reports"},
		Phrases: []string{"commission report", "network revenue csv"}},

	// Account menu
	{Run: "p202 user prefs update", Pages: []string{"Personal Settings"},
		Phrases: []string{"my preferences", "report defaults", "privacy setting"}},
	{Run: "p202 system info", Pages: []string{"Settings"},
		Phrases: []string{"install settings", "versions", "database size"}},
	{Run: "p202 system integrations", Pages: []string{"3rd Party API Integrations"},
		Phrases: []string{"clickbank url", "jvzoo url", "instant notification url"}},
	{Run: "p202 user list", Pages: []string{"User Management"},
		Phrases: []string{"team members"}},
	{Run: "p202 attribution breakdown", Pages: []string{"Attribution"},
		Phrases: []string{"multi touch attribution", "attribution report"}},
	{Run: "p202 system login-log",
		Phrases: []string{"sign-in attempts", "login history", "failed logins"}},

	// Pages and tasks with no command.
	{Pages: []string{"Home"},
		Instead: "the account home is a get-started checklist",
		Hint:    "Its steps are `p202 ppc-network create` and `p202 ppc-account create`, `p202 campaign create`, then `p202 tracker create-with-url`."},
	// The menu shows "Watch" and "& Discounts" only on wide screens
	// (p202c-nav__label--long), so the short names are names people see.
	{Pages: []string{"Watch TV202"}, Phrases: []string{"tv202"},
		Instead: "it plays Tracking202's hosted videos", Hint: "Open the page in the web UI."},
	{Pages: []string{"Hot Deals & Discounts"}, Phrases: []string{"hot deals"},
		Instead: "it lists Tracking202's hosted offers", Hint: "Open the page in the web UI."},
	{Pages: []string{"VIP Perks Profile"}, Instead: "it is your profile on Tracking202's hosted service", Hint: "Open the page in the web UI."},
	{Pages: []string{"Help"}, Instead: "it links the documentation and support",
		Hint: "`p202 --help`, `p202 <command> --help` and `p202 commands` describe the CLI."},
	{Phrases: []string{"upgrade", "1-click upgrade", "upgrade prosper202", "install upgrade"},
		Instead: "upgrades run from the web UI's upgrade page",
		Hint:    "`p202 system info` says which version is installed and whether an upgrade is needed."},
}

// searchSections are the UI's top-level tabs that only hold a strip of
// pages; each page is in the table under its section, so the tab itself is
// not a task.
var searchSections = map[string]string{
	"Setup":         "a section tab: its pages are under it (Setup › Traffic Sources, …)",
	"Overview":      "a section tab: its pages are under it (Overview › Campaign Overview, …)",
	"Analyze":       "a section tab: its pages are under it (Analyze › Keywords, …)",
	"Update":        "a section tab: its pages are under it (Update › Update Subids, …)",
	"Prosper202 CS": "the tracking section's tab: it opens Overview",
}

// taskCommandPath is the command a task line runs: its words up to the
// first flag ("p202 report breakdown --breakdown ip" → "p202 report
// breakdown").
func taskCommandPath(run string) string {
	var words []string
	for _, w := range strings.Fields(run) {
		if strings.HasPrefix(w, "-") {
			break
		}
		words = append(words, w)
	}
	return strings.Join(words, " ")
}

// taskFlags are the flag names a task line sets.
func taskFlags(run string) map[string]bool {
	out := map[string]bool{}
	for _, w := range strings.Fields(run) {
		if strings.HasPrefix(w, "--") {
			out[strings.TrimPrefix(w, "--")] = true
		}
	}
	return out
}

// tasksFor lists the tasks a command runs, in table order.
func tasksFor(path string) []taskInfo {
	var out []taskInfo
	for _, t := range searchTasks {
		if t.Run != "" && taskCommandPath(t.Run) == path {
			out = append(out, taskInfo{Run: t.Run, UIPages: t.Pages, Phrases: t.Phrases})
		}
	}
	return out
}

// tasksNotInCLI lists the pages and tasks no command does.
func tasksNotInCLI() []taskInfo {
	var out []taskInfo
	for _, t := range searchTasks {
		if t.Run == "" {
			out = append(out, taskInfo{UIPages: t.Pages, Phrases: t.Phrases, Instead: t.Instead, Hint: t.Hint})
		}
	}
	return out
}

// taskInfo is a task as `p202 commands --json` prints it.
type taskInfo struct {
	Run     string   `json:"run,omitempty"`
	UIPages []string `json:"ui_pages,omitempty"`
	Phrases []string `json:"phrases,omitempty"`
	Instead string   `json:"instead,omitempty"`
	Hint    string   `json:"hint,omitempty"`
}
