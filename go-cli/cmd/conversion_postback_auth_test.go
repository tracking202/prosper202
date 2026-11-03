package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The canonical string is PostbackSignature::stringToSign()'s, byte for
// byte: the same vector as PostbackAuthDecisionTest's.
func TestPostbackStringToSignIsTheServers(t *testing.T) {
	pairs := [][2]string{
		{"subid", "123"}, {"amount", "9.99"}, {"txid", "A B+1"}, {"p202_ts", "1800000000"},
		{"b", "10"}, {"b", "9"}, {"~u", "x y"}, {"p202_sig", "ff"},
	}
	want := "P202-POSTBACK-V1\ngpb\namount=9.99&b=10&b=9&p202_ts=1800000000&subid=123&txid=A%20B%2B1&~u=x%20y"
	if got := postbackStringToSign("gpb", pairs); got != want {
		t.Fatalf("string to sign\n%q\nwant\n%q", got, want)
	}
}

// postbackAuthFake records what set and rotate send.
func postbackAuthFake(t *testing.T) *[]string {
	t.Helper()
	var sent []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		sent = append(sent, r.Method+" "+r.URL.Path+" "+string(body))
		w.Write([]byte(`{"data":{"mode":"token"}}`))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &sent
}

func TestPostbackAuthSetSendsTheMode(t *testing.T) {
	sent := postbackAuthFake(t)
	if _, stderr, err := executeCommand("conversion", "postback-auth", "set", "--mode", "signed"); err != nil {
		t.Fatalf("set: %v\nstderr: %s", err, stderr)
	}
	if len(*sent) != 1 || !strings.HasPrefix((*sent)[0], "PUT /api/v3/conversions/postback-auth ") {
		t.Fatalf("sent %v", *sent)
	}
	var body map[string]interface{}
	if err := json.Unmarshal([]byte(strings.SplitN((*sent)[0], " ", 3)[2]), &body); err != nil || body["mode"] != "signed" || len(body) != 1 {
		t.Fatalf("body %v (%v), want {\"mode\":\"signed\"}", body, err)
	}

	_, _, err := executeCommand("conversion", "postback-auth", "set")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "postback-auth get") {
		t.Fatalf("no --mode: %v (exit %d, hint %q)", err, exitCodeForError(err), hintFor(err))
	}
	if _, _, err := executeCommand("conversion", "postback-auth", "set", "--mode", "required"); err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("--mode required: %v, want a validation error", err)
	}
	if len(*sent) != 1 {
		t.Fatalf("a refused set sent %v", (*sent)[1:])
	}
}

func TestPostbackAuthRotateSendsTheCredentialAndTheGrace(t *testing.T) {
	sent := postbackAuthFake(t)
	for _, args := range [][]string{
		{"conversion", "postback-auth", "rotate"},
		{"conversion", "postback-auth", "rotate", "--credential", "signing_secret", "--grace-hours", "0"},
	} {
		if _, stderr, err := executeCommand(args...); err != nil {
			t.Fatalf("%v: %v\nstderr: %s", args, err, stderr)
		}
	}
	want := []map[string]interface{}{
		{"credential": "token", "grace_hours": float64(168)},
		{"credential": "signing_secret", "grace_hours": float64(0)},
	}
	if len(*sent) != 2 {
		t.Fatalf("sent %v", *sent)
	}
	for i, line := range *sent {
		parts := strings.SplitN(line, " ", 3)
		var body map[string]interface{}
		if parts[0] != "POST" || parts[1] != "/api/v3/conversions/postback-auth/rotate" || json.Unmarshal([]byte(parts[2]), &body) != nil ||
			body["credential"] != want[i]["credential"] || body["grace_hours"] != want[i]["grace_hours"] {
			t.Errorf("rotation %d sent %q, want %v", i, line, want[i])
		}
	}
	if _, _, err := executeCommand("conversion", "postback-auth", "rotate", "--grace-hours", "721"); err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("--grace-hours 721: %v, want a validation error", err)
	}
	if _, _, err := executeCommand("conversion", "postback-auth", "rotate", "--credential", "secret"); err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("--credential secret: %v, want a validation error", err)
	}
	if len(*sent) != 2 {
		t.Fatalf("a refused rotation sent %v", (*sent)[2:])
	}
}
