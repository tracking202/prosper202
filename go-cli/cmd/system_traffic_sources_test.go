package cmd

import (
	"encoding/json"
	"fmt"
	"reflect"
	"strings"
	"testing"

	"p202/internal/api"
)

// `p202 system traffic-source-owners` against a recording server
// (updateServer): what each command sends, what it refuses before sending
// anything, and the exit code and hint of each failure. What the server
// lists and moves is TrafficSourceOwnersIntegrationTest's.

// Source 7 has one user to go back to; source 9 is ambiguous.
const tsoReportBody = `{"data":{"sources":[
 {"ppc_network_id":7,"ppc_network_name":"Google Ads","removed":false,"ppc_network_time":1100000000,
  "owner":{"user_id":3,"user_name":"taker","user_deleted":false,"ppc_accounts":0,"removed_ppc_accounts":0,"ppc_account_ids":[],"last_ppc_account_time":null,"clicks":0,"conversions":0},
  "named_by":[{"user_id":2,"user_name":"owner","user_deleted":false,"ppc_accounts":2,"removed_ppc_accounts":1,"ppc_account_ids":[4,5,6],"last_ppc_account_time":1000000000,"clicks":1200,"conversions":14}],
  "repair_to_user_id":2,"ambiguous":[]},
 {"ppc_network_id":9,"ppc_network_name":"Shared","removed":true,"ppc_network_time":1000000000,
  "owner":{"user_id":1,"user_name":"admin","user_deleted":false,"ppc_accounts":1,"removed_ppc_accounts":0,"ppc_account_ids":[8],"last_ppc_account_time":1000000000,"clicks":5,"conversions":0},
  "named_by":[{"user_id":4,"user_name":"sub","user_deleted":false,"user_active":false,"ppc_accounts":1,"removed_ppc_accounts":0,"ppc_account_ids":[10],"last_ppc_account_time":1200000000,"clicks":30,"conversions":1},
   {"user_id":6,"user_name":"gone","user_deleted":true,"user_active":true,"ppc_accounts":1,"removed_ppc_accounts":0,"ppc_account_ids":[11],"last_ppc_account_time":900000000,"clicks":0,"conversions":0}],
  "repair_to_user_id":null,"ambiguous":["owner_has_accounts","saved_after_rename"]}
],"repairable":1,"ambiguous":1}}`

const tsoRepairedOne = `{"data":{"repaired":[{"ppc_network_id":7,"ppc_network_name":"Google Ads","removed":false,"from_user_id":3,"to_user_id":2,"ppc_accounts":3,"queued_ppc_accounts":3}],"skipped":[]}}`

// assertTsoBody holds a request body to exactly the keys and values of want.
func assertTsoBody(t *testing.T, got map[string]interface{}, want string) {
	t.Helper()
	var w map[string]interface{}
	if err := json.Unmarshal([]byte(want), &w); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(got, w) {
		t.Errorf("body = %v, want %s", got, want)
	}
}

func tsoResponder(write func(r updateRequest) (int, string)) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		if r.Method == "GET" {
			return 200, tsoReportBody
		}
		return write(r)
	}
}

