package cmd

import (
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

// encodingErrorSinks are the functions that make an encoding call and return no error, keyed "file: function", with
// what each does when the encoding fails. "Decoded" means every value it encodes was decoded from JSON, which encodes
// again. The list only shrinks.
var encodingErrorSinks = map[string]string{
	"cmd/app_report.go: reshapeAppReport":          "decoded; prints the server's answer as it came, not reshaped",
	"cmd/diff.go: comparableEqual":                 "decoded; warns on stderr and reports the records as different, so sync updates rather than skips",
	"cmd/diff.go: changedFields":                   "decoded; reports the field as changed",
	"cmd/export.go: rowDedupeKey":                  "decoded; keys the row by its %v form",
	"cmd/mcp_serve.go: mcpShapeResult":             "answers the tool call with an error result naming change_list",
	"cmd/mcp_serve.go: mcpErrorResult":             "encodes strings only; answers a fixed error envelope",
	"cmd/root.go: printError":                      "the error path itself: prints the error as text instead of the envelope",
	"cmd/shell.go: runBatch (func literal)":        "hands handleBuiltin's error to runBatch in builtinErr, which fails the batch",
	"cmd/shell.go: emitBatchResult":                "prints a failed result line in the command's place, so the JSONL stream keeps one line per command",
	"cmd/shell.go: normalizeValue":                 "encodes a string only; $_ reads null, and a capture into $name is refused",
	"cmd/sync.go: comparableHash":                  "decoded; answers no fingerprint, which re-syncs the row",
	"cmd/user_prefs.go: withStoredStates":          "decoded; prints the server's answer with its {\"stored\": …} objects as they came",
	"internal/eval/runner.go: runCase":             "records the judge's error as the case's failure (StatusError)",
	"internal/metrics/metrics.go: Emit":            "diagnostics on stderr: the event is not written",
	"internal/output/output.go: renderJSON":        "decoded; says so on stderr and prints the answer unformatted",
	"internal/output/output.go: renderCompactJSON": "decoded; says so on stderr and prints the answer unformatted",
	"internal/output/output.go: renderNDJSON":      "decoded; the row's line is left out, with nothing said",
	"internal/output/output.go: formatScalar":      "decoded; a table cell shows the value's %v form",
}

// TestEncodingErrorsReachTheCaller holds every encoding's error to the command that asked for it. rowsToJSON printed
// its json.Marshal error and returned nil, so `report losers`, `winners`, `breakeven` and eight other commands printed
// nothing on stdout and exited 0 (CLAUDE.md #1; the CLI never prints an error itself). errcheck reports an error
// dropped by a call standing alone, not one assigned to _, nor one a function prints and keeps.
//
// An encoding call is json.Marshal, json.MarshalIndent, a method Encode with one argument (a json.Encoder's, and
// any other encoder's), or a call of a function of this tree whose last result is an error and which makes one of
// those calls, to the fixed point (rowsToJSON, renderRows). Two shapes fail: its error assigned to _, and a function
// that makes one and returns no error, unless encodingErrorSinks lists it.
//
// Names are the heuristic: a method is read by its name within its own package, so another package's method (an
// api.Client request, which encodes its body, is TestRequestErrorsAreNotDiscarded's) is not seen; nor is a function
// value. A function that returns an error is trusted to return the encoding's: one that prints it and carries on is
// not seen.
func TestEncodingErrorsReachTheCaller(t *testing.T) {
	root := ".."
	fset := token.NewFileSet()
	type file struct {
		rel     string
		pkg     string
		f       *ast.File
		json    string          // the name encoding/json is imported under, "" if it is not
		imports map[string]bool // the names other packages are imported under
	}
	var files []*file
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
		fl := &file{rel: filepath.ToSlash(rel), pkg: f.Name.Name, f: f, imports: map[string]bool{}}
		for _, imp := range f.Imports {
			p, _ := strconv.Unquote(imp.Path.Value)
			name := p[strings.LastIndex(p, "/")+1:]
			if imp.Name != nil {
				name = imp.Name.Name
			}
			if p == "encoding/json" {
				if name == "." || name == "_" {
					t.Errorf("%s imports encoding/json as %q: this test reads its calls by the package name", fl.rel, name)
				}
				fl.json = name
				continue
			}
			fl.imports[name] = true
		}
		files = append(files, fl)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}

	errorLast := func(ft *ast.FuncType) bool {
		if ft.Results == nil || len(ft.Results.List) == 0 {
			return false
		}
		id, ok := ft.Results.List[len(ft.Results.List)-1].Type.(*ast.Ident)
		return ok && id.Name == "error"
	}
	// key names a declared function as "pkg.name", and a method as "pkg.(method).name".
	key := func(pkg string, fn *ast.FuncDecl) string {
		if fn.Recv != nil {
			return pkg + ".(method)." + fn.Name.Name
		}
		return pkg + "." + fn.Name.Name
	}
	type decl struct {
		f  *file
		fn *ast.FuncDecl
	}
	var decls []decl
	for _, f := range files {
		for _, d := range f.f.Decls {
			if fn, ok := d.(*ast.FuncDecl); ok && fn.Body != nil {
				decls = append(decls, decl{f, fn})
			}
		}
	}

	encoders := map[string]bool{}
	// encoding reports whether call is an encoding call, as written in f.
	encoding := func(f *file, call *ast.CallExpr) bool {
		switch fun := call.Fun.(type) {
		case *ast.Ident:
			return encoders[f.pkg+"."+fun.Name]
		case *ast.SelectorExpr:
			if fun.Sel.Name == "Encode" && len(call.Args) == 1 {
				return true
			}
			x, ok := fun.X.(*ast.Ident)
			switch {
			case ok && f.json != "" && x.Name == f.json:
				return fun.Sel.Name == "Marshal" || fun.Sel.Name == "MarshalIndent"
			case ok && f.imports[x.Name]:
				return encoders[x.Name+"."+fun.Sel.Name]
			default:
				return encoders[f.pkg+".(method)."+fun.Sel.Name]
			}
		}
		return false
	}
	// calls visits each call whose innermost enclosing function is n, with that function's type.
	var calls func(n ast.Node, ft *ast.FuncType, visit func(*ast.CallExpr, *ast.FuncType))
	calls = func(n ast.Node, ft *ast.FuncType, visit func(*ast.CallExpr, *ast.FuncType)) {
		ast.Inspect(n, func(m ast.Node) bool {
			switch x := m.(type) {
			case *ast.FuncLit:
				if x != n {
					calls(x.Body, x.Type, visit)
					return false
				}
			case *ast.CallExpr:
				visit(x, ft)
			}
			return true
		})
	}

	// A function whose last result is an error and which makes an encoding call is one itself, to the fixed point.
	for changed := true; changed; {
		changed = false
		for _, d := range decls {
			k := key(d.f.pkg, d.fn)
			if encoders[k] || !errorLast(d.fn.Type) {
				continue
			}
			makes := false
			calls(d.fn.Body, d.fn.Type, func(call *ast.CallExpr, ft *ast.FuncType) {
				makes = makes || (ft == d.fn.Type && encoding(d.f, call))
			})
			if makes {
				encoders[k] = true
				changed = true
			}
		}
	}
	for _, want := range []string{"cmd.rowsToJSON", "cmd.renderRows"} {
		if !encoders[want] {
			t.Fatalf("the walk no longer finds %s, which it was written for: %v", want, encoders)
		}
	}

	var bad []string
	seen := map[string]bool{}
	for _, f := range files {
		// Its error assigned to _.
		ast.Inspect(f.f, func(m ast.Node) bool {
			var lhs, rhs []ast.Expr
			switch x := m.(type) {
			case *ast.AssignStmt:
				lhs, rhs = x.Lhs, x.Rhs
			case *ast.ValueSpec:
				for _, name := range x.Names {
					lhs = append(lhs, name)
				}
				rhs = x.Values
			default:
				return true
			}
			if len(rhs) != 1 || len(lhs) == 0 {
				return true
			}
			call, ok := rhs[0].(*ast.CallExpr)
			if !ok || !encoding(f, call) {
				return true
			}
			if id, ok := lhs[len(lhs)-1].(*ast.Ident); ok && id.Name == "_" {
				bad = append(bad, fset.Position(call.Pos()).String()+": an encoding's error is assigned to _, so its "+
					"failure reads as output. Return it (wrapped with %w)")
			}
			return true
		})
		// Made in a function that returns no error.
		for _, d := range f.f.Decls {
			fn, ok := d.(*ast.FuncDecl)
			if !ok || fn.Body == nil {
				continue
			}
			calls(fn.Body, fn.Type, func(call *ast.CallExpr, ft *ast.FuncType) {
				if errorLast(ft) || !encoding(f, call) {
					return
				}
				where := fn.Name.Name
				if ft != fn.Type {
					where += " (func literal)"
				}
				k := f.rel + ": " + where
				if _, listed := encodingErrorSinks[k]; listed {
					seen[k] = true
					return
				}
				bad = append(bad, fset.Position(call.Pos()).String()+": "+k+" makes an encoding call and returns no "+
					"error, so a failure is printed or dropped and the command goes on. Return it, or list the function in "+
					"encodingErrorSinks with what it does with the failure")
			})
		}
	}
	sort.Strings(bad)
	for _, b := range bad {
		t.Error(b)
	}
	for k := range encodingErrorSinks {
		if !seen[k] {
			t.Errorf("encodingErrorSinks lists %q, which no longer makes an encoding call without returning an error: remove it", k)
		}
	}
}
