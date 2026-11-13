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
	{Run: "p202 conversion list", Pages: []string{"Conversion Logs"},
		Phrases: []string{"conversion log", "conversion history", "recent conversions", "individual conversions", "every conversion"}},

	// Setup
	{Run: "p202 ppc-network list", Pages: []string{"Setup › Traffic Sources"},
		Phrases: []string{"traffic sources", "ad networks"}},
	{Run: "p202 ppc-account list",
		Phrases: []string{"traffic source accounts", "ad accounts"}},
	{Run: "p202 ppc-network update",
		Phrases: []string{"rename traffic source", "rename ad network"}},
	{Run: "p202 ppc-network add-chatgpt-ads",
		Phrases: []string{"add chatgpt ads", "set up chatgpt ads", "openai ads traffic source", "chatgpt traffic source"}},
	{Run: "p202 tracker get-url",
		Phrases: []string{"chatgpt tracking template", "ads manager tracking template", "openai ads destination url"}},
	{Run: "p202 ppc-account update",
		Phrases: []string{"rename traffic source account", "rename ad account", "default account"}},
	{Run: "p202 aff-network list", Pages: []string{"Setup › Categories"},
		Phrases: []string{"affiliate networks", "offer categories"}},
	{Run: "p202 aff-network update",
		Phrases: []string{"rename category", "rename affiliate network"}},
	{Run: "p202 campaign list", Pages: []string{"Setup › Campaigns"},
		Phrases: []string{"affiliate offers", "my offers"}},
	{Run: "p202 campaign replace-url",
		Phrases: []string{"bulk url change", "rewrite offer urls"}},
	{Run: "p202 landing-page list", Pages: []string{"Setup › Landing Pages"}},
	{Run: "p202 text-ad list", Pages: []string{"Setup › Text Ads"}},
	{Run: "p202 rotator list", Pages: []string{"Setup › Redirector"},
		Phrases: []string{"rotators", "split test offers", "redirect rules"}},
	{Run: "p202 rotator set-rules",
		Phrases: []string{"redirector rules", "rotator rules", "rule order", "reorder rules", "weighted split", "split traffic by weight"}},
	{Run: "p202 app list", Pages: []string{"Setup › Mobile Apps"},
		Phrases: []string{"registered apps"}},
	{Run: "p202 landing-page code", Pages: []string{"Setup › Get LP Code"},
		Phrases: []string{"landing page code", "landing page tracking script", "lp code"}},
	{Run: "p202 landing-page check",
		Phrases: []string{"check my page", "is the tracking code installed", "verify the code is on the page"}},
	{Run: "p202 tracker create-with-url", Pages: []string{"Setup › Get Links"},
		Phrases: []string{"tracking link", "generate a tracking link", "campaign link"}},
	{Run: "p202 conversion postback-url", Pages: []string{"Setup › Postback/Pixel"},
		Phrases: []string{"postback url", "s2s postback", "server to server postback"}},
	{Run: "p202 conversion pixel",
		Phrases: []string{"conversion pixel", "thank you page pixel", "tracking pixel"}},
	{Run: "p202 conversion match-methods",
		Phrases: []string{"how conversions were matched", "conversions matched by ip", "guessed conversions",
			"cookie conversions", "conversions without a subid"}},
	{Run: "p202 conversion postback-auth get",
		Phrases: []string{"postback token", "secure postbacks", "fake conversions", "forged postbacks",
			"sign postbacks", "rotate postback token", "require token"}},
	{Run: "p202 conversion offline-export --format google", Pages: []string{"Setup › Offline Conversions"},
		Phrases: []string{"offline conversions csv", "google ads offline conversions",
			"upload conversions to google ads", "gclid upload", "microsoft ads offline conversions", "msclkid upload",
			"bing offline conversions", "scheduled conversion upload"}},
	{Run: "p202 destination create", Pages: []string{"Setup › Destinations"},
		Phrases: []string{"send conversions to google analytics", "ga4 measurement protocol", "server side conversions",
			"conversions api", "send conversions to ad platforms", "send conversions to chatgpt ads", "openai conversions api"}},
	{Run: "p202 ad-connection create --api-key-file key.txt",
		Phrases: []string{"connect chatgpt ads", "connect openai ads", "chatgpt ads api key", "advertiser api key",
			"link my chatgpt ad account"}},
	{Run: "p202 ad-connection setup",
		Phrases: []string{"set up the chatgpt ads pixel", "create an openai conversions api key", "chatgpt ads event settings",
			"openai ads pixel and conversions"}},
	{Run: "p202 ad-connection update --api-key-file key.txt",
		Phrases: []string{"replace the advertiser api key", "rotate the chatgpt ads key", "openai refused the stored key"}},
	{Run: "p202 ad-campaign list",
		Phrases: []string{"list chatgpt ads campaigns", "chatgpt ad status", "why is my chatgpt ad not serving", "chatgpt ad rejected",
			"openai ads campaign budget"}},
	{Run: "p202 ad-campaign pause --dry-run",
		Phrases: []string{"pause a chatgpt ads campaign", "stop a losing chatgpt campaign", "turn off chatgpt ad", "pause openai ad"}},
	{Run: "p202 ad-campaign activate --dry-run",
		Phrases: []string{"activate a chatgpt ads campaign", "turn a chatgpt campaign back on", "resume chatgpt ad"}},
	{Run: "p202 ad-campaign get",
		Phrases: []string{"why is this chatgpt ad rejected", "chatgpt ad review status", "one chatgpt campaign's budget"}},
	{Run: "p202 ad-campaign set-template",
		Phrases: []string{"push the tracking template to chatgpt ads", "set the chatgpt campaign query string", "chatgpt ads url parameters"}},
	{Run: "p202 ad-campaign attach-events",
		Phrases: []string{"attach conversion events to a chatgpt campaign", "chatgpt campaign conversion event settings"}},
	{Run: "p202 ad-campaign spend",
		Phrases: []string{"chatgpt ads spend by campaign", "openai ads spend by campaign", "campaign spend live at openai"}},
	{Run: "p202 ad-connection check",
		Phrases: []string{"is my chatgpt ads key still working", "chatgpt ads account currency and time zone"}},
	{Run: "p202 spend conversions --period last7",
		Phrases: []string{"chatgpt ads conversions", "conversions openai reports per ad", "reconcile platform conversions",
			"compare ad platform conversions with mine"}},
	{Run: "p202 spend sync enable",
		Phrases: []string{"pull chatgpt ads spend", "sync openai ad spend hourly", "turn on spend sync",
			"real cost from chatgpt ads instead of cpc"}},
	{Run: "p202 destination resend --status failed",
		Phrases: []string{"send failed conversions again", "retry failed deliveries", "resend conversions to google ads",
			"conversions missing in the ad platform after fixing the token"}},
	{Run: "p202 destination update --scope all",
		Phrases: []string{"send every conversion to chatgpt ads instead", "chatgpt ads view through conversions",
			"credit people who saw a chatgpt ad", "openai destination scope all"}},
	{Run: "p202 destination backfill",
		Phrases: []string{"send earlier conversions to a new destination", "backfill conversions"}},
	{Run: "p202 destination deliveries",
		Phrases: []string{"ga4 delivery log", "why did a conversion not reach google analytics", "test ga4 destination"}},
	{Run: "p202 conversion postback-auth readiness",
		Phrases: []string{"which networks send no token", "safe to require the postback token",
			"postbacks without a token", "who would be refused"}},
	{Run: "p202 conversion inbound-log record",
		Phrases: []string{"record a refused postback", "recover a lost conversion", "replay a postback",
			"record it now"}},
	{Run: "p202 conversion shopify-refunds list",
		Phrases: []string{"shopify refunds waiting", "pending shopify refund", "refund not recorded", "refused shopify refund",
			"record a shopify refund", "refunds still counted as revenue"}},
	{Run: "p202 conversion inbound-log --outcome refused", Pages: []string{"Setup › Inbound Log"},
		Phrases: []string{"postback log", "refused postbacks", "why a postback failed", "conversions not showing",
			"ipn log", "rejected pixel", "inbound requests"}},

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
	{Run: "p202 report breakdown --breakdown publisher", Pages: []string{"Analyze › Publishers"},
		Phrases: []string{"publisher link report", "clicks and conversions by publisher", "what a publisher's links did"}},
	{Run: "p202 report breakdown --breakdown publisher_link",
		Phrases: []string{"clicks per publisher link", "links of a publisher", "tracking link of a publisher's clicks"}},
	{Run: "p202 ltv summary", Pages: []string{"Analyze › Customer LTV"},
		Phrases: []string{"customer lifetime value"}},
	{Run: "p202 ltv webhooks revive",
		Phrases: []string{"revive webhook", "reactivate webhook", "webhook stopped sending", "resend missed webhook deliveries"}},
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
	{Run: "p202 spend import",
		Phrases: []string{"import ad spend", "import spend", "record ad spend", "record spend", "upload ad platform spend",
			"load spend from the ad platform", "send hourly spend per ad", "write the spend ledger"}},
	{Run: "p202 spend list",
		Phrases: []string{"ad spend", "spend ledger", "what the ad platform billed", "what an ad cost", "cost per ad per hour", "spend per ad", "spend by day",
			"what the ad platform charged", "unallocated spend", "spend no click matched", "how far spend data reaches",
			"what did chatgpt ads cost", "chatgpt ads spend per ad", "openai ads spend by day"}},

	// Account menu
	{Run: "p202 user prefs update", Pages: []string{"Personal Settings"},
		Phrases: []string{"my preferences", "report defaults", "privacy setting", "same ip fallback"}},
	{Run: "p202 click-filter get",
		Phrases: []string{"why are clicks filtered", "click filtering settings", "repeat visit window"}},
	{Run: "p202 click-filter list add",
		Phrases: []string{"always count clicks from an office", "filter a click farm", "click filter address list"}},
	{Run: "p202 click-filter test",
		Phrases: []string{"would this address be filtered"}},
	{Run: "p202 config set-url",
		Phrases: []string{"instance url", "install address", "server address", "connect the cli to an install"}},
	{Run: "p202 system info", Pages: []string{"Settings"},
		Phrases: []string{"install settings", "versions", "database size", "geoip database age", "geolite build date"}},
	{Run: "p202 system integrations", Pages: []string{"3rd Party API Integrations"},
		Phrases: []string{"clickbank url", "jvzoo url", "instant notification url", "shopify webhook url"}},
	{Run: "p202 system integrations record-held",
		Phrases: []string{"record held sales", "held notifications", "sales in another currency", "currency mismatch"}},
	// The CLI's own setting: the docs call it the install's base URL.
	{Run: "p202 config set-url", Phrases: []string{"base url", "instance url"}},
	{Run: "p202 user list", Pages: []string{"User Management"},
		Phrases: []string{"team members"}},
	{Run: "p202 attribution breakdown", Pages: []string{"Attribution"},
		Phrases: []string{"multi touch attribution", "attribution report"}},
	{Run: "p202 attribution queue",
		Phrases: []string{"attribution worker backlog", "why attribution is failing", "failing model recompute"}},
	{Run: "p202 attribution retry",
		Phrases: []string{"try failed attribution again", "retry failed attribution"}},
	{Run: "p202 system login-log",
		Phrases: []string{"sign-in attempts", "login history", "failed logins"}},
	{Run: "p202 system retention delete-before --dry-run",
		Phrases: []string{"delete old clicks", "delete click data before a date", "purge old click data", "preview a click deletion"}},
	{Run: "p202 system traffic-source-owners show",
		Phrases: []string{"missing traffic source", "traffic source taken by another user", "traffic source in the wrong account", "move a traffic source back"}},
	{Run: "p202 system traffic-source-owners split",
		Phrases: []string{"traffic source shared by two users", "give each user their own traffic source", "split a shared traffic source"}},

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
	{Phrases: []string{"refresh the geoip database", "maxmind license key"},
		Instead: "the GeoIP refresh is set up and run in the web UI, under Account › Settings › GeoIP database",
		Hint:    "`p202 system info` shows the database's build date and age (geoip_database) and how its last refresh ended (geoip_refresh)."},
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
