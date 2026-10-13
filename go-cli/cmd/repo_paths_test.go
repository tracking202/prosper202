package cmd

import (
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

// repoPath is a file of the repository outside this module, for a test that
// holds the CLI to one: the API's controllers, the UI's menus, the
// agent-eval cases. The Go workflow runs only when a path it lists changes,
// so a test reading a file it does not list stays green while that file
// breaks it, until an unrelated CLI change runs it and fails for a reason
// that change did not cause (TestCRUDFlagsMatchTheirControllers read the
// API's controllers for months that way). TestRepoFilesTriggerTheGoWorkflow
// holds every repoPath call to a path in go-cli.yml.
func repoPath(parts ...string) string {
	return filepath.Join(append([]string{"..", ".."}, parts...)...)
}

func TestRepoFilesTriggerTheGoWorkflow(t *testing.T) {
	triggers := goWorkflowPaths(t)
	files, err := filepath.Glob("*_test.go")
	if err != nil || len(files) == 0 {
		t.Fatalf("no test files found: %v", err)
	}
	calls := 0
	for _, file := range files {
		fset := token.NewFileSet()
		f, err := parser.ParseFile(fset, file, nil, 0)
		if err != nil {
			t.Fatalf("%s: %v", file, err)
		}
		ast.Inspect(f, func(n ast.Node) bool {
			call, ok := n.(*ast.CallExpr)
			if !ok {
				return true
			}
			pos := fset.Position(call.Pos())
			switch fun := call.Fun.(type) {
			case *ast.Ident:
				if fun.Name != "repoPath" {
					return true
				}
				calls++
				var parts []string
				whole := true
				for _, arg := range call.Args {
					lit, ok := arg.(*ast.BasicLit)
					if !ok || lit.Kind != token.STRING {
						whole = false // a name built at run time: the literal parts are its directory
						break
					}
					s, _ := strconv.Unquote(lit.Value)
					parts = append(parts, s)
				}
				if len(parts) == 0 {
					t.Errorf("%s: repoPath() with no literal directory cannot be checked against go-cli.yml", pos)
					return true
				}
				// A path whose last part is built at run time names a file in
				// the literal directory; a literal path may be a file or a
				// directory read whole (the eval cases), covered by a glob
				// over what is in it.
				path := strings.Join(parts, "/")
				covered := matchesAnyTrigger(path+"/any-file", triggers)
				if whole {
					covered = covered || matchesAnyTrigger(path, triggers)
				}
				if !covered {
					t.Errorf("%s: a test reads %s, which go-cli.yml's paths do not list; add it to both push and pull_request so a change to it runs the tests that read it", pos, path)
				}
			case *ast.SelectorExpr:
				// A path out of the module built by hand is read by a test
				// this check cannot see.
				if pkg, ok := fun.X.(*ast.Ident); ok && pkg.Name == "filepath" && fun.Sel.Name == "Join" && len(call.Args) >= 2 {
					if isDotDot(call.Args[0]) && isDotDot(call.Args[1]) && !strings.HasSuffix(file, "repo_paths_test.go") {
						t.Errorf("%s: a path out of the module built with filepath.Join(\"..\", \"..\", …); use repoPath() so go-cli.yml is held to it", pos)
					}
				}
			}
			return true
		})
	}
	if calls == 0 {
		t.Fatal("found no repoPath() calls: the scan is blind, not the tests in order")
	}
}

func isDotDot(e ast.Expr) bool {
	lit, ok := e.(*ast.BasicLit)
	return ok && lit.Kind == token.STRING && lit.Value == `".."`
}

// goWorkflowPaths reads the path filters of go-cli.yml: the push list and
// the pull_request list, which must agree.
func goWorkflowPaths(t *testing.T) []string {
	t.Helper()
	src, err := os.ReadFile(repoWorkflow)
	if err != nil {
		t.Fatalf("reading go-cli.yml: %v", err)
	}
	lists := map[string][]string{}
	event := ""
	inPaths := false
	for _, line := range strings.Split(string(src), "\n") {
		trimmed := strings.TrimSpace(line)
		switch {
		case line == "jobs:":
			inPaths = false
			event = "done"
		case event == "done":
		case strings.HasPrefix(line, "  ") && !strings.HasPrefix(line, "    ") && strings.HasSuffix(trimmed, ":"):
			event, inPaths = strings.TrimSuffix(trimmed, ":"), false
		case trimmed == "paths:":
			inPaths = true
		case inPaths && strings.HasPrefix(trimmed, "- "):
			p := strings.Trim(strings.TrimSpace(strings.TrimPrefix(trimmed, "- ")), `'"`)
			lists[event] = append(lists[event], p)
		case inPaths && trimmed != "" && !strings.HasPrefix(trimmed, "#"):
			inPaths = false
		}
	}
	push, pr := lists["push"], lists["pull_request"]
	if len(push) == 0 || strings.Join(push, "\n") != strings.Join(pr, "\n") {
		t.Fatalf("go-cli.yml: push paths %v and pull_request paths %v must be the same non-empty list", push, pr)
	}
	return push
}

// repoWorkflow is go-cli.yml, read through repoPath's root; it is the file
// the check is about, so it is not itself held to a trigger.
var repoWorkflow = filepath.Join("..", "..", ".github", "workflows", "go-cli.yml")

// matchesAnyTrigger applies GitHub's path filter globs: ** crosses
// directories, * does not.
func matchesAnyTrigger(path string, globs []string) bool {
	for _, g := range globs {
		var re strings.Builder
		re.WriteString("^")
		for i := 0; i < len(g); i++ {
			switch {
			case strings.HasPrefix(g[i:], "**"):
				re.WriteString(".*")
				i++
			case g[i] == '*':
				re.WriteString("[^/]*")
			default:
				re.WriteString(regexp.QuoteMeta(string(g[i])))
			}
		}
		re.WriteString("$")
		if regexp.MustCompile(re.String()).MatchString(path) {
			return true
		}
	}
	return false
}

func TestMatchesAnyTriggerReadsGitHubGlobs(t *testing.T) {
	for path, want := range map[string]bool{
		"api/v3/Controllers/Foo.php":  true,
		"api/v3/index.php":            true,
		"api/v3/other.php":            false,
		"go-cli/cmd/x.go":             true,
		"tracking202/_config/top.php": true,
		"tracking202/_config/x.php":   false,
	} {
		got := matchesAnyTrigger(path, []string{"go-cli/**", "api/v3/Controllers/**", "api/v3/index.php", "tracking202/_config/top.php"})
		if got != want {
			t.Errorf("%s: got %v, want %v", path, got, want)
		}
	}
}