func TestTrafficSourceOwnersShowListsEachSource(t *testing.T) {
	seen := updateServer(t, tsoResponder(nil))
	stdout, _, err := executeCommand("system", "traffic-source-owners", "show", "--table", "--wide")
	if err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || (*seen)[0].Path != "/system/traffic-source-owners" {
		t.Errorf("requests = %+v", *seen)
	}
	for _, want := range []string{"Google Ads", "user 3 (taker)", "user 2 (owner): 3 traffic source account(s), 1 removed, 1200 click(s), 14 conversion(s)", "owner_has_accounts, saved_after_rename",
		"user 4 (sub, switched off)", "user 6 (gone, deleted)"} {
		if !strings.Contains(stdout, want) {
			t.Errorf("the table lacks %q:\n%s", want, stdout)
		}
	}

	updateServer(t, tsoResponder(nil))
	stdout, _, err = executeCommand("system", "traffic-source-owners", "show", "--json")
	if err != nil || !strings.Contains(stdout, `"ppc_account_ids"`) || !strings.Contains(stdout, `"repair_to_user_id": null`) {
		t.Errorf("--json carries the answer as sent: err %v\n%s", err, stdout)
	}

	updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{"repairable":0}}` })
	_, _, err = executeCommand("system", "traffic-source-owners", "show")
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("an answer without sources: exit %d (%v), want the server's failure, never an empty list", code, err)
	}

	updateServer(t, func(updateRequest) (int, string) { return 404, `{"error":true,"status":404,"message":"Not found"}` })
	_, _, err = executeCommand("system", "traffic-source-owners", "show")
	if !strings.Contains(hintFor(err), "features.traffic_source_owners") {
		t.Errorf("a server without the report: hint %q", hintFor(err))
	}
}

func TestTrafficSourceOwnersRepairAsksThenSendsTheSource(t *testing.T) {
	t.Run("no cancels after the read", func(t *testing.T) {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		answerPrompts(t, "n\n")
		_, stderr, err := executeCommand("system", "traffic-source-owners", "repair", "7")
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Cancelled") {
			t.Errorf("err %v, requests %+v, stderr %q", err, *seen, stderr)
		}
		if !strings.Contains(stderr, `Traffic source 7 ("Google Ads") is in user 3 (taker)'s account; 3 traffic source account(s), 1 removed, 1200 click(s), 14 conversion(s) of user 2 (owner) name it. This moves it to user 2 (owner).`) {
			t.Errorf("the question should say what moves where: %q", stderr)
		}
	})
	t.Run("nobody to answer moves nothing", func(t *testing.T) {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		answerPrompts(t, "")
		_, _, err := executeCommand("system", "traffic-source-owners", "repair", "7")
		assertValidationError(t, err)
		if !strings.Contains(hintFor(err), "--force") || len(*seen) != 1 {
			t.Errorf("hint %q, requests %+v", hintFor(err), *seen)
		}
	})
	t.Run("yes sends the source and what the report showed", func(t *testing.T) {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		answerPrompts(t, "y\n")
		_, stderr, err := executeCommand("system", "traffic-source-owners", "repair", "7")
		if err != nil {
			t.Fatal(err)
		}
		if len(*seen) != 2 || (*seen)[1].Method != "POST" || (*seen)[1].Path != "/system/traffic-source-owners/repair" {
			t.Fatalf("requests = %+v", *seen)
		}
		assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":7,"owner_user_id":3,"repair_to_user_id":2}`)
		if !strings.Contains(stderr, `Moved traffic source 7 ("Google Ads") from user 3 to user 2`) {
			t.Errorf("stderr = %q", stderr)
		}
	})
	t.Run("--force asks nothing", func(t *testing.T) {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		answerPrompts(t, "")
		if _, _, err := executeCommand("system", "traffic-source-owners", "repair", "7", "--force"); err != nil || len(*seen) != 2 {
			t.Errorf("err %v, requests %+v", err, *seen)
		}
	})
}

func TestTrafficSourceOwnersRepairOfAnAmbiguousSourceNeedsItsUser(t *testing.T) {
	seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	_, _, err := executeCommand("system", "traffic-source-owners", "repair", "9", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "ambiguous (owner_has_accounts, saved_after_rename)") || !strings.Contains(err.Error(), "one of users 4") ||
		!strings.Contains(hintFor(err), "--to-user-id") || len(*seen) != 1 {
		t.Errorf("error %q, hint %q, requests %+v: want the reasons, the users and no write", err, hintFor(err), *seen)
	}

	seen = updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "9", "--to-user-id", "5", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "no traffic source account of user 5") || len(*seen) != 1 {
		t.Errorf("error %q, requests %+v", err, *seen)
	}

	seen = updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	if _, _, err := executeCommand("system", "traffic-source-owners", "repair", "9", "--to-user-id", "4", "--force"); err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 2 {
		t.Fatalf("requests = %+v", *seen)
	}
	assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":9,"owner_user_id":1,"to_user_id":4}`)

	seen = updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "9", "--to-user-id", "6", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "user 6 is deleted") || len(*seen) != 1 {
		t.Errorf("a deleted user named: error %q, requests %+v", err, *seen)
	}

	seen = updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "8", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "traffic source 8 is not in the report") || len(*seen) != 1 {
		t.Errorf("error %q, requests %+v", err, *seen)
	}
}

