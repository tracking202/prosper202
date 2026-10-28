package cmd

import (
	"encoding/json"
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
)

// The PHP tree's Criterion reads each of these spellings; the CLI reads them
// the same way, or `rotator test` predicts a destination the redirects do
// not send the visitor to.
func TestCriterionValuesReadAsThePHPTreeReadsThem(t *testing.T) {
	raw, err := os.ReadFile(repoPath("tests", "fixtures", "rotator-criterion-values.json"))
	if err != nil {
		t.Fatalf("reading the shared vectors: %v", err)
	}
	var vectors struct {
		Cases []struct {
			Stored     string   `json:"stored"`
			Values     []string `json:"values"`
			Everyone   bool     `json:"everyone"`
			Normalized string   `json:"normalized"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &vectors); err != nil || len(vectors.Cases) < 10 {
		t.Fatalf("the shared vectors: %v (%d cases)", err, len(vectors.Cases))
	}
	for _, c := range vectors.Cases {
		if got := criterionValues(c.Stored); !reflect.DeepEqual(got, c.Values) {
			t.Errorf("criterionValues(%q) = %q, want %q", c.Stored, got, c.Values)
		}
		if got := criterionMatchesEveryone(criterionValues(c.Stored)); got != c.Everyone {
			t.Errorf("criterionMatchesEveryone(%q) = %v, want %v", c.Stored, got, c.Everyone)
		}
		if got := criterionNormalize(c.Stored); got != c.Normalized {
			t.Errorf("criterionNormalize(%q) = %q, want %q", c.Stored, got, c.Normalized)
		}
	}
}

func TestRotatorDestinationReadsEverySpellingOfAll(t *testing.T) {
	for _, value := range []string{"ALL", "all", "All", " all ", "United States(US), all"} {
		for _, statement := range []string{"is", "is_not"} {
			data := map[string]interface{}{
				"default_campaign": 90007.0,
				"rules": []interface{}{
					map[string]interface{}{
						"rule_name": "everyone", "status": 1.0,
						"criteria":  []interface{}{map[string]interface{}{"type": "country", "statement": statement, "value": value}},
						"redirects": []interface{}{map[string]interface{}{"redirect_campaign": 90008.0}},
					},
				},
			}
			if _, dest, _ := rotatorDestination(data, "BR"); dest != "campaign 90008" {
				t.Errorf("%s %q: a visitor from BR goes to %q, want the rule's campaign 90008", statement, value, dest)
			}
		}
	}
}

func TestDiffComparesCriteriaAsTheRedirectsReadThem(t *testing.T) {
	rule := func(value string) interface{} {
		return []interface{}{map[string]interface{}{"type": "country", "statement": "is", "value": value}}
	}
	if a, b := normalizeCriteria(rule("all")), normalizeCriteria(rule("ALL")); !reflect.DeepEqual(a, b) {
		t.Errorf("all and ALL compare as different: %v, %v", a, b)
	}
	if a, b := normalizeCriteria(rule("United States(US), Canada(CA)")), normalizeCriteria(rule("United States(US),Canada(CA)")); !reflect.DeepEqual(a, b) {
		t.Errorf("a list with and without spaces compares as different: %v, %v", a, b)
	}
	if a, b := normalizeCriteria(rule("United States(US)")), normalizeCriteria(rule("Canada(CA)")); reflect.DeepEqual(a, b) {
		t.Error("two countries compare as the same")
	}
}

// No other file of the CLI spells the every-visitor value: a reader with its
// own spelling is how rtr.php and offrtr.php came to read "all" two ways.
// The lower-case "all" is not looked for: it names the --all flags and
// diff's every-entity target.
func TestOnlyTheCriterionReaderSpellsAll(t *testing.T) {
	files, err := filepath.Glob("*.go")
	if err != nil || len(files) == 0 {
		t.Fatalf("no Go files: %v", err)
	}
	for _, file := range files {
		if strings.HasSuffix(file, "_test.go") || file == "rotator_criterion.go" {
			continue
		}
		fset := token.NewFileSet()
		f, err := parser.ParseFile(fset, file, nil, 0)
		if err != nil {
			t.Fatalf("%s: %v", file, err)
		}
		ast.Inspect(f, func(n ast.Node) bool {
			lit, ok := n.(*ast.BasicLit)
			if !ok || lit.Kind != token.STRING {
				return true
			}
			if s, err := strconv.Unquote(lit.Value); err == nil && strings.EqualFold(s, criterionEveryone) && s != "all" {
				t.Errorf("%s: spells %s; read a criterion value with criterionValues and criterionMatchesEveryone", fset.Position(lit.Pos()), lit.Value)
			}
			return true
		})
	}
}
