package cmd

import (
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/spf13/cobra"
)

// The real update command, its flags reset after the test.
func destinationFlagsCmd(t *testing.T, args ...string) *cobra.Command {
	t.Helper()
	c := destinationUpdateCmd
	resetAllFlags(c)
	t.Cleanup(func() { resetAllFlags(c) })
	if err := c.ParseFlags(args); err != nil {
		t.Fatalf("parse %v: %v", args, err)
	}
	return c
}

func TestDestinationSettingsChangeOnlyTheFlagsGiven(t *testing.T) {
	stored := map[string]interface{}{
		"measurement_id": "G-ABC123", "event_name": "purchase", "campaign_ids": []interface{}{12.0},
		"consent": map[string]interface{}{"ad_user_data": "granted", "ad_personalization": "denied"},
	}
	s, err := destinationSettings(destinationFlagsCmd(t, "--event-name", "sign_up", "--all-campaigns", "--ad-personalization", "unset"), stored)
	if err != nil {
		t.Fatal(err)
	}
	if s["measurement_id"] != "G-ABC123" || s["event_name"] != "sign_up" {
		t.Errorf("settings = %v", s)
	}
	if !reflect.DeepEqual(s["campaign_ids"], []int{}) {
		t.Errorf("--all-campaigns should clear the filter, got %v", s["campaign_ids"])
	}
	if !reflect.DeepEqual(s["consent"], map[string]interface{}{"ad_user_data": "granted"}) {
		t.Errorf("unset should drop only that signal, got %v", s["consent"])
	}
}

func TestDestinationSettingsRefuseABadParamWithAHint(t *testing.T) {
	_, err := destinationSettings(destinationFlagsCmd(t, "--param", "email=customer_email"), nil)
	if err == nil {
		t.Fatal("a param naming no click field was accepted")
	}
	if exitCodeForError(err) != 1 || hintFor(err) == "" {
		t.Errorf("exit %d, hint %q", exitCodeForError(err), hintFor(err))
	}
	if _, err := destinationSettings(destinationFlagsCmd(t, "--all-campaigns", "--campaign-id", "3"), nil); err == nil {
		t.Error("--all-campaigns with --campaign-id was accepted")
	}
}

func TestReadAPISecretTrimsAndRefusesAnEmptyFile(t *testing.T) {
	dir := t.TempDir()
	full := filepath.Join(dir, "secret.txt")
	if err := os.WriteFile(full, []byte("  Sx9-secretAPIvalue\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got, err := readAPISecret(full); err != nil || got != "Sx9-secretAPIvalue" {
		t.Errorf("readAPISecret = %q, %v", got, err)
	}
	empty := filepath.Join(dir, "empty.txt")
	if err := os.WriteFile(empty, []byte("\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := readAPISecret(empty); err == nil || hintFor(err) == "" {
		t.Errorf("an empty secret file: err %v, hint %q", err, hintFor(err))
	}
}

// The real create command, its flags reset after the test.
func destinationCreateFlagsCmd(t *testing.T, args ...string) *cobra.Command {
	t.Helper()
	c := destinationCreateCmd
	resetAllFlags(c)
	t.Cleanup(func() { resetAllFlags(c) })
	if err := c.ParseFlags(args); err != nil {
		t.Fatalf("parse %v: %v", args, err)
	}
	return c
}

// A flag of the other destination type is refused before anything is read or
// sent, with a hint, never dropped in silence.
func TestDestinationCreateRefusesTheOtherTypesFlags(t *testing.T) {
	cases := map[string][]string{
		"google_ads credentials on ga4":    {"--name", "S", "--measurement-id", "G-ABC123", "--api-secret-file", "x", "--credentials-file", "g.json"},
		"google_ads customer on ga4":       {"--name", "S", "--measurement-id", "G-ABC123", "--api-secret-file", "x", "--customer-id", "123-456-7890"},
		"ga4 measurement id on google_ads": {"--type", "google_ads", "--name", "S", "--customer-id", "1234567890", "--conversion-action", "987654", "--credentials-file", "g.json", "--measurement-id", "G-ABC123"},
		"ga4 secret on google_ads":         {"--type", "google_ads", "--name", "S", "--customer-id", "1234567890", "--conversion-action", "987654", "--credentials-file", "g.json", "--api-secret-file", "x"},
	}
	for name, args := range cases {
		c := destinationCreateFlagsCmd(t, args...)
		err := c.RunE(c, nil)
		if err == nil {
			t.Fatalf("%s: accepted", name)
		}
		if exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%s: exit %d, hint %q", name, exitCodeForError(err), hintFor(err))
		}
		if !strings.Contains(err.Error(), "destinations; this one is") {
			t.Errorf("%s: %v", name, err)
		}
	}
	err := destinationCreateFlagsCmd(t, "--name", "S", "--measurement-id", "G-ABC123", "--api-secret-file", "x", "--credentials-file", "g.json").RunE(destinationCreateCmd, nil)
	if !strings.Contains(hintFor(err), "--type google_ads") {
		t.Errorf("the hint should name --type google_ads, got %q", hintFor(err))
	}
}

func TestDestinationUpdateRefusesBothCredentialFiles(t *testing.T) {
	c := destinationFlagsCmd(t, "--api-secret-file", "s.txt", "--credentials-file", "g.json")
	err := c.RunE(c, []string{"3"})
	if err == nil || !strings.Contains(err.Error(), "exclusive") {
		t.Fatalf("both credential files were accepted: %v", err)
	}
	if exitCodeForError(err) != 1 || !strings.Contains(hintFor(err), "p202 destination get 3") {
		t.Errorf("exit %d, hint %q", exitCodeForError(err), hintFor(err))
	}
}

// update checks the flags against the stored type it reads.
func TestDestinationFlagsForTheStoredType(t *testing.T) {
	if err := destinationFlagsFor(destinationFlagsCmd(t, "--customer-id", "1234567890"), "ga4", "Leave --%s out."); err == nil ||
		exitCodeForError(err) != 1 || hintFor(err) != "Leave --customer-id out." {
		t.Errorf("a Google Ads flag on a GA4 destination: %v (hint %q)", err, hintFor(err))
	}
	if err := destinationFlagsFor(destinationFlagsCmd(t, "--param", "campaign=utm_campaign"), "google_ads", "Leave --%s out."); err == nil {
		t.Error("a GA4 flag on a Google Ads destination was accepted")
	}
	if err := destinationFlagsFor(destinationFlagsCmd(t, "--customer-id", "1234567890", "--send-user-data"), "google_ads", "x"); err != nil {
		t.Errorf("its own and common flags: %v", err)
	}
}

func TestCampaignConversionActionsMergeIntoTheStoredOnes(t *testing.T) {
	stored := map[string]interface{}{"campaign_conversion_actions": map[string]interface{}{"14": "111", "20": "222"}}
	s, err := destinationSettings(destinationFlagsCmd(t, "--campaign-conversion-action", "12=987655", "--campaign-conversion-action", "14="), stored)
	if err != nil {
		t.Fatal(err)
	}
	want := map[string]interface{}{"12": "987655", "20": "222"}
	if !reflect.DeepEqual(s["campaign_conversion_actions"], want) {
		t.Errorf("got %v, want %v (12 added, 14 removed, 20 kept)", s["campaign_conversion_actions"], want)
	}
	if !reflect.DeepEqual(stored["campaign_conversion_actions"], map[string]interface{}{"14": "111", "20": "222"}) {
		t.Error("the stored map was changed in place")
	}
	if _, err := destinationSettings(destinationFlagsCmd(t, "--campaign-conversion-action", "12="), nil); err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
		t.Errorf("12= on a create should be refused with a hint: %v", err)
	}
}

