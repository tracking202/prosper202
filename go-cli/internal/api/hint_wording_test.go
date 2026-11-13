package api

import (
	"go/ast"
	"go/parser"
	"go/token"
	"regexp"
	"strconv"
	"testing"
)

// The CLI and AI assistants are free from 1.9.80, so no hint offers a paid
// tier. Every string literal HintFor and the hints it returns are built from
// is read, branch by branch, so a hint on a branch no request reaches is seen
// too. By word, not substring: "Prosper202" is not "Pro" (CLAUDE.md #21).
func TestHintForNeverNamesAPaidTier(t *testing.T) {
	paidTier := regexp.MustCompile(`(?i)\b(pro|trials?)\b`)
	if !paidTier.MatchString("Start a Pro trial") || paidTier.MatchString("Prosper202") {
		t.Fatal("the probe must see the word and only the word")
	}
	fset := token.NewFileSet()
	file, err := parser.ParseFile(fset, "client.go", nil, 0)
	if err != nil {
		t.Fatal(err)
	}
	if paidTier.MatchString(LicenceSupportURL) {
		t.Errorf("LicenceSupportURL %q names a paid tier", LicenceSupportURL)
	}
	read := 0
	for _, decl := range file.Decls {
		fn, ok := decl.(*ast.FuncDecl)
		if !ok || fn.Recv != nil || fn.Name.Name != "HintFor" {
			continue
		}
		ast.Inspect(fn.Body, func(n ast.Node) bool {
			lit, ok := n.(*ast.BasicLit)
			if !ok || lit.Kind != token.STRING {
				return true
			}
			s, err := strconv.Unquote(lit.Value)
			if err != nil {
				t.Fatalf("%s: %v", fset.Position(lit.Pos()), err)
			}
			read++
			if paidTier.MatchString(s) {
				t.Errorf("%s: HintFor says %q", fset.Position(lit.Pos()), s)
			}
			return true
		})
	}
	if read < 20 {
		t.Fatalf("read %d string literals in HintFor; the scan did not find its body", read)
	}
}
