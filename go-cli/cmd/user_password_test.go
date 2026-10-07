package cmd

import (
	"strings"
	"testing"
)

// With no terminal, a password is read as a line of stdin: an agent or a
// script has a way in that is not a flag value in shell history.
func TestUserCreateReadsThePasswordFromPipedStdin(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{"user_id":9}}`)
	answerPrompts(t, "piped-secret-1\n")

	if _, _, err := executeCommand("user", "create", "--user_name", "ann", "--user_email", "ann@example.com"); err != nil {
		t.Fatalf("user create: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Body["user_pass"] != "piped-secret-1" {
		t.Fatalf("requests = %+v, want one create carrying the piped password", *seen)
	}
}

func TestUserCreateWithAnEmptyPipeRefusesBeforeAnyRequest(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{}}`)
	answerPrompts(t, "")

	_, _, err := executeCommand("user", "create", "--user_name", "ann", "--user_email", "ann@example.com")
	if err == nil || !strings.Contains(err.Error(), "password is required") {
		t.Fatalf("err = %v, want the missing password named", err)
	}
	if hint := hintFor(err); !strings.Contains(hint, "pipe it on stdin") {
		t.Errorf("hint = %q, want it to say how to pass the password", hint)
	}
	if len(*seen) != 0 {
		t.Errorf("requests = %+v, want none", *seen)
	}
}

// Your own password: the current one is read first, the new one second.
func TestUserUpdateReadsCurrentThenNewPasswordFromThePipe(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"user_id":2}}`)
	answerPrompts(t, "old-secret-1\nnew-secret-1\n")

	if _, _, err := executeCommand("user", "update", "2", "--current-password", "--set-password"); err != nil {
		t.Fatalf("user update: %v", err)
	}
	if len(*seen) != 1 {
		t.Fatalf("requests = %+v, want one", *seen)
	}
	body := (*seen)[0].Body
	if body["current_password"] != "old-secret-1" || body["user_pass"] != "new-secret-1" {
		t.Errorf("body = %v, want current then new from the pipe", body)
	}
}

// A piped change of your own password without --current-password gets the
// server's 422 back with the way to send it, and is not retried blind.
func TestUserUpdateNamesCurrentPasswordWhenTheServerAsksForIt(t *testing.T) {
	_, seen := goalServer(t, 422, `{"error":true,"status":422,"message":"Changing your own password needs your current password",`+
		`"field_errors":{"current_password":"Required when you change your own password"}}`)
	answerPrompts(t, "new-secret-1\n")

	_, _, err := executeCommand("user", "update", "2", "--set-password")
	if err == nil {
		t.Fatal("want the 422")
	}
	if hint := hintFor(err); !strings.Contains(hint, "--current-password") {
		t.Errorf("hint = %q, want --current-password named", hint)
	}
	if len(*seen) != 1 {
		t.Errorf("requests = %d, want exactly one (no retry without an answer)", len(*seen))
	}
}

func TestUserUpdatePasswordFlagMisuseIsRefusedBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args  []string
		stdin string
		want  string
	}{
		{[]string{"user", "update", "2", "--set-password", "--user_pass", "x12345678"}, "", "both set the password"},
		{[]string{"user", "update", "2", "--current-password"}, "old-secret-1\n", "only needed with a new password"},
		{[]string{"user", "update", "2", "--set-password"}, "\n", "empty password"},
	}
	for _, tc := range cases {
		t.Run(strings.Join(tc.args[2:], " "), func(t *testing.T) {
			_, seen := goalServer(t, 200, `{"data":{}}`)
			answerPrompts(t, tc.stdin)
			_, _, err := executeCommand(tc.args...)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			if len(*seen) != 0 {
				t.Errorf("requests = %+v, want none", *seen)
			}
		})
	}
}

func TestReadLineUnbufferedStopsAtTheNewline(t *testing.T) {
	r := strings.NewReader("first\r\nsecond\n")
	first, err := readLineUnbuffered(r)
	if err != nil || first != "first" {
		t.Fatalf("first = %q, %v", first, err)
	}
	rest, _ := readLineUnbuffered(r)
	if rest != "second" {
		t.Errorf("the second line was consumed by the first read: %q", rest)
	}
}
