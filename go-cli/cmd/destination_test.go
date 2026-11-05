package cmd

import (
	"os"
	"path/filepath"
	"reflect"
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
