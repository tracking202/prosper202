package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/term"

	configpkg "p202/internal/config"
)

// agentMode is the global --agent-mode flag.
var agentMode bool

// fieldsFilter is the global --fields flag (comma-separated field names).
var fieldsFilter string

// maxFieldLength is the global --max-field-length flag.
var maxFieldLength int

// idOnly is the global --id-only flag.
var idOnly bool

// dryRun is the global --dry-run flag.
var dryRun bool

// --- Non-interactive detection (Feature 9) ---

// isInteractive returns true when stdin is a terminal.
func isInteractive() bool {
	return term.IsTerminal(int(os.Stdin.Fd()))
}

// confirmOrFail prompts for confirmation in interactive mode.
// In non-interactive mode (or agent-mode), it returns an error unless --force is set.
func confirmOrFail(prompt string, force bool) error {
	if force {
		return nil
	}
	if agentMode || !isInteractive() {
		return validationError("refusing to proceed without --force in non-interactive/agent mode. Add --force to confirm")
	}
	fmt.Print(prompt)
	var answer string
	fmt.Scanln(&answer)
	answer = strings.ToLower(strings.TrimSpace(answer))
	if answer != "y" && answer != "yes" {
		fmt.Println("Cancelled.")
		return errCancelled
	}
	return nil
}

// errCancelled is a sentinel error for user cancellation.
var errCancelled = &CLIError{
	Category: "cancelled",
	Message:  "operation cancelled by user",
	ExitCode: ExitOK,
}

// --- Agent-mode restrictions (Feature 2) ---

// agentModeBlocklist contains commands that are blocked in agent-mode.
var agentModeBlocklist = map[string]bool{
	"config set-url": true,
	"config set-key": true,
}

// checkAgentModeRestrictions verifies the command is allowed in agent-mode.
func checkAgentModeRestrictions(cmdPath string, isMutation bool) error {
	if !agentMode {
		return nil
	}
	if agentModeBlocklist[cmdPath] {
		return &CLIError{
			Category: "agent_blocked",
			Message:  fmt.Sprintf("command %q is blocked in --agent-mode", cmdPath),
			ExitCode: ExitAgentBlocked,
		}
	}
	return nil
}

// --- Secret redaction (Feature 3) ---

// shouldRedactSecrets returns true when secrets should be masked.
func shouldRedactSecrets() bool {
	if agentMode {
		return true
	}
	if !isInteractive() {
		return true
	}
	return false
}

// redactSecretFields masks values for known secret field names.
func redactSecretFields(obj map[string]interface{}) {
	secretFields := map[string]bool{
		"api_key":      true,
		"apikey":       true,
		"secret":       true,
		"password":     true,
		"token":        true,
		"access_token": true,
	}
	for k, v := range obj {
		if secretFields[strings.ToLower(k)] {
			if s, ok := v.(string); ok && len(s) > 8 {
				obj[k] = s[:4] + "..." + s[len(s)-4:]
			} else if s, ok := v.(string); ok && s != "" && s != "(not set)" {
				obj[k] = strings.Repeat("*", len(s))
			}
		}
	}
}

// --- Fields filter (Feature 6) ---

// filterFields keeps only the specified fields in each data row.
func filterFields(data []byte, fields []string) []byte {
	if len(fields) == 0 {
		return data
	}
	fieldSet := map[string]bool{}
	for _, f := range fields {
		fieldSet[strings.TrimSpace(f)] = true
	}

	var parsed interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return data
	}

	filterObj := func(obj map[string]interface{}) {
		for k := range obj {
			if !fieldSet[k] {
				delete(obj, k)
			}
		}
	}

	switch v := parsed.(type) {
	case map[string]interface{}:
		if items, ok := v["data"].([]interface{}); ok {
			for _, item := range items {
				if obj, ok := item.(map[string]interface{}); ok {
					filterObj(obj)
				}
			}
		} else if inner, ok := v["data"].(map[string]interface{}); ok {
			filterObj(inner)
		}
	case []interface{}:
		for _, item := range v {
			if obj, ok := item.(map[string]interface{}); ok {
				filterObj(obj)
			}
		}
	}

	result, err := json.Marshal(parsed)
	if err != nil {
		return data
	}
	return result
}

// --- Max field length / truncation (Feature 8) ---

// truncateFields truncates string values longer than maxLen.
func truncateFields(data []byte, maxLen int) []byte {
	if maxLen <= 0 {
		return data
	}

	var parsed interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return data
	}

	truncateObj(parsed, maxLen)

	result, err := json.Marshal(parsed)
	if err != nil {
		return data
	}
	return result
}

func truncateObj(v interface{}, maxLen int) {
	switch val := v.(type) {
	case map[string]interface{}:
		for k, field := range val {
			if s, ok := field.(string); ok && len(s) > maxLen {
				val[k] = s[:maxLen] + "...[truncated]"
			} else {
				truncateObj(field, maxLen)
			}
		}
	case []interface{}:
		for i, item := range val {
			if s, ok := item.(string); ok && len(s) > maxLen {
				val[i] = s[:maxLen] + "...[truncated]"
			} else {
				truncateObj(item, maxLen)
			}
		}
	}
}