func TestTrafficSourceOwnersRepairRefusesBadArgumentsBeforeAnyRequest(t *testing.T) {
	t.Cleanup(func() { api.SetStagedMode(false) })
	for _, args := range [][]string{
		{},
		{"abc"},
		{"07"},
		{"0"},
		{"16777216"},
		{"7", "--to-user-id", "x"},
		{"7", "--to-user-id", "0"},
		{"7", "--to-user-id", "1.5"},
		{"--all", "7"},
		{"--all", "--to-user-id", "2"},
		{"--to-user-id", "2"},
	} {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		_, _, err := executeCommand(append([]string{"system", "traffic-source-owners", "repair", "--force"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(*seen) != 0 {
			t.Errorf("%v: hint %q, sent %+v", args, hintFor(err), *seen)
		}
	}
	seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
	_, _, err := executeCommand("--staged", "system", "traffic-source-owners", "repair", "7", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "--staged cannot apply") || len(*seen) != 0 {
		t.Errorf("error %q, sent %+v under --staged", err, *seen)
	}
}

// Sources 7 and 12 go to user 2 and 13 to user 5; 9 is ambiguous.
var tsoReportAllBody = strings.Replace(tsoReportBody, `],"repairable":1`, `,
 {"ppc_network_id":12,"ppc_network_name":"Bing","removed":false,"ppc_network_time":1100000000,
  "owner":{"user_id":3,"user_name":"taker","user_deleted":false,"ppc_accounts":0,"removed_ppc_accounts":0,"ppc_account_ids":[],"last_ppc_account_time":null,"clicks":0,"conversions":0},
  "named_by":[{"user_id":2,"user_name":"owner","user_deleted":false,"ppc_accounts":1,"removed_ppc_accounts":0,"ppc_account_ids":[12],"last_ppc_account_time":1000000000,"clicks":0,"conversions":0}],
  "repair_to_user_id":2,"ambiguous":[]},
 {"ppc_network_id":13,"ppc_network_name":"Meta","removed":false,"ppc_network_time":1100000000,
  "owner":{"user_id":3,"user_name":"taker","user_deleted":false,"ppc_accounts":0,"removed_ppc_accounts":0,"ppc_account_ids":[],"last_ppc_account_time":null,"clicks":0,"conversions":0},
  "named_by":[{"user_id":5,"user_name":"admin2","user_deleted":false,"ppc_accounts":1,"removed_ppc_accounts":0,"ppc_account_ids":[13],"last_ppc_account_time":1000000000,"clicks":0,"conversions":0}],
  "repair_to_user_id":5,"ambiguous":[]}],"repairable":3`, 1)

func tsoRepairedAs(id int, name string, to int) string {
	return fmt.Sprintf(`{"data":{"repaired":[{"ppc_network_id":%d,"ppc_network_name":%q,"removed":false,"from_user_id":3,"to_user_id":%d,"ppc_accounts":1,"queued_ppc_accounts":1}],"skipped":[]}}`, id, name, to)
}

// tsoAllServer answers the report, then each repair by its source.
func tsoAllServer(t *testing.T, answers map[int][2]interface{}) *[]updateRequest {
	return updateServer(t, func(r updateRequest) (int, string) {
		if r.Method == "GET" {
			return 200, tsoReportAllBody
		}
		id, _ := r.Body["ppc_network_id"].(float64)
		a, ok := answers[int(id)]
		if !ok {
			t.Errorf("a repair of source %v was sent: %v", r.Body["ppc_network_id"], r.Body)
			return 500, `{"error":true,"status":500,"message":"unexpected"}`
		}
		return a[0].(int), a[1].(string)
	})
}

func TestTrafficSourceOwnersRepairAllMovesWhatItShowedOneByOne(t *testing.T) {
	seen := tsoAllServer(t, map[int][2]interface{}{
		7:  {200, tsoRepairedAs(7, "Google Ads", 2)},
		12: {422, `{"error":true,"status":422,"message":"Traffic source 12 is now in user 4's account, not in user 3's as the report you read said. Nothing was moved. Read the report again.","field_errors":{"owner_user_id":"…"}}`},
		13: {403, `{"error":true,"status":403,"message":"Changing an Admin or Super user account needs the 'add_edit_delete_admin' permission, which only the Super user role has."}`},
	})
	answerPrompts(t, "y\n")
	stdout, stderr, err := executeCommand("system", "traffic-source-owners", "repair", "--all", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 4 {
		t.Fatalf("requests = %+v", *seen)
	}
	assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":7,"owner_user_id":3,"repair_to_user_id":2}`)
	assertTsoBody(t, (*seen)[2].Body, `{"ppc_network_id":12,"owner_user_id":3,"repair_to_user_id":2}`)
	assertTsoBody(t, (*seen)[3].Body, `{"ppc_network_id":13,"owner_user_id":3,"repair_to_user_id":5}`)
	for _, want := range []string{"This moves it to user 2 (owner).", "This moves it to user 5 (admin2).", "1 ambiguous source(s) are left as they are",
		"Move these 3 traffic source(s)?", "Moved 1 traffic source(s); 3 left as they are"} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr lacks %q:\n%s", want, stderr)
		}
	}
	var answer struct {
		Data struct {
			Repaired []tsoRepaired `json:"repaired"`
			Skipped  []tsoSkipped  `json:"skipped"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
		t.Fatalf("stdout is not the answer: %v\n%s", err, stdout)
	}
	if len(answer.Data.Repaired) != 1 || answer.Data.Repaired[0].PpcNetworkID != 7 || answer.Data.Repaired[0].To != 2 {
		t.Errorf("repaired = %+v", answer.Data.Repaired)
	}
	reasons := map[int]string{}
	for _, s := range answer.Data.Skipped {
		reasons[s.PpcNetworkID] = s.Reason
	}
	if !reflect.DeepEqual(reasons, map[int]string{9: "ambiguous", 12: "changed", 13: "not_permitted"}) {
		t.Errorf("skipped = %+v", answer.Data.Skipped)
	}
	if !strings.Contains(stdout, "not in user 3's as the report you read said") {
		t.Errorf("a changed source carries the server's reason:\n%s", stdout)
	}

	seen = updateServer(t, func(updateRequest) (int, string) {
		return 200, strings.Replace(tsoReportBody, `"repair_to_user_id":2`, `"repair_to_user_id":null`, 1)
	})
	stdout, stderr, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force", "--json")
	if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Nothing was sent") {
		t.Errorf("nothing repairable sends nothing: err %v, requests %+v, stderr %q", err, *seen, stderr)
	}
	// Under --json the answer is still a document: none moved, each skipped.
	answer.Data.Repaired, answer.Data.Skipped = nil, nil
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil || answer.Data.Repaired == nil || len(answer.Data.Repaired) != 0 || len(answer.Data.Skipped) != 2 {
		t.Errorf("nothing repairable still answers {repaired: [], skipped: [...]}: err %v, %+v\n%s", err, answer.Data, stdout)
	}

	updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{"sources":[],"repairable":0,"ambiguous":0}}` })
	stdout, _, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force", "--json")
	answer.Data.Repaired, answer.Data.Skipped = nil, nil
	if err != nil || json.Unmarshal([]byte(stdout), &answer) != nil || answer.Data.Repaired == nil || answer.Data.Skipped == nil ||
		len(answer.Data.Repaired)+len(answer.Data.Skipped) != 0 {
		t.Errorf("an empty report answers {repaired: [], skipped: []}: err %v, %+v\n%s", err, answer.Data, stdout)
	}
}

func TestTrafficSourceOwnersRepairAllStopsAtAFailureAndNamesWhatMoved(t *testing.T) {
	failed := `{"error":true,"status":500,"message":"traffic source owners: Lock wait timeout exceeded"}`
	seen := tsoAllServer(t, map[int][2]interface{}{7: {200, tsoRepairedAs(7, "Google Ads", 2)}, 12: {500, failed}})
	stdout, _, err := executeCommand("system", "traffic-source-owners", "repair", "--all", "--force")
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("exit %d (%v)", code, err)
	}
	if !strings.Contains(hintFor(err), "Traffic source(s) 7 were moved before traffic source 12 failed") || !strings.Contains(hintFor(err), "lists what remains") {
		t.Errorf("hint %q", hintFor(err))
	}
	if len(*seen) != 3 || stdout != "" {
		t.Errorf("nothing after the failure is sent, and stdout stays empty: requests %+v, stdout %q", *seen, stdout)
	}

	tsoAllServer(t, map[int][2]interface{}{7: {500, failed}})
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force")
	if code := exitCodeForError(err); code != ExitServer || strings.Contains(hintFor(err), "were moved") {
		t.Errorf("a failure before any move: exit %d, hint %q", code, hintFor(err))
	}

	tsoAllServer(t, map[int][2]interface{}{7: {200, tsoRepairedAs(12, "Bing", 2)}})
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force")
	if code := exitCodeForError(err); code != ExitServer || !strings.Contains(err.Error(), "not 7") {
		t.Errorf("an answer naming another source: exit %d (%v)", code, err)
	}
}

func TestTrafficSourceOwnersRepairRefusalsSayNothingMoved(t *testing.T) {
	updateServer(t, tsoResponder(func(updateRequest) (int, string) {
		return 422, `{"error":true,"status":422,"message":"Traffic source 7 changed after it was read. Nothing was moved. Read the report again.","field_errors":{"ppc_network_id":"Traffic source 7 changed after it was read."}}`
	}))
	_, _, err := executeCommand("system", "traffic-source-owners", "repair", "7", "--force")
	if code := exitCodeForError(err); code != ExitValidation || !strings.Contains(hintFor(err), "Nothing was moved") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}

	updateServer(t, tsoResponder(func(updateRequest) (int, string) {
		return 403, `{"error":true,"status":403,"message":"Changing an Admin or Super user account needs the 'add_edit_delete_admin' permission, which only the Super user role has."}`
	}))
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "7", "--force")
	if code := exitCodeForError(err); code != ExitAuth || !strings.Contains(hintFor(err), "user 1's key") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}
}

