package cmd

import (
	"encoding/json"
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
	t.Run("yes sends the source and nothing else", func(t *testing.T) {
		seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) { return 200, tsoRepairedOne }))
		answerPrompts(t, "y\n")
		_, stderr, err := executeCommand("system", "traffic-source-owners", "repair", "7")
		if err != nil {
			t.Fatal(err)
		}
		if len(*seen) != 2 || (*seen)[1].Method != "POST" || (*seen)[1].Path != "/system/traffic-source-owners/repair" {
			t.Fatalf("requests = %+v", *seen)
		}
		assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":7}`)
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
	assertTsoBody(t, (*seen)[1].Body, `{"ppc_network_id":9,"to_user_id":4}`)

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

func TestTrafficSourceOwnersRepairAllSendsAllAndSaysWhatMoved(t *testing.T) {
	seen := updateServer(t, tsoResponder(func(updateRequest) (int, string) {
		return 200, `{"data":{"repaired":[{"ppc_network_id":7,"ppc_network_name":"Google Ads","removed":false,"from_user_id":3,"to_user_id":2,"ppc_accounts":3,"queued_ppc_accounts":3},` +
			`{"ppc_network_id":11,"ppc_network_name":"New","removed":false,"from_user_id":3,"to_user_id":2,"ppc_accounts":1,"queued_ppc_accounts":1}],` +
			`"skipped":[{"ppc_network_id":9,"ppc_network_name":"Shared","reason":"ambiguous","ambiguous":["owner_has_accounts"],"message":"…"}]}}`
	}))
	answerPrompts(t, "y\n")
	_, stderr, err := executeCommand("system", "traffic-source-owners", "repair", "--all")
	if err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 2 {
		t.Fatalf("requests = %+v", *seen)
	}
	assertTsoBody(t, (*seen)[1].Body, `{"all":true}`)
	for _, want := range []string{"This moves it to user 2 (owner).", "1 ambiguous source(s) are left as they are", "Move these 1 traffic source(s)?",
		"Moved 2 traffic source(s); 1 left as they are", "Moved without being listed above", ": 11."} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr lacks %q:\n%s", want, stderr)
		}
	}

	seen = updateServer(t, func(updateRequest) (int, string) {
		return 200, strings.Replace(tsoReportBody, `"repair_to_user_id":2`, `"repair_to_user_id":null`, 1)
	})
	_, stderr, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force")
	if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Nothing was sent") {
		t.Errorf("nothing repairable sends nothing: err %v, requests %+v, stderr %q", err, *seen, stderr)
	}

	updateServer(t, tsoResponder(func(updateRequest) (int, string) {
		return 500, `{"error":true,"status":500,"message":"Traffic sources 7 were moved back, and moving traffic source 11 failed, so nothing after it was tried."}`
	}))
	_, _, err = executeCommand("system", "traffic-source-owners", "repair", "--all", "--force")
	if code := exitCodeForError(err); code != ExitServer || !strings.Contains(hintFor(err), "lists what remains") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
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
