package cmd

import (
	"go/ast"
	"go/parser"
	"go/token"
	"go/types"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// requestErrorDrops are the requests whose error is still assigned to _, keyed "file: callee", with what the drop
// does. The list only shrinks.
var requestErrorDrops = map[string]string{
	"cmd/sync.go: orchestrator.Post": "sync's server-side job: kicks the server's sync worker between status reads; if " +
		"every kick fails, the 20 reads end and the job is printed as queued, with no warning",
}

// TestRequestErrorsAreNotDiscarded holds every assignment of a request's error to _ (`offers, _ :=
// fetchBreakdownRows(c, …)`, `_, _ = c.Post(…)`) to requestErrorDrops. `campaign optimize` dropped the error of its
// ranking, so a 500, a scope refusal or an unreachable server read as a campaign with no salvage offer, exit 0
// (CLAUDE.md #1). errcheck does not report a blank assignment.
//
// A request is a call of an api.Client method on a client variable, a call that is handed a client variable, or a
// call of a function that makes one of those. A client variable is a parameter, receiver or variable of type
// *api.Client, or one assigned from a function that returns one (api.NewFromConfig). Names are the heuristic: a
// client reached through a struct field or a map, or renamed by an assignment, is not seen.
func TestRequestErrorsAreNotDiscarded(t *testing.T) {
	root := ".."
	fset := token.NewFileSet()
	type file struct {
		rel string
		pkg string
		f   *ast.File
	}
	var files []file
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
		files = append(files, file{filepath.ToSlash(rel), f.Name.Name, f})
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}

	isClient := func(pkg string, e ast.Expr) bool {
		star, ok := e.(*ast.StarExpr)
		if !ok {
			return false
		}
		switch x := star.X.(type) {
		case *ast.Ident:
			return pkg == "api" && x.Name == "Client"
		case *ast.SelectorExpr:
			id, ok := x.X.(*ast.Ident)
			return ok && id.Name == "api" && x.Sel.Name == "Client"
		}
		return false
	}
	resultCount := func(ft *ast.FuncType) (int, bool) {
		if ft.Results == nil || len(ft.Results.List) == 0 {
			return 0, false
		}
		n := 0
		for _, r := range ft.Results.List {
			if len(r.Names) == 0 {
				n++
			} else {
				n += len(r.Names)
			}
		}
		last, ok := ft.Results.List[len(ft.Results.List)-1].Type.(*ast.Ident)
		return n, ok && last.Name == "error"
	}

	// Declarations: a function by "pkg.name", the client's methods by name, and the functions that return a client.
	type decl struct {
		pkg     string
		fn      *ast.FuncDecl
		results int
	}
	funcs := map[string]decl{}
	clientMethods := map[string]int{}
	// methods are the other types' methods by "pkg.name": a call x.name(c) is read by name; -1 marks a name whose
	// methods differ in their results.
	methods := map[string]int{}
	makesClient := map[string]bool{}
	for _, f := range files {
		for _, d := range f.f.Decls {
			fn, ok := d.(*ast.FuncDecl)
			if !ok {
				continue
			}
			n, errLast := resultCount(fn.Type)
			if fn.Recv != nil {
				if len(fn.Recv.List) == 1 && isClient(f.pkg, fn.Recv.List[0].Type) {
					if errLast {
						clientMethods[fn.Name.Name] = n
					}
					continue
				}
				key := f.pkg + "." + fn.Name.Name
				if prev, ok := methods[key]; (ok && prev != n) || !errLast {
					methods[key] = -1
				} else {
					methods[key] = n
				}
				continue
			}
			if fn.Type.Results != nil && len(fn.Type.Results.List) > 0 && isClient(f.pkg, fn.Type.Results.List[0].Type) {
				makesClient[f.pkg+"."+fn.Name.Name] = true
			}
			if errLast {
				funcs[f.pkg+"."+fn.Name.Name] = decl{f.pkg, fn, n}
			}
		}
	}

	// callee names a call's function as "pkg.name" (a function this tree declares) and as written.
	callee := func(pkg string, call *ast.CallExpr) (key, written string) {
		written = types.ExprString(call.Fun)
		switch fun := call.Fun.(type) {
		case *ast.Ident:
			return pkg + "." + fun.Name, written
		case *ast.SelectorExpr:
			if x, ok := fun.X.(*ast.Ident); ok {
				return x.Name + "." + fun.Sel.Name, written
			}
		}
		return "", written
	}

	requests := map[string]bool{}
	// walk visits n with the client variables in scope, calling found for each request call and its result count.
	var walk func(pkg string, n ast.Node, clients map[string]bool, found func(*ast.CallExpr, int))
	walk = func(pkg string, n ast.Node, clients map[string]bool, found func(*ast.CallExpr, int)) {
		scope := map[string]bool{}
		for k := range clients {
			scope[k] = true
		}
		addParams := func(fl *ast.FieldList) {
			if fl == nil {
				return
			}
			for _, p := range fl.List {
				if isClient(pkg, p.Type) {
					for _, name := range p.Names {
						scope[name.Name] = true
					}
				}
			}
		}
		switch fn := n.(type) {
		case *ast.FuncDecl:
			addParams(fn.Recv)
			addParams(fn.Type.Params)
		case *ast.FuncLit:
			addParams(fn.Type.Params)
		}
		ast.Inspect(n, func(m ast.Node) bool {
			switch x := m.(type) {
			case *ast.FuncLit:
				if x != n {
					walk(pkg, x, scope, found)
					return false
				}
			case *ast.AssignStmt:
				if len(x.Rhs) == 1 {
					if call, ok := x.Rhs[0].(*ast.CallExpr); ok {
						if key, _ := callee(pkg, call); makesClient[key] {
							if id, ok := x.Lhs[0].(*ast.Ident); ok {
								scope[id.Name] = true
							}
						}
					}
				}
			case *ast.ValueSpec:
				if x.Type != nil && isClient(pkg, x.Type) {
					for _, name := range x.Names {
						scope[name.Name] = true
					}
				}
			case *ast.CallExpr:
				key, _ := callee(pkg, x)
				count, known := 0, false
				if d, ok := funcs[key]; ok {
					count, known = d.results, true
				}
				request := requests[key]
				if sel, ok := x.Fun.(*ast.SelectorExpr); ok {
					if id, ok := sel.X.(*ast.Ident); ok && scope[id.Name] {
						if rc, ok := clientMethods[sel.Sel.Name]; ok {
							count, known, request = rc, true, true
						}
					} else if rc := methods[pkg+"."+sel.Sel.Name]; !known && rc > 0 {
						count, known = rc, true
					}
				}
				for _, a := range x.Args {
					if id, ok := a.(*ast.Ident); ok && scope[id.Name] {
						request = true
					}
				}
				if request && !known {
					count = -1
				}
				if request {
					found(x, count)
				}
			}
			return true
		})
	}

	// A function that makes a request is a request itself, to the fixed point.
	for changed := true; changed; {
		changed = false
		for key, d := range funcs {
			if requests[key] {
				continue
			}
			makes := false
			walk(d.pkg, d.fn, nil, func(*ast.CallExpr, int) { makes = true })
			if makes {
				requests[key] = true
				changed = true
			}
		}
	}
	if !requests["cmd.fetchBreakdownRows"] || !requests["cmd.fetchCampaignPayout"] {
		t.Fatalf("the walk no longer finds the requests it was written for: %v", requests)
	}

	// Every assignment of a request's error to _.
	seen := map[string]bool{}
	var bad []string
	for _, f := range files {
		f := f
		drops := map[*ast.CallExpr]int{}
		record := func(call *ast.CallExpr, n int) { drops[call] = n }
		for _, d := range f.f.Decls {
			walk(f.pkg, d, nil, record)
		}
		blankAt := func(lhs []ast.Expr, i int) bool {
			id, ok := lhs[i].(*ast.Ident)
			return ok && id.Name == "_"
		}
		ast.Inspect(f.f, func(m ast.Node) bool {
			var lhs []ast.Expr
			var rhs []ast.Expr
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
			if len(rhs) != 1 {
				return true
			}
			call, ok := rhs[0].(*ast.CallExpr)
			if !ok {
				return true
			}
			n, ok := drops[call]
			// A request whose results this test cannot read (a func value, a method name two types share) is
			// held to the rule when its last result goes to _, since that is where an error goes.
			if !ok || (n >= 0 && len(lhs) != n) || !blankAt(lhs, len(lhs)-1) {
				return true
			}
			_, written := callee(f.pkg, call)
			key := f.rel + ": " + written
			if _, listed := requestErrorDrops[key]; listed {
				seen[key] = true
				return true
			}
			bad = append(bad, fset.Position(call.Pos()).String()+": "+written)
			return true
		})
	}
	sort.Strings(bad)
	for _, b := range bad {
		t.Errorf("%s: a request's error is assigned to _, so its failure reads as an answer. Return it (wrapped "+
			"with %%w), or list it in requestErrorDrops with what the drop does", b)
	}
	for key := range requestErrorDrops {
		if !seen[key] {
			t.Errorf("requestErrorDrops lists %q, which no longer drops a request's error: remove it", key)
		}
	}
}
