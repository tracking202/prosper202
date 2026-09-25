package cmd

import (
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// attributionExportStatuses is the export job lifecycle
// (Prosper202\Attribution\ExportStore::STATUSES).
var attributionExportStatuses = []string{"pending", "running", "completed", "failed"}

var attrExportCmd = &cobra.Command{
	Use:   "export",
	Short: "Attribution exports: a breakdown written to CSV by the export runner, downloadable and optionally sent to an https webhook",
	Long: `An export is a job: the export runner (the minutely cron) builds the breakdown it names,
writes every group to a CSV file, and, when the export has a webhook, POSTs the file there,
signed with HMAC-SHA256 (X-P202-Signature: sha256=<hex> over "<X-P202-Timestamp>.<body>").

Webhooks go to https URLs on public addresses only: every address the host resolves to is
checked, the connection is pinned to the checked address, and redirects are not followed.`,
}

func exportIDArg(arg string) error {
	if !positiveIntPattern.MatchString(arg) {
		return validationError("export id must be a positive whole number, got %q", arg).
			WithHint("Run `p202 attribution export list` to find export ids.")
	}
	return nil
}

var attrExportListCmd = &cobra.Command{
	Use:   "list",
	Short: "List exports, newest first",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("status"); v != "" {
			if !containsString(attributionExportStatuses, v) {
				return validationError("invalid --status %q; valid: %s", v, strings.Join(attributionExportStatuses, ", "))
			}
			params["status"] = v
		}
		if cmd.Flags().Changed("limit") {
			n, _ := cmd.Flags().GetInt("limit")
			if n < 1 || n > 200 {
				return validationError("invalid --limit %d; 1 to 200", n)
			}
			params["limit"] = strconv.Itoa(n)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/exports", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrExportGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "One export: its status, rows, webhook answer and last error",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := exportIDArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/exports/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// attributionExportBody reads the create flags into the request body, as
// the types the API takes (ids and times are JSON numbers). Every flag is
// checked here, before a client is built.
func attributionExportBody(cmd *cobra.Command) (map[string]interface{}, error) {
	body := map[string]interface{}{}
	groupBy, _ := cmd.Flags().GetString("group-by")
	if !containsString(attributionDimensions, groupBy) {
		return nil, validationError("invalid --group-by %q; valid: %s", groupBy, strings.Join(attributionDimensions, ", "))
	}
	body["group_by"] = groupBy
	for flag, field := range map[string]string{"model": "model_id", "compare-model": "compare_model_id"} {
		v, _ := cmd.Flags().GetString(flag)
		if v == "" {
			continue
		}
		if !positiveIntPattern.MatchString(v) {
			return nil, validationError("invalid --%s %q: a positive whole number", flag, v).
				WithHint("Run `p202 attribution model list` to find model ids.")
		}
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil {
			return nil, validationError("invalid --%s %q: too large", flag, v)
		}
		body[field] = n
	}
	if body["model_id"] != nil && body["model_id"] == body["compare_model_id"] {
		return nil, validationError("--compare-model must differ from --model")
	}
	params := map[string]string{}
	if err := attributionRangeParams(cmd, params); err != nil {
		return nil, err
	}
	if p, ok := params["period"]; ok {
		body["period"] = p
	}
	for _, field := range []string{"time_from", "time_to"} {
		if v, ok := params[field]; ok {
			n, _ := strconv.ParseInt(v, 10, 64)
			body[field] = n
		}
	}
	if v, _ := cmd.Flags().GetString("run-at"); v != "" {
		if !unixTimePattern.MatchString(v) {
			return nil, validationError("invalid --run-at %q: unix time in seconds", v).
				WithHint("For example --run-at $(date -d 'tomorrow 06:00' +%%s); leave it out to run at the next export run.")
		}
		n, _ := strconv.ParseInt(v, 10, 64)
		body["run_at"] = n
	}
	url, _ := cmd.Flags().GetString("webhook-url")
	secret, _ := cmd.Flags().GetString("webhook-secret")
	if url != "" {
		if !strings.HasPrefix(strings.ToLower(url), "https://") {
			return nil, validationError("invalid --webhook-url %q: exports are only sent over https", url).
				WithHint("Use an https:// URL on a public address; the server refuses private, loopback and link-local destinations.")
		}
		body["webhook_url"] = url
	}
	if secret != "" {
		if url == "" {
			return nil, validationError("--webhook-secret needs --webhook-url: the secret signs webhook deliveries")
		}
		body["webhook_secret"] = secret
	}
	return body, nil
}

var attrExportCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Queue an export of a breakdown: now, or at --run-at; optionally to a signed https webhook",
	Long: `Queue an export. Without --model it exports the account default model (stored as its id, so
the job reads a fixed model); without a range, the last 30 days. The response carries
webhook_secret once when the export has a webhook: keep it with the receiver.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		body, err := attributionExportBody(cmd)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("attribution/exports", body, idemKey)
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) && apiErr.FieldErrors["webhook_url"] != "" {
				return withHint(err, "Point --webhook-url at a public https address. Private, loopback, link-local and metadata addresses are refused, whatever the name resolves through; an operator whose receiver is on their own network can allow that network with P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php.")
			}
			if asAPIError(err, &apiErr) && apiErr.Status == 409 {
				return withHint(err, "That model is inactive or invalid, so it has no credits to export. Check it with `p202 attribution model get <id>`, or leave --model out to export the default.")
			}
			return err
		}
		render(data)
		return nil
	},
}

var attrExportDownloadCmd = &cobra.Command{
	Use:   "download <id>",
	Short: "Download an export's CSV to --output, or to stdout",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := exportIDArg(args[0]); err != nil {
			return err
		}
		outputPath, _ := cmd.Flags().GetString("output")
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Download("attribution/exports/" + args[0] + "/download")
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) && apiErr.Status == 409 {
				return withHint(err, "The file is written when the export runs. Check its status with `p202 attribution export get %s`; a failed export can be queued again with `p202 attribution export retry %s`.", args[0], args[0])
			}
			return err
		}
		if outputPath == "" {
			if _, err := os.Stdout.Write(data); err != nil {
				return err
			}
			return nil
		}
		if err := os.WriteFile(outputPath, data, 0600); err != nil {
			return withHint(err, "Check that the directory of --output exists and is writable.")
		}
		output.Success("Export %s written to %s (%d bytes)", args[0], outputPath, len(data))
		return nil
	},
}

var attrExportRetryCmd = &cobra.Command{
	Use:   "retry <id>",
	Short: "Queue a failed export again, with its attempts reset",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := exportIDArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("attribution/exports/"+args[0]+"/retry", map[string]interface{}{})
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) && apiErr.Status == 409 {
				return withHint(err, "Only a failed export can be retried. A pending one runs within a minute; a completed one can be downloaded with `p202 attribution export download %s`.", args[0])
			}
			return err
		}
		render(data)
		return nil
	},
}

var attrExportDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete an export and its file (not while it is running); supports --ids for bulk",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		if len(args) == 1 {
			if err := exportIDArg(args[0]); err != nil {
				return err
			}
		}
		err := bulkOrSingleDelete(cmd, "attribution/exports", "attribution export")
		var apiErr *api.APIError
		if asAPIError(err, &apiErr) && apiErr.Status == 409 {
			return withHint(err, "The export is running; it finishes within a minute. Delete it then.")
		}
		return err
	},
}

func init() {
	attrExportListCmd.Flags().String("status", "", "Filter: "+strings.Join(attributionExportStatuses, ", "))
	attrExportListCmd.Flags().Int("limit", 50, "Rows, 1-200")

	attrExportCreateCmd.Flags().String("group-by", "campaign", "Dimension: "+strings.Join(attributionDimensions, ", "))
	attrExportCreateCmd.Flags().String("model", "", "Model id (default: the account default model)")
	attrExportCreateCmd.Flags().String("compare-model", "", "A second model id, side by side")
	registerAttributionRangeFlags(attrExportCreateCmd)
	attrExportCreateCmd.Flags().String("run-at", "", "When to run it, unix seconds (default: the next export run, within a minute)")
	attrExportCreateCmd.Flags().String("webhook-url", "", "Also POST the CSV here: https, public address, no redirects")
	attrExportCreateCmd.Flags().String("webhook-secret", "", "HMAC secret for the webhook signature, 16-255 characters (default: generated and returned once)")
	registerIdempotencyKeyFlag(attrExportCreateCmd)

	attrExportDownloadCmd.Flags().StringP("output", "O", "", "Write the CSV to this file instead of stdout")

	registerDeleteFlags(attrExportDeleteCmd, "export")

	attrExportCmd.AddCommand(attrExportListCmd, attrExportGetCmd, attrExportCreateCmd, attrExportDownloadCmd, attrExportRetryCmd, attrExportDeleteCmd)
	attributionCmd.AddCommand(attrExportCmd)
}
