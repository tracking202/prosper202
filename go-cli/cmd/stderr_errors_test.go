package cmd

import (
	"go/ast"
	"go/parser"
	"go/token"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

// printedFailures lists the files that write a failure to stderr themselves,
// with how many such writes each has and why they are not returned errors.
// The contract (CLAUDE.md, Go CLI errors, 5) is that a command returns its
// error and Execute prints it, so an agent gets one envelope with a category,
// a hint and an exit code. A count here only shrinks.
var printedFailures = map[string]struct {
	count int
	why   string
}{
	"campaign_urls.go": {1, "one line per campaign of a bulk update; the command still returns the failure"},
	"crud.go":          {6, "one line per id of a bulk delete, archive or URL fetch; the command still returns the failure"},
	"render.go":        {1, "a payload that could not be encoded; render() returns nothing to carry it"},
	"shell.go":         {5, "the interactive shell prints a command's error and reads the next command"},
}

// TestFailuresArePrintedOnlyByExecute reads fmt.Fprint, Fprintf and Fprintln
// to os.Stderr whose message begins "Error" or "Failed". It does not see a
// failure written another way (os.Stderr.WriteString, a writer held in a
// variable) or one whose message begins with anything else.
func TestFailuresArePrintedOnlyByExecute(t *testing.T) {
	files, err := filepath.Glob("*.go")
	if err != nil || len(files) == 0 {
		t.Fatalf("no source files found: %v", err)
	}
	found := map[string][]string{}
	stderrWrites := 0
	for _, file := range files {
		if strings.HasSuffix(file, "_test.go") {
			continue
		}
		fset := token.NewFileSet()
		f, err := parser.ParseFile(fset, file, nil, 0)
		if err != nil {
			t.Fatalf("%s: %v", file, err)
		}
		ast.Inspect(f, func(n ast.Node) bool {
			call, ok := n.(*ast.CallExpr)
			if !ok || len(call.Args) < 2 {
				return true
			}
			if !isSelector(call.Fun, "fmt", "Fprint", "Fprintf", "Fprintln") || !isSelector(call.Args[0], "os", "Stderr") {
				return true
			}
			stderrWrites++
			lit, ok := call.Args[1].(*ast.BasicLit)
			if !ok || lit.Kind != token.STRING {
				return true
			}
			msg, err := strconv.Unquote(lit.Value)
			if err != nil {
				t.Fatalf("%s: unreadable string %s", fset.Position(lit.Pos()), lit.Value)
			}
			if strings.HasPrefix(msg, "Error") || strings.HasPrefix(msg, "Failed") {
				found[file] = append(found[file], fset.Position(call.Pos()).String())
			}
			return true
		})
	}
	// A scan that saw no stderr write at all read nothing.
	if stderrWrites < 20 {
		t.Fatalf("read only %d writes to os.Stderr in cmd; the scan no longer sees them", stderrWrites)
	}
	for file, sites := range found {
		allowed, listed := printedFailures[file]
		if !listed {
			t.Errorf("%s prints a failure to stderr itself (%s). Return the error: Execute prints it with its category, hint and exit code.", file, strings.Join(sites, ", "))
			continue
		}
		if len(sites) > allowed.count {
			t.Errorf("%s prints %d failures to stderr itself, and %d are listed (%s). Return the new one. Sites: %s", file, len(sites), allowed.count, allowed.why, strings.Join(sites, ", "))
		}
	}
	for file, allowed := range printedFailures {
		if len(found[file]) < allowed.count {
			t.Errorf("%s now prints %d failures to stderr itself; lower its count in printedFailures from %d", file, len(found[file]), allowed.count)
		}
	}
}

func isSelector(e ast.Expr, pkg string, names ...string) bool {
	sel, ok := e.(*ast.SelectorExpr)
	if !ok {
		return false
	}
	x, ok := sel.X.(*ast.Ident)
	if !ok || x.Name != pkg {
		return false
	}
	for _, name := range names {
		if sel.Sel.Name == name {
			return true
		}
	}
	return false
}
