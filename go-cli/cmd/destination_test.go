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

// accounts and conversion-actions are reads of this install, which asks
// Google; pick posts a name or an ID and the install resolves it.
func TestDestinationPickCommandsSendNamesAndListFromTheInstall(t *testing.T) {
	type call struct{ method, path, query, body string }
	var calls []call
	answer := `{"data":{"accounts":[{"customer_id":"4445556666","login_customer_id":"1112223333","name":"Online Store","currency":"USD","via":"Northwind Agency"}],"notes":[]}}`
	status := http.StatusOK
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		body := make([]byte, r.ContentLength)
		r.Body.Read(body)
		calls = append(calls, call{r.Method, r.URL.Path, r.URL.RawQuery, string(body)})
		w.WriteHeader(status)
		w.Write([]byte(answer))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	for _, c := range []*cobra.Command{destinationPickCmd, destinationConversionActionsCmd} {
		resetAllFlags(c)
		c := c
		t.Cleanup(func() { resetAllFlags(c) })
	}

	out, _, err := executeCommand("destination", "accounts", "7", "--json")
	if err != nil {
		t.Fatalf("accounts: %v", err)
	}
	if len(calls) != 1 || calls[0].method != "GET" || calls[0].path != "/api/v3/destinations/7/google-ads/accounts" {
		t.Errorf("accounts requests = %v", calls)
	}
	if !strings.Contains(out, "Online Store") || !strings.Contains(out, "1112223333") {
		t.Errorf("the accounts should be printed, got %s", out)
	}

	calls = nil
	if _, _, err = executeCommand("destination", "conversion-actions", "7", "--customer-id", "444-555-6666", "--login-customer-id", "1112223333", "--json"); err != nil {
		t.Fatalf("conversion-actions: %v", err)
	}
	if len(calls) != 1 || calls[0].path != "/api/v3/destinations/7/google-ads/conversion-actions" ||
		!strings.Contains(calls[0].query, "customer_id=444-555-6666") || !strings.Contains(calls[0].query, "login_customer_id=1112223333") {
		t.Errorf("conversion-actions requests = %v", calls)
	}

	calls = nil
	resetAllFlags(destinationPickCmd)
	if _, _, err = executeCommand("destination", "pick", "7", "--account", "Online Store", "--action", "Purchase (imported)", "--json"); err != nil {
		t.Fatalf("pick: %v", err)
	}
	if len(calls) != 1 || calls[0].method != "POST" || calls[0].path != "/api/v3/destinations/7/google-ads/pick" {
		t.Fatalf("pick requests = %v", calls)
	}
	if !strings.Contains(calls[0].body, `"account":"Online Store"`) || !strings.Contains(calls[0].body, `"conversion_action":"Purchase (imported)"`) {
		t.Errorf("pick body = %s", calls[0].body)
	}

	// No flag: an empty body, so the install keeps or takes the only choice.
	calls = nil
	resetAllFlags(destinationPickCmd)
	if _, _, err = executeCommand("destination", "pick", "7", "--json"); err != nil {
		t.Fatalf("pick with no flags: %v", err)
	}
	if len(calls) != 1 || calls[0].body != "{}" {
		t.Errorf("pick with no flags sent %v", calls)
	}

	// The server's per-key limit on these reads: the next step is to wait, not to reconnect or re-pick.
	status, answer = http.StatusTooManyRequests,
		`{"error":true,"status":429,"message":"Rate limit exceeded","retry_after_seconds":23,"rate_limit":{"limit":10,"window_seconds":60}}`
	for _, args := range [][]string{
		{"destination", "accounts", "7", "--json"},
		{"destination", "conversion-actions", "7", "--customer-id", "444-555-6666", "--json"},
		{"destination", "pick", "7", "--json"},
	} {
		resetAllFlags(destinationPickCmd)
		resetAllFlags(destinationConversionActionsCmd)
		_, _, err := executeCommand(args...)
		if err == nil {
			t.Fatalf("%v: a 429 was reported as done", args)
		}
		if code, hint := exitCodeForError(err), hintFor(err); code != ExitRateLimited || !strings.Contains(hint, "Wait 23 seconds") || strings.Contains(hint, "connect") {
			t.Errorf("%v: exit %d, hint %q; want %d and the wait", args, code, hint, ExitRateLimited)
		}
	}
}

