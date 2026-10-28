package cmd

import (
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

// breakdownPagers read reports/breakdown to the last row (or a stated bound),
// so a call through one has no page to speak of.
var breakdownPagers = map[string]bool{"fetchAllRowsPaged": true, "fetchAllPages": true}

// TestEveryBreakdownReadSaysWhetherRowsRemain holds every read of
// reports/breakdown in the CLI to one of three shapes: a pager
// (breakdownPagers), fetchBreakdownRows itself, or a one-page answer whose
// function also calls breakdownMoreRowsNote. A page holds 50 rows unless
// asked for more, and losers, winners, breakeven and crosstab read one and
// ranked or classified it as the whole breakdown, saying nothing.
//
// It finds the literal "reports/breakdown" as a call's argument; an endpoint
// held in a variable or built by concatenation is not seen, and a call it
// cannot place is reported rather than passed.
func TestEveryBreakdownReadSaysWhetherRowsRemain(t *testing.T) {
	root := ".."
	fset := token.NewFileSet()
	reads := 0
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
		var stack []ast.Node
		ast.Inspect(f, func(n ast.Node) bool {
			if n == nil {
				stack = stack[:len(stack)-1]
				return true
			}
			stack = append(stack, n)
			call, ok := n.(*ast.CallExpr)
			if !ok || !namesBreakdown(call) {
				return true
			}
			reads++
			where := fset.Position(call.Pos()).String()
			callee := calleeName(call)
			if breakdownPagers[callee] {
				return true
			}
			fn, name := enclosingFunc(stack)
			switch {
			case callee != "Get":
				t.Errorf("%s: %s(\"reports/breakdown\", …) is no read this test knows; teach it the shape", where, callee)
			case name == "fetchBreakdownRows":
			case fn == nil || !callsFunc(fn, "breakdownMoreRowsNote"):
				t.Errorf("%s: reads one page of reports/breakdown and never says whether rows remain: "+
					"page with fetchBreakdownRows, or note it with breakdownMoreRowsNote and carry `truncated`", where)
			}
			return true
		})
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if reads < 6 {
		t.Fatalf("found %d reads of reports/breakdown; the scan is not reading the tree", reads)
	}
}

func namesBreakdown(call *ast.CallExpr) bool {
	for _, arg := range call.Args {
		if lit, ok := arg.(*ast.BasicLit); ok && lit.Kind == token.STRING {
			if s, err := strconv.Unquote(lit.Value); err == nil && s == "reports/breakdown" {
				return true
			}
		}
	}
	return false
}

func calleeName(call *ast.CallExpr) string {
	switch fun := call.Fun.(type) {
	case *ast.Ident:
		return fun.Name
	case *ast.SelectorExpr:
		return fun.Sel.Name
	}
	return ""
}

// enclosingFunc is the innermost function literal or declaration on the
// stack, and the declaration's name ("" for a literal).
func enclosingFunc(stack []ast.Node) (ast.Node, string) {
	for i := len(stack) - 1; i >= 0; i-- {
		switch fn := stack[i].(type) {
		case *ast.FuncLit:
			return fn, ""
		case *ast.FuncDecl:
			return fn, fn.Name.Name
		}
	}
	return nil, ""
}

func callsFunc(fn ast.Node, name string) bool {
	found := false
	ast.Inspect(fn, func(n ast.Node) bool {
		if call, ok := n.(*ast.CallExpr); ok && calleeName(call) == name {
			found = true
		}
		return !found
	})
	return found
}
