package cmd

import (
	"regexp"
	"strings"
)

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

// criterionCountryPattern is a country value that names a country by its
// code: two letters alone (rows stored before the writers wrote Name(CC)),
// or two letters in the brackets that end it (Criterion::countryCode()).
var criterionCountryPattern = regexp.MustCompile(`^([A-Za-z]{2})$|\(([A-Za-z]{2})\)$`)

// criterionCountryCode is the country a country value names, as its
// upper-case code, and false when it names none that way: "Germany", "DEU",
// "Germany( DE )" and "Germany(DE) x" name no code, and the redirects compare
// such a value with the visitor's whole "Name(CC)", which is always a value
// that names a code, so it never matches.
func criterionCountryCode(value string) (string, bool) {
	m := criterionCountryPattern.FindStringSubmatch(value)
	if m == nil {
		return "", false
	}
	if m[2] != "" {
		return strings.ToUpper(m[2]), true
	}
	return strings.ToUpper(m[1]), true
}

// criterionCountryListed is whether a country criterion's values list a
// visitor whose country code is code (CriterionMatcher::countryListed()).
func criterionCountryListed(values []string, code string) bool {
	for _, v := range values {
		if named, ok := criterionCountryCode(v); ok && named == strings.ToUpper(code) {
			return true
		}
	}
	return false
}

// criterionStatementKnown is whether the redirects read a statement at all:
// any other matches no visitor, unless a value is ALL.
func criterionStatementKnown(statement string) bool {
	return statement == "is" || statement == "is_not"
}