// --- ID-only extraction (Feature 12) ---

// extractID extracts the primary key from a response.
func extractID(data []byte) (string, error) {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return "", fmt.Errorf("cannot extract ID: response is not a JSON object")
	}

	obj := parsed
	if inner, ok := parsed["data"].(map[string]interface{}); ok {
		obj = inner
	}

	// Try common ID field patterns
	idFields := []string{"id", "tracker_id", "aff_campaign_id", "aff_network_id",
		"ppc_network_id", "ppc_account_id", "landing_page_id", "text_ad_id",
		"user_id", "role_id", "rotator_id"}
	for _, field := range idFields {
		if val, ok := obj[field]; ok && val != nil {
			return fmt.Sprintf("%v", val), nil
		}
	}

	return "", fmt.Errorf("no ID field found in response")
}

// --- user_content_fields metadata (Feature 5) ---

// userContentFieldsByEntity maps entity names to fields that contain user-supplied content.
var userContentFieldsByEntity = map[string][]string{
	"campaign": {"aff_campaign_name", "aff_campaign_url", "aff_campaign_url_2",
		"aff_campaign_url_3", "aff_campaign_url_4", "aff_campaign_url_5",
		"aff_campaign_postback_url", "aff_campaign_postback_append"},
	"aff-network":  {"aff_network_name", "aff_network_postback_url", "aff_network_postback_append"},
	"ppc-network":  {"ppc_network_name"},
	"ppc-account":  {"ppc_account_name"},
	"tracker":      {"tracker_id_public"},
	"landing-page": {"landing_page_url", "landing_page_nickname", "leave_behind_page_url"},
	"text-ad":      {"text_ad_name", "text_ad_headline", "text_ad_description", "text_ad_display_url"},
	"rotator":      {"name", "description"},
	"user":         {"user_name", "user_email"},
}

// injectUserContentMeta adds _meta.user_content_fields to JSON output.
func injectUserContentMeta(data []byte, entityName string) []byte {
	fields, ok := userContentFieldsByEntity[entityName]
	if !ok || len(fields) == 0 {
		return data
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return data
	}

	if parsed["_meta"] == nil {
		parsed["_meta"] = map[string]interface{}{}
	}
	if meta, ok := parsed["_meta"].(map[string]interface{}); ok {
		meta["user_content_fields"] = fields
	}

	result, err := json.Marshal(parsed)
	if err != nil {
		return data
	}
	return result
}

// --- Dry-run support (Feature 7) ---

// dryRunResponse wraps a response to indicate it was a dry-run.
func dryRunResponse(operation string, payload interface{}) []byte {
	result := map[string]interface{}{
		"dry_run":   true,
		"operation": operation,
		"payload":   payload,
		"message":   fmt.Sprintf("Dry run: %s would execute with the given payload", operation),
	}
	data, _ := json.Marshal(result)
	return data
}

// --- Audit trail (Feature 11) ---

// auditLog writes an entry to the agent audit log.
func auditLog(command, args, result string) {
	if !agentMode {
		return
	}
	dir := configpkg.Dir()
	logPath := filepath.Join(dir, "agent_audit.log")

	os.MkdirAll(dir, 0700)

	f, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil {
		return
	}
	defer f.Close()

	entry := fmt.Sprintf("%s\tagent-mode\t%s\t%s\t%s\n",
		time.Now().UTC().Format(time.RFC3339),
		command,
		args,
		result,
	)
	f.WriteString(entry)
}

// --- Structured error hints (Feature 1) ---

// suggestFix generates remediation hints for common errors.
func suggestFix(err error) string {
	msg := err.Error()

	if strings.Contains(msg, "no URL configured") {
		return "p202 config set-url <url>"
	}
	if strings.Contains(msg, "no API key configured") {
		return "p202 config set-key <key>"
	}
	if strings.Contains(msg, "required flag") {
		parts := strings.Split(msg, "--")
		if len(parts) >= 2 {
			flagName := strings.Fields(parts[1])[0]
			return fmt.Sprintf("Add --%s <value> to your command", flagName)
		}
	}
	if strings.Contains(msg, "connection failed") || strings.Contains(msg, "network error") {
		return "Check that your Prosper202 instance is running: p202 system health"
	}
	if strings.Contains(msg, "401") || strings.Contains(msg, "Unauthorized") {
		return "Your API key may be invalid. Run: p202 config set-key <new-key>"
	}
	if strings.Contains(msg, "403") || strings.Contains(msg, "Forbidden") {
		return "Your API key lacks permissions for this operation"
	}
	if strings.Contains(msg, "404") || strings.Contains(msg, "not found") && !strings.Contains(msg, "profile") {
		return "The resource was not found. Verify the ID exists: p202 <entity> list --json"
	}
	if strings.Contains(msg, "profile") && strings.Contains(msg, "not found") {
		return "List available profiles: p202 config list-profiles"
	}
	if strings.Contains(msg, "refusing to proceed without --force") {
		return "Add --force to confirm the operation"
	}
	if strings.Contains(msg, "blocked in --agent-mode") {
		return "This command is not allowed in --agent-mode for safety"
	}
	return ""
}
