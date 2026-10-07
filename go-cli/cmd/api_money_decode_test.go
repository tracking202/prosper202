package cmd

import (
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"testing"
)

// apiMoneyFields are the JSON names the API uses for money.
var apiMoneyFields = map[string]bool{
	"amount": true, "click_payout": true, "click_cpc": true, "click_cpa": true, "ledger_value": true,
	"aff_campaign_payout": true, "aff_campaign_foreign_payout": true, "payout": true, "recorded_payout": true,
	"revenue": true, "revenue_override": true, "cost": true, "income": true, "net": true, "cpc": true,
	"cpa": true, "epc": true, "price": true, "total_revenue": true, "total_cost": true, "total_income": true,
	"total_net": true,
}

// notAPIMoney are struct fields with a money name that no API answer is decoded into, keyed
// "file:Field", with why.
var notAPIMoney = map[string]string{
	"cmd/conversion_import.go:Payout":         "the import's own output: the payout it read from the file, written canonical",
	"cmd/conversion_import.go:RecordedPayout": "the import's own output: the server's click_payout after parseImportAmount",
}

// TestStructsDecodeAPIMoneyAsJSONNumber holds every struct field that decodes API money to
// json.Number. The API answers money as a JSON number, older servers (and the answers that echo
// an exact decimal, such as POST /clicks/cpc's cpc) as a numeric string. A field typed string
// fails on the number — `p202 conversion import` failed every row of a converted click when the
// ledger's amount became one, while its tests' fake still sent strings — and one typed float64
// fails on the string. json.Number reads both, and null.
func TestStructsDecodeAPIMoneyAsJSONNumber(t *testing.T) {
	root := ".."
	fset := token.NewFileSet()
	var bad []string
	seen := map[string]bool{}
	checked := 0
	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			if path != root && (strings.HasPrefix(info.Name(), ".") || info.Name() == "testdata" || info.Name() == "dist") {
				return filepath.SkipDir
			}
			return nil
		}
		if !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}
		f, err := parser.ParseFile(fset, path, nil, 0)
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(root, path)
		rel = filepath.ToSlash(rel)
		ast.Inspect(f, func(n ast.Node) bool {
			st, ok := n.(*ast.StructType)
			if !ok {
				return true
			}
			for _, field := range st.Fields.List {
				if field.Tag == nil || len(field.Names) == 0 {
					continue
				}
				tag := reflect.StructTag(strings.Trim(field.Tag.Value, "`")).Get("json")
				name := strings.Split(tag, ",")[0]
				if !apiMoneyFields[name] {
					continue
				}
				key := rel + ":" + field.Names[0].Name
				if _, ok := notAPIMoney[key]; ok {
					seen[key] = true
					continue
				}
				if !isScalar(field.Type) {
					continue // an object or a list under a money name ({index, header}): not an amount
				}
				checked++
				if !isJSONNumber(field.Type) {
					bad = append(bad, fmt.Sprintf("%s:%d: %s `json:%q` is %s", rel, fset.Position(field.Pos()).Line,
						field.Names[0].Name, name, typeString(field.Type)))
				}
			}
			return true
		})
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	sort.Strings(bad)
	for _, b := range bad {
		t.Errorf("%s: decode API money as json.Number (a number or an older server's numeric string); "+
			"if no API answer is decoded into it, list it in notAPIMoney with why", b)
	}
	for key := range notAPIMoney {
		if !seen[key] {
			t.Errorf("notAPIMoney lists %s, which no longer exists; remove it", key)
		}
	}
	if checked == 0 {
		t.Fatalf("no money field was checked under %s: the walk found nothing to read", root)
	}
}

// isScalar reports a named type, or a pointer to one: string, float64, json.Number — the shapes
// an amount is decoded into, as against an inline struct, a map or a slice.
func isScalar(e ast.Expr) bool {
	if star, ok := e.(*ast.StarExpr); ok {
		e = star.X
	}
	switch e.(type) {
	case *ast.Ident, *ast.SelectorExpr:
		return true
	}
	return false
}

func isJSONNumber(e ast.Expr) bool {
	if star, ok := e.(*ast.StarExpr); ok {
		e = star.X
	}
	sel, ok := e.(*ast.SelectorExpr)
	if !ok {
		return false
	}
	pkg, ok := sel.X.(*ast.Ident)
	return ok && pkg.Name == "json" && sel.Sel.Name == "Number"
}

func typeString(e ast.Expr) string {
	switch v := e.(type) {
	case *ast.Ident:
		return v.Name
	case *ast.StarExpr:
		return "*" + typeString(v.X)
	case *ast.SelectorExpr:
		return typeString(v.X) + "." + v.Sel.Name
	case *ast.ArrayType:
		return "[]" + typeString(v.Elt)
	case *ast.MapType:
		return "map[" + typeString(v.Key) + "]" + typeString(v.Value)
	case *ast.InterfaceType:
		return "interface{}"
	}
	return fmt.Sprintf("%T", e)
}
