package cmd

import (
	"encoding/json"
	"fmt"
	"strings"

	"p202/internal/output"
)

// render outputs data applying all global filters: fields, max-field-length,
// secret redaction, and id-only extraction.
func render(data []byte) {
	// --id-only: extract and print just the primary key
	if idOnly {
		id, err := extractID(data)
		if err != nil {
			fmt.Println(err)
			return
		}
		fmt.Println(id)
		return
	}

	data = applyOutputFilters(data)

	if csvOutput {
		output.RenderCSV(data)
		return
	}
	output.Render(data, jsonOutput)
}

// renderForEntity outputs data with entity-aware user_content_fields metadata.
func renderForEntity(data []byte, entityName string) {
	if idOnly {
		id, err := extractID(data)
		if err != nil {
			fmt.Println(err)
			return
		}
		fmt.Println(id)
		return
	}

	// Inject user_content_fields metadata when in --json mode
	if (jsonOutput || agentMode) && entityName != "" {
		data = injectUserContentMeta(data, entityName)
	}

	data = applyOutputFilters(data)

	if csvOutput {
		output.RenderCSV(data)
		return
	}
	output.Render(data, jsonOutput)
}

// applyOutputFilters applies --fields, --max-field-length, and secret redaction.
func applyOutputFilters(data []byte) []byte {
	// --fields: filter to specified fields
	if fieldsFilter != "" {
		fields := strings.Split(fieldsFilter, ",")
		data = filterFields(data, fields)
	}

	// --max-field-length: truncate long values
	if maxFieldLength > 0 {
		data = truncateFields(data, maxFieldLength)
	}

	// Secret redaction in non-interactive / agent mode
	if shouldRedactSecrets() {
		data = redactSecretsInJSON(data)
	}

	return data
}

// redactSecretsInJSON applies secret redaction to JSON data.
func redactSecretsInJSON(data []byte) []byte {
	var parsed interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return data
	}

	redactRecursive(parsed)

	result, err := json.Marshal(parsed)
	if err != nil {
		return data
	}
	return result
}

func redactRecursive(v interface{}) {
	switch val := v.(type) {
	case map[string]interface{}:
		redactSecretFields(val)
		for _, field := range val {
			redactRecursive(field)
		}
	case []interface{}:
		for _, item := range val {
			redactRecursive(item)
		}
	}
}
