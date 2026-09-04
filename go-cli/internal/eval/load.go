package eval

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// LoadCases reads eval cases from a JSON file or from every *.json file in a
// directory (sorted by name). A file holds either a top-level array of cases
// or {"cases": [...]}. Malformed input is an explicit error naming the file —
// never silently skipped.
func LoadCases(path string) ([]Case, error) {
	info, err := os.Stat(path)
	if err != nil {
		return nil, fmt.Errorf("reading cases from %s: %w", path, err)
	}

	var files []string
	if info.IsDir() {
		entries, err := os.ReadDir(path)
		if err != nil {
			return nil, fmt.Errorf("reading cases directory %s: %w", path, err)
		}
		for _, e := range entries {
			if !e.IsDir() && strings.HasSuffix(e.Name(), ".json") {
				files = append(files, filepath.Join(path, e.Name()))
			}
		}
		sort.Strings(files)
		if len(files) == 0 {
			return nil, fmt.Errorf("no *.json case files in %s", path)
		}
	} else {
		files = []string{path}
	}

	var cases []Case
	seen := map[string]string{}
	for _, file := range files {
		fileCases, err := loadCaseFile(file)
		if err != nil {
			return nil, err
		}
		for _, c := range fileCases {
			if prev, dup := seen[c.ID]; dup {
				return nil, fmt.Errorf("duplicate case id %q in %s (already defined in %s)", c.ID, file, prev)
			}
			seen[c.ID] = file
			cases = append(cases, c)
		}
	}
	return cases, nil
}

func loadCaseFile(file string) ([]Case, error) {
	raw, err := os.ReadFile(file)
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", file, err)
	}

	trimmed := strings.TrimSpace(string(raw))
	var cases []Case
	if strings.HasPrefix(trimmed, "[") {
		if err := json.Unmarshal(raw, &cases); err != nil {
			return nil, fmt.Errorf("parsing %s: %w", file, err)
		}
	} else {
		var wrapper struct {
			Cases []Case `json:"cases"`
		}
		if err := json.Unmarshal(raw, &wrapper); err != nil {
			return nil, fmt.Errorf("parsing %s: %w", file, err)
		}
		if wrapper.Cases == nil {
			return nil, fmt.Errorf("parsing %s: expected a top-level array or a {\"cases\": [...]} object", file)
		}
		cases = wrapper.Cases
	}

	for i, c := range cases {
		if err := validateCase(c); err != nil {
			return nil, fmt.Errorf("%s, case %d (%s): %w", file, i, orUnnamed(c.ID), err)
		}
	}
	return cases, nil
}

func validateCase(c Case) error {
	if strings.TrimSpace(c.ID) == "" {
		return fmt.Errorf("id is required")
	}
	if strings.TrimSpace(c.Ask) == "" && c.Skip == "" {
		return fmt.Errorf("ask is required (or mark the case skip with a reason)")
	}
	switch c.Priority {
	case "", "critical", "high", "medium", "low":
	default:
		return fmt.Errorf("priority %q is not one of critical, high, medium, low", c.Priority)
	}
	e := c.Expected
	hasExpectation := len(e.RunsOneOf) > 0 || len(e.NeverRuns) > 0 || len(e.StateUnchanged) > 0 ||
		len(e.Checks) > 0 || len(e.ReplyIncludes) > 0 || len(e.ReplyOmits) > 0 || strings.TrimSpace(e.Rubric) != ""
	if !hasExpectation && c.Skip == "" {
		return fmt.Errorf("expected asserts nothing; give the case at least one expectation or a rubric")
	}
	for j, chk := range e.Checks {
		if strings.TrimSpace(chk.Run) == "" {
			return fmt.Errorf("checks[%d].run is required", j)
		}
	}
	return nil
}

func orUnnamed(id string) string {
	if strings.TrimSpace(id) == "" {
		return "unnamed"
	}
	return id
}
