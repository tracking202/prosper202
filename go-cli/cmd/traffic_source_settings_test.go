package cmd

import (
	"net/http"
	"strings"
	"testing"
)

func TestVariableCommandsSendThePagesFields(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		if r.Method == http.MethodDelete {
			return 204, ""
		}
		return 200, `{"data":{"ppc_variable_id":7,"ppc_network_id":3,"name":"Ad id","parameter":"adid","placeholder":"{ad_id}"}}`
	})

	run := func(args ...string) {
		t.Helper()
		if _, _, err := executeCommand(append(args, "--json")...); err != nil {
			t.Fatalf("%v: %v", args, err)
		}
	}
	run("ppc-network", "variable", "list", "3")
	run("ppc-network", "variable", "create", "3", "--name", "Ad id", "--parameter", "adid", "--placeholder", "{ad_id}", "--idempotency-key", "k1")
	run("ppc-network", "variable", "update", "3", "7", "--placeholder", "{{ad.id}}")
	run("ppc-network", "variable", "delete", "3", "7", "--dry-run")
	run("ppc-network", "variable", "delete", "3", "7", "--force")

	want := []struct {
		method, path string
		body         map[string]interface{}
		dryRun       bool
	}{
		{"GET", "/api/v3/ppc-networks/3/variables", nil, false},
		{"POST", "/api/v3/ppc-networks/3/variables", map[string]interface{}{"name": "Ad id", "parameter": "adid", "placeholder": "{ad_id}"}, false},
		{"PUT", "/api/v3/ppc-networks/3/variables/7", map[string]interface{}{"placeholder": "{{ad.id}}"}, false},
		{"DELETE", "/api/v3/ppc-networks/3/variables/7", nil, true},
		{"DELETE", "/api/v3/ppc-networks/3/variables/7", nil, false},
	}
	if len(*seen) != len(want) {
		t.Fatalf("requests = %+v", *seen)
	}
	for i, w := range want {
		got := (*seen)[i]
		if got.Method != w.method || got.Path != w.path || (got.Query.Get("dry_run") == "1") != w.dryRun {
			t.Errorf("request %d = %s %s %v, want %s %s dry_run=%v", i, got.Method, got.Path, got.Query, w.method, w.path, w.dryRun)
		}
		if len(w.body) != len(got.Body) {
			t.Errorf("request %d body = %v, want %v", i, got.Body, w.body)
		}
		for k, v := range w.body {
			if got.Body[k] != v {
				t.Errorf("request %d %s = %v, want %v", i, k, got.Body[k], v)
			}
		}
	}
}

func TestPixelCommandsSendThePagesFields(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		return 200, `{"data":{"pixel_id":9,"ppc_account_id":4,"pixel_type_id":4,"pixel_type":"Postback","pixel_code":"https://n.example/pb","correction_url":""}}`
	})
	if _, _, err := executeCommand("ppc-account", "pixel", "create", "4", "--type-id", "4", "--code", "https://n.example/pb?c=[[subid]]", "--correction-url", "https://n.example/fix", "--json"); err != nil {
		t.Fatalf("pixel create: %v", err)
	}
	if _, _, err := executeCommand("ppc-account", "pixel", "update", "4", "9", "--correction-url", "", "--json"); err != nil {
		t.Fatalf("pixel update --correction-url '': %v", err)
	}
	create, update := (*seen)[0], (*seen)[1]
	if create.Method != "POST" || create.Path != "/api/v3/ppc-accounts/4/pixels" ||
		create.Body["pixel_type_id"] != "4" || create.Body["pixel_code"] != "https://n.example/pb?c=[[subid]]" || create.Body["correction_url"] != "https://n.example/fix" {
		t.Errorf("create = %+v", create)
	}
	if update.Method != "PUT" || update.Path != "/api/v3/ppc-accounts/4/pixels/9" || len(update.Body) != 1 || update.Body["correction_url"] != "" {
		t.Errorf("update = %+v, want only correction_url, empty: the way to remove it", update)
	}
}

func TestSettingsCommandsRefuseWhatTheyCanBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args []string
		hint string
	}{
		{[]string{"ppc-network", "variable", "create", "3", "--name", "Ad id", "--parameter", "adid"}, "--placeholder '{ad_id}'"},
		{[]string{"ppc-network", "variable", "update", "3", "7"}, ""},
		{[]string{"ppc-network", "variable", "list", "abc"}, ""},
		{[]string{"ppc-account", "pixel", "create", "4", "--code", "https://x.example/"}, "4 Postback (server to server)"},
		{[]string{"ppc-account", "pixel", "create", "4", "--type-id", "9", "--code", "x"}, "4 Postback (server to server)"},
		{[]string{"ppc-account", "pixel", "update", "4", "9"}, ""},
		{[]string{"ppc-account", "pixel", "delete", "4", "x", "--force"}, ""},
	}
	for _, tc := range cases {
		_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, `{"data":{}}` })
		_, _, err := executeCommand(append(tc.args, "--json")...)
		if err == nil {
			t.Fatalf("%v was accepted", tc.args)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d", tc.args, code, ExitValidation)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q lacks %q", tc.args, hintFor(err), tc.hint)
		}
		if len(*seen) != 0 {
			t.Errorf("%v: %d requests before the refusal", tc.args, len(*seen))
		}
	}
}

func TestSettingsWritesCanBeStaged(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		return 202, `{"data":{"change_id":"chg_aabbccddeeff001122334455","status":"staged","method":"POST","path":"/ppc-networks/3/variables"}}`
	})
	stdout, _, err := executeCommand("ppc-network", "variable", "create", "3", "--name", "n", "--parameter", "p", "--placeholder", "{x}", "--staged", "--json")
	if err != nil {
		t.Fatalf("staged create: %v", err)
	}
	if got := (*seen)[0].Query.Get("staged"); got != "1" {
		t.Errorf("staged = %q, want 1", got)
	}
	if !strings.Contains(stdout, "chg_aabbccddeeff001122334455") {
		t.Errorf("the change id is the answer: %s", stdout)
	}
}