// Source 9 as a split can take it: user 6, deleted in tsoReportBody, is not.
var tsoSplitReportBody = strings.Replace(strings.Replace(tsoReportBody, `"user_name":"gone","user_deleted":true`, `"user_name":"other","user_deleted":false`, 1),
	`"ambiguous":["owner_has_accounts","saved_after_rename"]}`, `"ambiguous":["owner_has_accounts","several_users"],"custom_variables":2}`, 1)

const tsoSplitOne = `{"data":{"ppc_network_id":9,"ppc_network_name":"Shared","removed":true,"owner_user_id":1,"copies":[` +
	`{"user_id":4,"ppc_network_id":21,"ppc_account_ids":[10],"custom_variables":2},` +
	`{"user_id":6,"ppc_network_id":22,"ppc_account_ids":[11],"custom_variables":2}],"queued_ppc_accounts":2}}`

func tsoSplitResponder(write func(r updateRequest) (int, string)) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		if r.Method == "GET" {
			return 200, tsoSplitReportBody
		}
		return write(r)
	}
}

func TestTrafficSourceOwnersSplitAsksThenSendsWhatTheReportShowed(t *testing.T) {
	t.Run("no cancels after the read", func(t *testing.T) {
		seen := updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
		answerPrompts(t, "n\n")
		_, stderr, err := executeCommand("system", "traffic-source-owners", "split", "9")
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Cancelled") {
			t.Errorf("err %v, requests %+v, stderr %q", err, *seen, stderr)
		}
		want := `Traffic source 9 ("Shared") stays in user 1 (admin)'s account. Each of these users gets a copy of their own, named "Shared" with 2 custom variable(s), removed as it is, and their traffic source accounts move onto it: user 4 (sub, switched off): 1 traffic source account(s), 30 click(s), 1 conversion(s); user 6 (other): 1 traffic source account(s), 0 click(s), 0 conversion(s).`
		if !strings.Contains(stderr, want) {
			t.Errorf("the question should say what is copied and what moves:\n%s", stderr)
		}
	})
	t.Run("yes sends the source, its owner and the users shown", func(t *testing.T) {
		seen := updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
		answerPrompts(t, "y\n")
		stdout, stderr, err := executeCommand("system", "traffic-source-owners", "split", "9", "--json")
		if err != nil {
			t.Fatal(err)
		}
		if len(*seen) != 2 || (*seen)[1].Method != "POST" || (*seen)[1].Path != "/system/traffic-source-owners/split" {
			t.Fatalf("requests = %+v", *seen)
		}
		assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":9,"owner_user_id":1,"copy_to_user_ids":[4,6]}`)
		if !strings.Contains(stdout, `"copies"`) {
			t.Errorf("--json prints the answer:\n%s", stdout)
		}
		if !strings.Contains(stderr, `Split traffic source 9 ("Shared"): it stays in user 1's account; user 4's 1 account(s) now name traffic source 21; user 6's 1 account(s) now name traffic source 22.`) {
			t.Errorf("stderr = %q", stderr)
		}
	})
	t.Run("--force asks nothing", func(t *testing.T) {
		seen := updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
		answerPrompts(t, "")
		if _, _, err := executeCommand("system", "traffic-source-owners", "split", "9", "--force"); err != nil || len(*seen) != 2 {
			t.Errorf("err %v, requests %+v", err, *seen)
		}
	})
}

