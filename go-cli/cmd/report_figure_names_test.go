package cmd

import (
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
)

// figureRewrites are the writes of a computed value under a report field's name that are known, keyed
// "file:declaration:field", with why. The list is to shrink, not to grow.
var figureRewrites = map[string]string{}

// TestReportFiguresKeepTheServersValues holds every value a command writes under a report field's name (metricColumns,
// as a string key in a map literal or an index assignment) to one read from a row as the server sent it. A command
// that wrote its own figure there answered one name two ways: losers and winners rounded total_cost to 2 decimals and
// avg_cpc to 4 (a $0.00375 cost read 0), crosstab each cell to 2, campaign optimize each epc to 3, and breakeven wrote
// leads ÷ clicks over conv_rate (leads ÷ click-throughs). A figure the command computes takes a name of its own
// (payout_net, click_conv_rate). Not seen: a key held in a variable or a constant (crosstab's cells, forecast's
// predictions), and what an index expression reads, which is taken to be the row's own figure.
func TestReportFiguresKeepTheServersValues(t *testing.T) {
	fields := map[string]bool{}
	for _, f := range metricColumns {
		fields[f] = true
	}
	root := "."
	fset := token.NewFileSet()
	var bad []string
	seen := map[string]bool{}
	checked := 0
	entries, err := os.ReadDir(root)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}
		f, err := parser.ParseFile(fset, filepath.Join(root, name), nil, 0)
		if err != nil {
			t.Fatal(err)
		}
		rel := "cmd/" + name
		decl := ""
		report := func(key ast.Expr, value ast.Expr) {
			lit, ok := key.(*ast.BasicLit)
			if !ok || lit.Kind != token.STRING {
				return
			}
			field, err := strconv.Unquote(lit.Value)
			if err != nil || !fields[field] {
				return
			}
			checked++
			if readFromARow(value) {
				return
			}
			k := rel + ":" + decl + ":" + field
			if _, ok := figureRewrites[k]; ok {
				seen[k] = true
				return
			}
			bad = append(bad, fmt.Sprintf("%s:%d (%s): %q is written as %s", rel, fset.Position(lit.Pos()).Line, decl, field, exprText(value)))
		}
		for _, d := range f.Decls {
			// The declaration a write sits in: a function, or the variable a command's literal and its RunE are.
			switch x := d.(type) {
			case *ast.FuncDecl:
				decl = x.Name.Name
			case *ast.GenDecl:
				decl = ""
				if x.Tok == token.VAR && len(x.Specs) > 0 {
					if vs, ok := x.Specs[0].(*ast.ValueSpec); ok && len(vs.Names) > 0 {
						decl = vs.Names[0].Name
					}
				}
			}
			ast.Inspect(d, func(n ast.Node) bool {
				switch x := n.(type) {
				case *ast.KeyValueExpr:
					report(x.Key, x.Value)
				case *ast.AssignStmt:
					if len(x.Lhs) != len(x.Rhs) {
						return true
					}
					for i, lhs := range x.Lhs {
						if ix, ok := lhs.(*ast.IndexExpr); ok {
							report(ix.Index, x.Rhs[i])
						}
					}
				}
				return true
			})
		}
	}
	sort.Strings(bad)
	for _, b := range bad {
		t.Errorf("%s: a figure under a report field's name is the server's, as `report breakdown` shows it. Pass the "+
			"row's value through, or give the command's own figure its own name (payout_net, click_conv_rate)", b)
	}
	for k, why := range figureRewrites {
		if !seen[k] {
			t.Errorf("figureRewrites lists %s (%s), which no longer writes a computed value there; remove it", k, why)
		}
	}
	if checked == 0 {
		t.Fatal("no write under a report field's name was found: the walk read nothing")
	}
}

// readFromARow reports a value read from a row (row["epc"], cr[metric]), or one that is not a figure at all: a name
// (an alias map's "total_income") or a flag (a set's true).
func readFromARow(e ast.Expr) bool {
	switch v := e.(type) {
	case *ast.IndexExpr:
		return true
	case *ast.BasicLit:
		return v.Kind == token.STRING
	case *ast.Ident:
		return v.Name == "true" || v.Name == "false"
	case *ast.ParenExpr:
		return readFromARow(v.X)
	}
	return false
}

func exprText(e ast.Expr) string {
	switch v := e.(type) {
	case *ast.CallExpr:
		return exprText(v.Fun) + "(…)"
	case *ast.Ident:
		return v.Name
	case *ast.SelectorExpr:
		return exprText(v.X) + "." + v.Sel.Name
	case *ast.BinaryExpr:
		return exprText(v.X) + " " + v.Op.String() + " " + exprText(v.Y)
	}
	return fmt.Sprintf("%T", e)
}
