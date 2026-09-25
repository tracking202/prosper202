package cmd

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDecodeOneJSONRefusesAnythingAfterTheFirstValue(t *testing.T) {
	for _, in := range []string{
		`[{"event_id":"a"}][{"event_id":"b"}]`,
		`{"events":[]} {"events":[]}`,
		"[1]\n[2]\n",
		`{"a":1} x`,
		`{"a":1}}`,
		`{"a":1},`,
		`1 2`,
	} {
		var v interface{}
		if err := decodeOneJSON([]byte(in), &v); !errors.Is(err, errTrailingJSON) {
			t.Errorf("%q: err = %v, want errTrailingJSON", in, err)
		}
	}
	for _, in := range []string{`[1]`, "[1]\n", " \t{\"a\": 3.0}\r\n\n  ", `"x"`} {
		var v interface{}
		if err := decodeOneJSON([]byte(in), &v); err != nil {
			t.Errorf("%q: err = %v, want nil (only whitespace follows)", in, err)
		}
	}
	var v map[string]interface{}
	if err := decodeOneJSON([]byte(`{"n": 3.0}`), &v); err != nil || v["n"] != json.Number("3.0") {
		t.Errorf("numbers are kept as written: %v %#v", err, v)
	}
	if err := decodeOneJSON([]byte(`{"a":`), &v); err == nil || errors.Is(err, errTrailingJSON) {
		t.Errorf("a value that is not JSON is the decoder's own error, got %v", err)
	}
}

// Every command that reads a JSON value from a flag, a file or stdin refuses
// input with more after its first value, as a validation error with a next
// step, and sends nothing: a json.Decoder stops after one value, and the
// rest would otherwise be dropped in silence.
func TestJSONInputsRefuseTrailingValues(t *testing.T) {
	dir := t.TempDir()
	write := func(name, body string) string {
		p := filepath.Join(dir, name)
		if err := os.WriteFile(p, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		return p
	}
	twoLists := write("events.json", `[{"event_id":"a","name":"s"}]`+"\n"+`[{"event_id":"b","name":"s"}]`)
	twoObjects := write("events-obj.json", `{"events":[{"event_id":"a","name":"s"}]}{"events":[{"event_id":"b","name":"s"}]}`)
	garbage := write("events-garbage.json", `[{"event_id":"a","name":"s"}] oops`)
	twoGoals := write("goals.json", `{"name":"A","trigger":{"event":"a"}}`+"\n"+`{"name":"B","trigger":{"event":"b"}}`)
	twoBodies := write("evaluate.json", `{"goals":[],"subject":{},"events":[]} {"goals":[]}`)
	twoPostbacks := write("postback.json", `{"version":"4.0"}{"version":"4.0"}`)

	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"event", "send", "--click-id", "9", "--file", twoLists}, "more than one JSON value", "one list"},
		{[]string{"event", "send", "--click-id", "9", "--file", twoObjects}, "more than one JSON value", "one list"},
		{[]string{"event", "send", "--click-id", "9", "--file", garbage}, "more than one JSON value", "one list"},
		{[]string{"event", "send", "--click-id", "9", "--name", "s", "--id", "e", "--props", `{"plan":"pro"}{"seats":3}`}, "one JSON object", "plan"},
		{[]string{"goal", "create", "--account", "--file", twoGoals}, "more than one JSON value", "once per goal"},
		{[]string{"goal", "create", "--account", "--definition", `{"name":"A","trigger":{"event":"a"}} {}`}, "more than one JSON value", "once per goal"},
		{[]string{"goal", "update", "5", "--file", twoGoals}, "more than one JSON value", "once per goal"},
		{[]string{"goal", "validate", "--file", twoGoals}, "more than one JSON value", "once per goal"},
		{[]string{"goal", "evaluate", "--file", twoBodies}, "more than one JSON value", "its own call"},
		{[]string{"app", "verify", "--file", twoPostbacks}, "more than one JSON value", "one postback per call"},
	}
	for _, tc := range cases {
		_, seen := goalServer(t, 201, `{"data":{}}`)
		_, _, err := executeCommand(tc.args...)
		if err == nil {
			t.Errorf("%v: expected an error", tc.args)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code %d, want %d (validation): %v", tc.args, code, ExitValidation, err)
		}
		if !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: error %q should mention %q", tc.args, err, tc.want)
		}
		if !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q should mention %q", tc.args, hintFor(err), tc.hint)
		}
		for _, r := range *seen {
			if r.Method != "GET" {
				t.Errorf("%v: sent %s %s; nothing may be sent", tc.args, r.Method, r.Path)
			}
		}
	}
}

func TestAppVerifyRefusesJSONNull(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "null.json")
	if err := os.WriteFile(file, []byte("null\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	_, seen := goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("app", "verify", "--file", file)
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(err.Error(), "null") {
		t.Fatalf("err = %v (exit %d), want a validation error naming null", err, exitCodeForError(err))
	}
	if !strings.Contains(hintFor(err), "attribution-signature") {
		t.Errorf("hint %q", hintFor(err))
	}
	for _, r := range *seen {
		if r.Method != "GET" {
			t.Errorf("sent %s %s", r.Method, r.Path)
		}
	}
}