func TestTrafficSourceOwnersSplitRefusesWhatItCannotCopyBeforeAnyWrite(t *testing.T) {
	seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
	_, _, err := executeCommand("system", "traffic-source-owners", "split", "9", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "user 6, whose traffic source accounts name traffic source 9, is deleted") ||
		!strings.Contains(hintFor(err), "repair 9 --to-user-id") || len(*seen) != 1 {
		t.Errorf("a deleted user named: error %q, hint %q, requests %+v", err, hintFor(err), *seen)
	}

	seen = updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
	_, _, err = executeCommand("system", "traffic-source-owners", "split", "8", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "traffic source 8 is not in the report") || len(*seen) != 1 {
		t.Errorf("error %q, requests %+v", err, *seen)
	}

	t.Cleanup(func() { api.SetStagedMode(false) })
	for _, args := range [][]string{{}, {"abc"}, {"07"}, {"0"}, {"16777216"}} {
		seen := updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
		_, _, err := executeCommand(append([]string{"system", "traffic-source-owners", "split", "--force"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(*seen) != 0 {
			t.Errorf("%v: hint %q, sent %+v", args, hintFor(err), *seen)
		}
	}
	seen = updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
	_, _, err = executeCommand("system", "traffic-source-owners", "split", "9", "10", "--force")
	if err == nil || len(*seen) != 0 {
		t.Errorf("two sources: err %v, sent %+v", err, *seen)
	}
	seen = updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) { return 200, tsoSplitOne }))
	_, _, err = executeCommand("--staged", "system", "traffic-source-owners", "split", "9", "--force")
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "--staged cannot apply") || len(*seen) != 0 {
		t.Errorf("error %q, sent %+v under --staged", err, *seen)
	}
}

