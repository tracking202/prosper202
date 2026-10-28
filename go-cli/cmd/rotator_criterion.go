package cmd

import "strings"

// A redirector rule's criterion value, read as the redirects read it
// (Prosper202\Rotator\Criterion in the PHP tree). `rotator test` predicts
// where a visitor goes and `diff` compares two installs' rules, so both read
// a value the way rtr.php and offrtr.php do; tests/fixtures/
// rotator-criterion-values.json holds both trees to the same answers.

// criterionEveryone is the value that matches every visitor, as stored.
const criterionEveryone = "ALL"

// criterionValues is a stored value as the redirects compare it: split on
// commas, each value trimmed, the empty ones dropped.
func criterionValues(stored string) []string {
	values := []string{}
	for _, v := range strings.Split(stored, ",") {
		if v = strings.TrimSpace(v); v != "" {
			values = append(values, v)
		}
	}
	return values
}

// criterionMatchesEveryone is whether a criterion with these values matches
// every visitor: one of them is ALL, in any case, whatever the statement.
func criterionMatchesEveryone(values []string) bool {
	for _, v := range values {
		if strings.EqualFold(strings.TrimSpace(v), criterionEveryone) {
			return true
		}
	}
	return false
}

// criterionNormalize is a value as the writers store it: criterionValues
// joined with ",", every visitor as ALL.
func criterionNormalize(stored string) string {
	values := criterionValues(stored)
	for i, v := range values {
		if criterionMatchesEveryone([]string{v}) {
			values[i] = criterionEveryone
		}
	}
	return strings.Join(values, ",")
}
