package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Play Integrity for an Android registration: the status read and the
// service-account credential. The mode itself is a registration field
// (`p202 app update <id> --integrity-mode observe|require|off`).
//
// The credential is a private key. It is read from a file (or piped stdin),
// never from a flag value — a flag would land in shell history and process
// listings — and nothing here prints it: the server answers with the
// account's email and key id only.

var appIntegrityCmd = &cobra.Command{
	Use:   "integrity",
	Short: "Play Integrity for an Android app: status, and its Google service-account credential",
	Long: "Play Integrity is opt-in per Android registration. Set the service account first, then\n" +
		"switch the mode:\n\n" +
		"  p202 app integrity credential set 3 --file service-account.json\n" +
		"  p202 app update 3 --integrity-mode observe --integrity-cloud-project-number 123456789012\n" +
		"  p202 app integrity status 3\n\n" +
		"observe records every install's verdict and changes nothing; require attributes (and pays)\n" +
		"an install only once its verdict passes. `p202 app install list 3 --integrity-state invalid`\n" +
		"shows the installs that failed.",
}

var appIntegrityStatusCmd = &cobra.Command{
	Use:   "status <registration-id>",
	Short: "Show the mode, the credential (never its key), verdict counts and today's decodes",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("apps/"+args[0]+"/integrity", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appIntegrityCredentialCmd = &cobra.Command{
	Use:   "credential",
	Short: "Set, rotate or clear the Google service account Play Integrity decodes with",
}

// readCredentialFile reads the service-account key file from --file (or
// stdin for "-" / no flag when piped) and returns its JSON object. Errors
// never quote the content: it is a private key.
func readCredentialFile(file string) (map[string]interface{}, error) {
	var raw []byte
	var err error
	if file == "" || file == "-" {
		if isTerminal(os.Stdin) {
			return nil, validationError("no service-account key file to read: pass --file, or pipe it in").
				WithHint("Download a JSON key for the service account in Google Cloud (IAM › Service accounts › Keys), then `p202 app integrity credential set <id> --file key.json`.")
		}
		raw, err = io.ReadAll(io.LimitReader(os.Stdin, 64*1024))
		if err != nil {
			return nil, validationError("reading the key file from stdin: %v", err)
		}
	} else {
		raw, err = os.ReadFile(file)
		if err != nil {
			return nil, validationError("reading %s: %v", file, err).
				WithHint("Pass the path of the service-account key file (JSON) Google Cloud downloaded.")
		}
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var key map[string]interface{}
	if err := decoder.Decode(&key); err != nil || key == nil {
		return nil, validationError("the key file is not a JSON object").
			WithHint("Use the JSON key file exactly as Google Cloud downloaded it (it starts with {\"type\": \"service_account\", …}).")
	}
	if t, _ := key["type"].(string); t != "service_account" {
		return nil, validationError("the key file's type is %q, not \"service_account\"", t).
			WithHint("Create a key for a SERVICE ACCOUNT (not an OAuth client or user credential) in Google Cloud IAM, as JSON.")
	}
	return key, nil
}

var appIntegrityCredentialSetCmd = &cobra.Command{
	Use:   "set <registration-id> --file <service-account.json>",
	Short: "Set or rotate the service account (the key is stored encrypted and never shown)",
	Long: "Uploads a Google service-account key file for the app. The account must be able to call\n" +
		"the Play Integrity API for the app: the Google Cloud project it belongs to is linked to the\n" +
		"app in Play Console. Setting it again replaces it (rotation). The server stores the key\n" +
		"encrypted and answers with the account's email and key id only.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		// The server refuses to stage this route (a staged change is stored
		// and shown to reviewers, and this body is a private key); refuse
		// here too rather than send it.
		if api.StagedMode() {
			return validationError("--staged cannot apply to `app integrity credential set`: the body is a private key, which a staged change would store and show to reviewers").
				WithHint("Run it without --staged; `p202 app integrity status " + args[0] + "` shows the result.")
		}
		file, _ := cmd.Flags().GetString("file")
		key, err := readCredentialFile(file)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("apps/"+args[0]+"/integrity-credential", map[string]interface{}{"credential": key})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appIntegrityCredentialClearCmd = &cobra.Command{
	Use:   "clear <registration-id>",
	Short: "Delete the service account (refused while the mode is observe or require, or installs still wait for a verdict)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		if api.StagedMode() {
			return validationError("--staged cannot apply to `app integrity credential clear`: the route is not stageable").
				WithHint("Run it without --staged.")
		}
		force, _ := cmd.Flags().GetBool("force")
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if !force && !confirmPrompt("Delete the Play Integrity credential of registration %s?", args[0]) {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
		data, err := c.DeleteReturning("apps/" + args[0] + "/integrity-credential")
		if err != nil {
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 409 {
				// Two refusals share the status; the message says which. The
				// hint covers both so an agent never has to parse it.
				return withHint(err, "If the mode is observe or require, switch Play Integrity off first (`p202 app update "+args[0]+" --integrity-mode off`). "+
					"If installs are still waiting for a verdict, leave the credential until the worker has settled them (each within 24 hours of arriving; "+
					"`p202 app integrity status "+args[0]+"` shows installs.by_integrity_state.pending). Or rotate it with `p202 app integrity credential set "+args[0]+" --file <key.json>`.")
			}
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	appIntegrityCredentialSetCmd.Flags().StringP("file", "f", "", "The service-account key file (JSON); \"-\" or omitted reads piped stdin")
	appIntegrityCredentialClearCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	appIntegrityCredentialCmd.AddCommand(appIntegrityCredentialSetCmd, appIntegrityCredentialClearCmd)
	appIntegrityCmd.AddCommand(appIntegrityStatusCmd, appIntegrityCredentialCmd)
	appCmd.AddCommand(appIntegrityCmd)
}