func TestTrafficSourceOwnersSplitRefusalsSayNothingWasCopied(t *testing.T) {
	updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) {
		return 422, `{"error":true,"status":422,"message":"Traffic source 9 would now give a copy to users 4, not to users 4, 6 as the report you read said. Nothing was copied. Read the report again.","field_errors":{"copy_to_user_ids":"…"}}`
	}))
	_, _, err := executeCommand("system", "traffic-source-owners", "split", "9", "--force")
	if code := exitCodeForError(err); code != ExitValidation || !strings.Contains(hintFor(err), "Nothing was copied") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}

	updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) {
		return 403, `{"error":true,"status":403,"message":"Changing an Admin or Super user account needs the 'add_edit_delete_admin' permission, which only the Super user role has."}`
	}))
	_, _, err = executeCommand("system", "traffic-source-owners", "split", "9", "--force")
	if code := exitCodeForError(err); code != ExitAuth || !strings.Contains(hintFor(err), "user 1's key") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}

	updateServer(t, tsoSplitResponder(func(updateRequest) (int, string) {
		return 200, strings.Replace(tsoSplitOne, `"ppc_network_id":9,`, `"ppc_network_id":12,`, 1)
	}))
	stdout, _, err := executeCommand("system", "traffic-source-owners", "split", "9", "--force")
	if code := exitCodeForError(err); code != ExitServer || stdout != "" {
		t.Errorf("an answer naming another source: exit %d (%v), stdout %q", code, err, stdout)
	}

	updateServer(t, func(r updateRequest) (int, string) {
		if r.Method == "GET" {
			return 200, tsoSplitReportBody
		}
		return 404, `{"error":true,"status":404,"message":"Not found"}`
	})
	_, _, err = executeCommand("system", "traffic-source-owners", "split", "9", "--force")
	if !strings.Contains(hintFor(err), "features.traffic_source_owners") {
		t.Errorf("a server without the split: hint %q", hintFor(err))
	}
}
