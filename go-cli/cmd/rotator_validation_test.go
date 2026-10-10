package cmd

import (
	"strings"
	"testing"
)

// rule-create sends --status, as rule-update does: a rule can be created
// paused.
func TestRotatorRuleCreateSendsStatus(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"id":7,"rules":[]}}`)
	if _, _, err := executeCommand("rotator", "rule-create", "7", "--rule-name", "paused", "--status", "0",
		"--redirects-json", `[{"redirect_url":"https://a.example/","weight":"100","name":"A"}]`); err != nil {
		t.Fatalf("rule-create: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || (*seen)[0].Body["status"] != "0" {
		t.Fatalf("requests = %+v, want one POST carrying status 0", *seen)
	}
}

// Input the command can judge itself is refused as a validation error
// (exit 1) before the client is built; these were bare errors raised after
// it, so without a configured URL the config error hid them.
func TestRotatorInputErrorsAreValidationErrorsBeforeAnyRequest(t *testing.T) {
	for _, tc := range []struct {
		args []string
		want string
	}{
		{[]string{"rotator", "create"}, "--name is missing"},
		{[]string{"rotator", "update", "7"}, "no fields specified"},
		{[]string{"rotator", "rule-create", "7"}, "--rule-name is missing"},
		{[]string{"rotator", "rule-create", "7", "--rule-name", "r", "--criteria-json", "[{"}, "invalid --criteria-json"},
		{[]string{"rotator", "rule-create", "7", "--rule-name", "r", "--redirects-json", "nope"}, "invalid --redirects-json"},
		{[]string{"rotator", "rule-update", "7"}, "rule id is required"},
		{[]string{"rotator", "rule-update", "7", "3", "--criteria-json", "{"}, "invalid --criteria-json"},
		{[]string{"rotator", "rule-update", "7", "3"}, "no fields specified"},
	} {
		// No configuration at all: a command that built its client before
		// reading its input would fail on the missing URL instead.
		setTestHome(t, t.TempDir())
		_, _, err := executeCommand(tc.args...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.args, err, tc.want)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d", tc.args, code, ExitValidation)
		}
	}
}
