package cmd

import (
	"go/ast"
	"go/parser"
	"go/token"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"sync/atomic"
	"testing"
)

// syncOrchestrator is a server that offers server-side sync jobs and plans
// and answers POST sync/jobs, sync/re-sync and sync/plan with jobAnswer; any
// other request to it, like every request to the target, is a list read the
// client-side fallback would make.
func syncOrchestrator(t *testing.T, jobStatus int, jobAnswer, polled string, reads *int32) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"sync_features":{"async_jobs":true,"sync_plan":true}}}`))
		case r.Method == http.MethodPost && (r.URL.Path == "/api/v3/sync/jobs" || r.URL.Path == "/api/v3/sync/re-sync" || r.URL.Path == "/api/v3/sync/plan"):
			w.WriteHeader(jobStatus)
			_, _ = w.Write([]byte(jobAnswer))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/worker/run":
			_, _ = w.Write([]byte(`{"data":{"processed":1}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/sync/jobs/j1":
			_, _ = w.Write([]byte(polled))
		default:
			atomic.AddInt32(reads, 1)
			_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
		}
	}))
	t.Cleanup(server.Close)
	return server
}

func syncProfiles(t *testing.T, source, target string) {
	t.Helper()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source, "api_key": "source-key-123456"},
		"target": {"url": target, "api_key": "target-key-123456"},
	})
}

// serverSidePaths are the commands that send both profiles to the --from
// profile's install, which calls them, and run on this machine when it
// cannot; via is the function that sends them.
var serverSidePaths = []struct {
	name string
	args []string
	via  string
}{
	{"sync", []string{"sync", "campaigns", "--from", "source", "--to", "target", "--json"}, "tryServerSideSync"},
	{"re-sync", []string{"re-sync", "--from", "source", "--to", "target", "--json"}, "tryServerSideSync"},
	{"diff", []string{"diff", "campaigns", "--from", "source", "--to", "target", "--json"}, "tryServerPlan"},
}

// profilesNotCalled are the functions that send both profiles to a server
// that only reads them, so it never refuses their urls, with why.
var profilesNotCalled = map[string]string{
	"tryServerSyncRead": "GET sync/status and sync/history name the pair to look up its audit records; SyncController::status() and history() resolve the profiles without calling them, so no url is refused",
}

// refusedProfileURL is how a 1.9.79 install answered POST /sync/plan,
// /sync/jobs and /sync/re-sync naming a profile url it will not call
// (measured live, loopback address, no P202_WEBHOOK_ALLOW_NETWORKS).
func refusedProfileURL(field string) string {
	return `{"error":true,"message":"This server does not call the sync ` + strings.TrimSuffix(field, ".url") + `'s url","status":422,` +
		`"field_errors":{"` + field + `":"127.0.0.1, which is a loopback address. Syncs are only sent to public addresses. ` +
		`To sync with an install on this server's own machine or network, add 127.0.0.1 (or its network) to P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php."}}`
}

// A profile url the server will not call is refused on its field. Every
// command that has the server call the profiles stops on it with the same
// category, exit code and hint: which profile to point where, and which
// operator setting admits an install on the server's own network. None runs
// on this machine instead, which would make the calls the server refused and
// never show why.
func TestServerSidePathsStopOnARefusedProfileURL(t *testing.T) {
	for _, field := range []string{"source.url", "target.url"} {
		profile := strings.TrimSuffix(field, ".url")
		var first map[string]interface{}
		for _, path := range serverSidePaths {
			t.Run(path.name+" "+field, func(t *testing.T) {
				var reads int32
				orchestrator := syncOrchestrator(t, 422, refusedProfileURL(field), "", &reads)
				target := syncOrchestrator(t, 404, `{}`, "", &reads)
				syncProfiles(t, orchestrator.URL, target.URL)

				stdout, _, err := executeCommand(path.args...)
				if err == nil {
					t.Fatal("the refusal was not an error")
				}
				if n := atomic.LoadInt32(&reads); n != 0 {
					t.Errorf("it ran on this machine instead: %d list reads", n)
				}
				if strings.TrimSpace(stdout) != "" {
					t.Errorf("stdout is not empty on a refusal: %q", stdout)
				}
				env, _ := errorEnvelope(err)["error"].(map[string]interface{})
				if env["category"] != "validation" || env["exit_code"] != ExitValidation || env["http_status"] != 422 {
					t.Errorf("category %v, exit %v, status %v; want validation, %d, 422", env["category"], env["exit_code"], env["http_status"], ExitValidation)
				}
				hint, _ := env["hint"].(string)
				for _, want := range []string{"`p202 --profile " + profile + " config set-url https://…`", "P202_WEBHOOK_ALLOW_NETWORKS", "never admitted"} {
					if !strings.Contains(hint, want) {
						t.Errorf("hint %q lacks %q", hint, want)
					}
				}
				if fields, _ := env["field_errors"].(map[string]string); fields[field] == "" {
					t.Errorf("the envelope does not carry the server's %s: %v", field, env["field_errors"])
				}
				delete(env, "command")
				if first == nil {
					first = env
				} else if !reflect.DeepEqual(env, first) {
					t.Errorf("%s answers the refusal differently from %s:\n%v\n%v", path.name, serverSidePaths[0].name, env, first)
				}
			})
		}
	}
}