// connect asks this install for the consent link and prints it: one POST to
// the destination's oauth/start, nothing else, and the answer as JSON.
func TestDestinationConnectPostsOAuthStartAndPrintsTheLink(t *testing.T) {
	var calls []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.Method+" "+r.URL.Path)
		w.Write([]byte(`{"data":{"authorisation_url":"https://accounts.google.com/o/oauth2/v2/auth?state=v1.1.7.x","redirect_uri":"https://track.example/tracking202/setup/destination-oauth.php","expires_at":1767225600}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("destination", "connect", "7", "--json")
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	if !reflect.DeepEqual(calls, []string{"POST /api/v3/destinations/7/oauth/start"}) {
		t.Errorf("requests = %v", calls)
	}
	if !strings.Contains(out, "accounts.google.com/o/oauth2/v2/auth") || !strings.Contains(out, "destination-oauth.php") {
		t.Errorf("the consent link and redirect URI should be printed, got %s", out)
	}

	calls = nil
	_, _, err = executeCommand("destination", "connect", "seven")
	if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
		t.Errorf("a bad id: err %v, exit %d, hint %q", err, exitCodeForError(err), hintFor(err))
	}
	if len(calls) != 0 {
		t.Errorf("a bad id reached the server: %v", calls)
	}
}

// resend and backfill send exactly the body the route reads, and refuse a
// call that names nothing (or both) before it reaches the server.
func TestDestinationResendAndBackfillPostWhatWasAsked(t *testing.T) {
	var calls []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		calls = append(calls, r.Method+" "+r.URL.Path+" "+string(body))
		w.Write([]byte(`{"data":{"queued":2,"more":false}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	for _, args := range [][]string{
		{"destination", "resend", "7", "--status", "failed", "--json"},
		{"destination", "resend", "7", "--delivery-id", "4812", "--json"},
		{"destination", "backfill", "7", "--days", "30", "--json"},
	} {
		out, _, err := executeCommand(args...)
		if err != nil {
			t.Fatalf("%v: %v", args, err)
		}
		if !strings.Contains(out, `"queued"`) {
			t.Errorf("%v should print the answer, got %s", args, out)
		}
	}
	want := []string{
		`POST /api/v3/destinations/7/deliveries/resend {"status":"failed"}`,
		`POST /api/v3/destinations/7/deliveries/resend {"delivery_id":4812}`,
		`POST /api/v3/destinations/7/backfill {"days":30}`,
	}
	if !reflect.DeepEqual(calls, want) {
		t.Errorf("requests = %v, want %v", calls, want)
	}

	calls = nil
	for _, args := range [][]string{
		{"destination", "resend", "7"},
		{"destination", "resend", "7", "--status", "failed", "--delivery-id", "3"},
		{"destination", "resend", "7", "--status", "sent"},
		{"destination", "resend", "7", "--delivery-id", "0"},
		{"destination", "backfill", "7"},
		{"destination", "backfill", "7", "--days", "91"},
		{"destination", "backfill", "seven", "--days", "3"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%v: err %v, exit %d, hint %q", args, err, exitCodeForError(err), hintFor(err))
		}
	}
	if len(calls) != 0 {
		t.Errorf("a refused call reached the server: %v", calls)
	}
}