func TestDestinationPickCommandsRefuseBadInputBeforeTheServerAndHintOnARefusal(t *testing.T) {
	var calls int
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.WriteHeader(http.StatusUnprocessableEntity)
		w.Write([]byte(`{"error":true,"message":"There are 2 to choose from; name the Google Ads account by its name or ID: Online Store (444-555-6666), Demo Store (555-666-7777).","status":422,"field_errors":{"account":"There are 2 to choose from"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	for _, c := range []*cobra.Command{destinationPickCmd, destinationConversionActionsCmd} {
		c := c
		resetAllFlags(c)
		t.Cleanup(func() { resetAllFlags(c) })
	}

	refused := [][]string{
		{"destination", "accounts", "seven"},
		{"destination", "conversion-actions", "0"},
		{"destination", "conversion-actions", "7", "--login-customer-id", "1112223333"},
		{"destination", "conversion-actions", "7", "--customer-id", ""},
		{"destination", "pick", "x"},
		{"destination", "pick", "7", "--account", " "},
		{"destination", "pick", "7", "--action", ""},
	}
	for _, args := range refused {
		resetAllFlags(destinationPickCmd)
		resetAllFlags(destinationConversionActionsCmd)
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%v: err %v, exit %d, hint %q", args, err, exitCodeForError(err), hintFor(err))
		}
	}
	if calls != 0 {
		t.Errorf("bad input reached the server %d times", calls)
	}

	// The install's refusal keeps its category and its words, with where to look next.
	for _, args := range [][]string{{"destination", "pick", "7"}, {"destination", "accounts", "7"}, {"destination", "conversion-actions", "7"}} {
		resetAllFlags(destinationPickCmd)
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 {
			t.Fatalf("%v: a 422 should be a validation error, got %v (exit %d)", args, err, exitCodeForError(err))
		}
		if !strings.Contains(err.Error(), "Online Store (444-555-6666)") || !strings.Contains(hintFor(err), "p202 destination") {
			t.Errorf("%v: message %q, hint %q", args, err.Error(), hintFor(err))
		}
	}
}

func TestAGoogleAdsDestinationIsCreatedWithoutItsIDs(t *testing.T) {
	var bodies []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		body := make([]byte, r.ContentLength)
		r.Body.Read(body)
		bodies = append(bodies, string(body))
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(`{"data":{"destination_id":2,"type":"google_ads","status":"needs_authorisation"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	resetAllFlags(destinationCreateCmd)
	t.Cleanup(func() { resetAllFlags(destinationCreateCmd) })
	creds := filepath.Join(tmp, "google-ads.json")
	if err := os.WriteFile(creds, []byte(`{"developer_token":"devTOKEN_abc123XYZ","oauth_client_id":"1234-abcd.apps.googleusercontent.com","oauth_client_secret":"GOCSPX-clientSecret99"}`), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := executeCommand("destination", "create", "--type", "google_ads", "--name", "Search", "--credentials-file", creds, "--json"); err != nil {
		t.Fatalf("create without IDs: %v", err)
	}
	if len(bodies) != 1 || strings.Contains(bodies[0], "customer_id") || strings.Contains(bodies[0], "conversion_action") {
		t.Errorf("create sent %v", bodies)
	}

	bodies = nil
	resetAllFlags(destinationCreateCmd)
	_, _, err := executeCommand("destination", "create", "--type", "google_ads", "--name", "Search", "--credentials-file", creds, "--conversion-action", "987654")
	if err == nil || exitCodeForError(err) != 1 || !strings.Contains(hintFor(err), "destination pick") {
		t.Errorf("an action without its account: err %v, hint %q", err, hintFor(err))
	}
	if len(bodies) != 0 {
		t.Errorf("it reached the server: %v", bodies)
	}
}

func TestAChatGptAdsDestinationIsCreatedWithItsKeyFromAFile(t *testing.T) {
	var bodies []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		bodies = append(bodies, string(body))
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(`{"data":{"destination_id":3,"type":"openai_ads","status":"ready"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	resetAllFlags(destinationCreateCmd)
	t.Cleanup(func() { resetAllFlags(destinationCreateCmd) })
	keyFile := filepath.Join(tmp, "key.txt")
	if err := os.WriteFile(keyFile, []byte(" oai-capi-Key_0123456789abcdef\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, _, err := executeCommand("destination", "create", "--type", "openai_ads", "--name", "ChatGPT Ads", "--pixel-id", "px_4821",
		"--conversions-api-key-file", keyFile, "--campaign-event-type", "12=lead_created", "--json"); err != nil {
		t.Fatalf("create: %v", err)
	}
	if len(bodies) != 1 {
		t.Fatalf("sent %v", bodies)
	}
	for _, want := range []string{`"type":"openai_ads"`, `"pixel_id":"px_4821"`, `"conversions_api_key":"oai-capi-Key_0123456789abcdef"`, `"event_types":{"12":"lead_created"}`} {
		if !strings.Contains(bodies[0], want) {
			t.Errorf("the body lacks %s: %s", want, bodies[0])
		}
	}
	if strings.Contains(bodies[0], `"scope"`) {
		t.Errorf("no --scope sends none, so the server's default (oppref) applies: %s", bodies[0])
	}

	refused := map[string][]string{
		"a consent flag":             {"--ad-user-data", "granted"},
		"a GA4 flag":                 {"--measurement-id", "G-ABC123"},
		"a custom type's name":       {"--event-type", "lead_created", "--custom-event-name", "demo"},
		"an account without scope":   {"--scope", "all", "--ppc-account-id", "70"},
		"a custom per campaign":      {"--campaign-event-type", "12=custom"},
		"source without its account": {"--scope", "source"},
	}
	for name, extra := range refused {
		bodies = nil
		resetAllFlags(destinationCreateCmd)
		args := append([]string{"destination", "create", "--type", "openai_ads", "--name", "C", "--pixel-id", "px_4821", "--conversions-api-key-file", keyFile}, extra...)
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%s: err %v, hint %q", name, err, hintFor(err))
		}
		if len(bodies) != 0 {
			t.Errorf("%s reached the server: %v", name, bodies)
		}
	}
}

func TestChatGptAdsSettingsMergeOnAnUpdate(t *testing.T) {
	stored := map[string]interface{}{"pixel_id": "px_4821", "scope": "source", "ppc_account_id": 70.0, "event_types": map[string]interface{}{"12": "lead_created", "14": "trial_started"}}
	got, err := destinationSettings(destinationFlagsCmd(t, "--scope", "all", "--campaign-event-type", "12=", "--campaign-event-type", "20=page_viewed"), stored)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := got["ppc_account_id"]; ok || got["scope"] != "all" {
		t.Errorf("scope all drops the account: %v", got)
	}
	if !reflect.DeepEqual(got["event_types"], map[string]interface{}{"14": "trial_started", "20": "page_viewed"}) {
		t.Errorf("event_types %v", got["event_types"])
	}
}