// Any other answer to the request still runs the command on this machine,
// as before: a server without the route (a 404, or a 405 from what serves
// it), and a refusal of something else, such as an entity this CLI knows and
// that server does not.
func TestServerSidePathsStillFallBackForAnyOtherAnswer(t *testing.T) {
	for _, answer := range []struct {
		name   string
		status int
		body   string
	}{
		{"404", 404, `{"error":true,"message":"Not found","status":404}`},
		{"405", 405, `<html><head><title>405 Not Allowed</title></head></html>`},
		{"422 entity", 422, `{"error":true,"message":"Unsupported entity","status":422,"field_errors":{"entity":"Valid values: all, aff-networks"}}`},
		{"422 source.nmae", 422, `{"error":true,"message":"Invalid source.nmae","status":422,"field_errors":{"source.nmae":"is not a field of a sync profile (accepted: url, api_key, name)"}}`},
	} {
		for _, path := range serverSidePaths {
			t.Run(path.name+" "+answer.name, func(t *testing.T) {
				var reads int32
				orchestrator := syncOrchestrator(t, answer.status, answer.body, "", &reads)
				target := syncOrchestrator(t, 404, `{}`, "", &reads)
				syncProfiles(t, orchestrator.URL, target.URL)

				if _, _, err := executeCommand(path.args...); err != nil {
					t.Fatalf("the run on this machine: %v", err)
				}
				if atomic.LoadInt32(&reads) == 0 {
					t.Error("it did not fall back")
				}
			})
		}
	}
}

// TestEveryPathThatSendsTheProfilesIsHeldToTheRefusal finds every function
// that sends a profile's url and key to a server (loadProfileConnection
// builds what it sends) and requires each to be driven by a row of
// serverSidePaths, or listed in profilesNotCalled with why its server never
// refuses the url. A third command that has the server call the profiles and
// falls back on its answer then fails here until a row shows it stops on the
// refusal. A function that builds the profile without loadProfileConnection
// is not seen.
func TestEveryPathThatSendsTheProfilesIsHeldToTheRefusal(t *testing.T) {
	fset := token.NewFileSet()
	senders := map[string]bool{}
	files, err := filepath.Glob("*.go")
	if err != nil {
		t.Fatal(err)
	}
	for _, path := range files {
		if strings.HasSuffix(path, "_test.go") {
			continue
		}
		src, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		f, err := parser.ParseFile(fset, path, src, 0)
		if err != nil {
			t.Fatal(err)
		}
		// A function by its name, a command's RunE by the variable it is in.
		named := map[string]ast.Node{}
		for _, decl := range f.Decls {
			switch d := decl.(type) {
			case *ast.FuncDecl:
				named[d.Name.Name] = d
			case *ast.GenDecl:
				for _, spec := range d.Specs {
					if vs, ok := spec.(*ast.ValueSpec); ok && len(vs.Names) > 0 {
						named[vs.Names[0].Name] = vs
					}
				}
			}
		}
		for name, node := range named {
			if name == "loadProfileConnection" {
				continue
			}
			// Any mention, not only a call: a function value passed on sends too.
			ast.Inspect(node, func(n ast.Node) bool {
				if id, ok := n.(*ast.Ident); ok && id.Name == "loadProfileConnection" {
					senders[name] = true
				}
				return true
			})
		}
	}
	if len(senders) == 0 {
		t.Fatal("no call of loadProfileConnection found: the scan read nothing")
	}

	held := map[string]bool{}
	for _, path := range serverSidePaths {
		held[path.via] = true
	}
	for name := range profilesNotCalled {
		held[name] = true
	}
	var unheld, stale []string
	for name := range senders {
		if !held[name] {
			unheld = append(unheld, name)
		}
	}
	for name := range held {
		if !senders[name] {
			stale = append(stale, name)
		}
	}
	sort.Strings(unheld)
	sort.Strings(stale)
	for _, name := range unheld {
		t.Errorf("%s sends the profiles to a server: when that server calls them, return syncURLRefusal's error rather than falling back, and add a serverSidePaths row driving it; otherwise list it in profilesNotCalled with why", name)
	}
	for _, name := range stale {
		t.Errorf("%s is listed but sends no profile any more: remove it", name)
	}
}

// A job that stopped for a reason it names by error_code gets that reason's
// next step; one without a code keeps the general one.
func TestSyncAJobStoppedByTheGuardOrACapSaysWhatToDo(t *testing.T) {
	for code, want := range map[string]string{
		"sync_url_refused":    "P202_WEBHOOK_ALLOW_NETWORKS",
		"sync_url_unresolved": "does not resolve",
		"sync_limit":          "system health",
		"sync_key_unreadable": "queues a new job",
		"":                    "found on the target by their natural key",
	} {
		t.Run(code, func(t *testing.T) {
			var reads int32
			polled := `{"data":{"job_id":"j1","status":"failed","error":"stopped","error_code":"` + code + `"}}`
			if code == "" {
				polled = `{"data":{"job_id":"j1","status":"failed","error":"stopped","error_code":null}}`
			}
			orchestrator := syncOrchestrator(t, 202, `{"data":{"job_id":"j1","status":"queued"}}`, polled, &reads)
			target := syncOrchestrator(t, 404, `{}`, "", &reads)
			syncProfiles(t, orchestrator.URL, target.URL)

			_, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
			if err == nil || exitCodeForError(err) != ExitPartialFailure {
				t.Fatalf("want a partial failure, got %v (exit %d)", err, exitCodeForError(err))
			}
			if hint := hintFor(err); !strings.Contains(hint, want) {
				t.Errorf("hint %q lacks %q", hint, want)
			}
		})
	}
}
