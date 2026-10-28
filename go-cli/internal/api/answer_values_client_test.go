package api

import (
	"bytes"
	"errors"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// tinyAnswer is a list answer of about n values, two to a row: the shape
// that costs an engine the most memory per byte.
func tinyAnswer(n int) []byte {
	rows := strings.Repeat(`{"a":0},`, n/2)
	return []byte(`{"data":[` + strings.TrimSuffix(rows, ",") + `]}`)
}

// installFetch answers a request in place of the network, so these tests
// run in the js/wasm build too, where nothing can listen.
type installFetch func(*http.Request) (*http.Response, error)

func (f installFetch) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

// valueInstall answers each path with what answers maps it to (200 unless
// status names another status), through http.DefaultTransport, which the
// client's transport sends through on every platform; it records the paths
// asked for and returns the install's address.
func valueInstall(t *testing.T, answers map[string][]byte, status map[string]int) (string, *[]string) {
	t.Helper()
	var asked []string
	saved := http.DefaultTransport
	t.Cleanup(func() { http.DefaultTransport = saved })
	http.DefaultTransport = installFetch(func(r *http.Request) (*http.Response, error) {
		asked = append(asked, r.URL.Path)
		code := http.StatusOK
		body, ok := answers[r.URL.Path]
		if !ok {
			switch r.URL.Path {
			case "/api/versions":
				body = []byte(`{"data":{"preferred":"v3","supported":["v3"]}}`)
			case "/api/v3/capabilities":
				body = []byte(`{"data":{"features":{"staged_writes":true}}}`)
			default:
				code = http.StatusNotFound
			}
		}
		if c, ok := status[r.URL.Path]; ok {
			code = c
		}
		return &http.Response{StatusCode: code, Header: http.Header{"Content-Type": {"application/json"}}, Body: io.NopCloser(bytes.NewReader(body)), Request: r}, nil
	})
	return "https://install.example", &asked
}

func boundFor(t *testing.T, n int) {
	t.Helper()
	LimitAnswerValues(n)
	t.Cleanup(func() { LimitAnswerValues(0) })
}

func TestBoundRefusesAnAnswerBeforeItIsDecoded(t *testing.T) {
	small, big := tinyAnswer(200), tinyAnswer(3000)
	base, asked := valueInstall(t, map[string][]byte{"/api/v3/small": small, "/api/v3/big": big}, nil)
	c := newClient(base, "key")

	// No bound: every answer is handed over as it came (a native run).
	if got, err := c.Get("big", nil); err != nil || string(got) != string(big) {
		t.Fatalf("unbounded: %v", err)
	}

	boundFor(t, 1000)
	if got, err := c.Get("small", nil); err != nil || string(got) != string(small) {
		t.Fatalf("an answer under the bound: %v", err)
	}
	got, err := c.Get("big", nil)
	if got != nil {
		t.Fatalf("a refused answer was handed over: %d bytes", len(got))
	}
	var reqErr *RequestError
	if !errors.As(err, &reqErr) || reqErr.Op != OpAnswerTooManyValues || reqErr.Kind != "validation" {
		t.Fatalf("an answer past the bound: %v", err)
	}
	if !strings.Contains(err.Error(), "more than 1000 JSON values") || !strings.Contains(HintFor(err), "smaller limit (with offset for the next page)") {
		t.Fatalf("refusal %q, hint %q", err, HintFor(err))
	}
	// The call is over its bound: it sends nothing more, read or write.
	sent := len(*asked)
	if _, err := c.Get("small", nil); !AnswerTooManyValues(err) || !strings.Contains(err.Error(), "GET small was not sent") {
		t.Fatalf("a read after the refusal: %v", err)
	}
	if _, err := c.Post("small", map[string]string{"a": "b"}); !AnswerTooManyValues(err) || !strings.Contains(err.Error(), "POST small was not sent") {
		t.Fatalf("a write after the refusal: %v", err)
	}
	if len(*asked) != sent {
		t.Fatalf("requests after the refusal reached the install: %v", (*asked)[sent:])
	}
}

// A write's answer past the bound is refused too, saying the write was sent
// and accepted, so it is looked for before it is sent again (CLAUDE.md #13).
func TestBoundSaysAWriteWasAcceptedBeforeItsAnswerWasRefused(t *testing.T) {
	base, asked := valueInstall(t, map[string][]byte{"/api/v3/campaigns": tinyAnswer(3000)}, map[string]int{"/api/v3/campaigns": 201})
	c := newClient(base, "key")
	boundFor(t, 1000)
	got, err := c.Post("campaigns", map[string]string{"aff_campaign_name": "x"})
	if got != nil || !AnswerTooManyValues(err) {
		t.Fatalf("a write's answer past the bound: %v", err)
	}
	if !strings.Contains(err.Error(), "accepted the POST (HTTP 201) before its answer was refused, so it may have changed data") {
		t.Fatalf("the refusal does not say the write was accepted: %v", err)
	}
	if fmt.Sprint(*asked) != "[/api/v3/campaigns]" {
		t.Fatalf("asked %v", *asked)
	}
	if !AnswerRefusedAfterWrite() {
		t.Fatal("the refused write is not recorded as one")
	}

	// A preview and a read sent as POST change nothing.
	for _, send := range []func(c *Client) error{
		func(c *Client) error {
			_, err := c.do("DELETE", "campaigns", map[string]string{"dry_run": "1"}, nil)
			return err
		},
		func(c *Client) error { _, err := c.Post("goals/validate", map[string]string{"a": "b"}); return err },
	} {
		base, _ := valueInstall(t, map[string][]byte{"/api/v3/campaigns": tinyAnswer(3000), "/api/v3/goals/validate": tinyAnswer(3000)}, nil)
		boundFor(t, 1000)
		err := send(newClient(base, "key"))
		if !AnswerTooManyValues(err) || strings.Contains(err.Error(), "may have changed data") || AnswerRefusedAfterWrite() {
			t.Fatalf("a request that changes nothing reads as a write: %v", err)
		}
	}
}

// An error answer past the bound keeps its status, which says what failed,
// and its body is not decoded; one under it is read as before.
func TestBoundKeepsTheStatusOfAnErrorAnswer(t *testing.T) {
	answers := map[string][]byte{
		"/api/v3/broken":  tinyAnswer(3000),
		"/api/v3/refused": []byte(`{"message":"Validation failed","field_errors":{"limit":"Must be 1 to 500"}}`),
	}
	base, _ := valueInstall(t, answers, map[string]int{"/api/v3/broken": 500, "/api/v3/refused": 422})
	c := newClient(base, "key")

	boundFor(t, 1000)
	_, err := c.Get("refused", nil)
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 422 || apiErr.Message != "Validation failed" || apiErr.FieldErrors["limit"] == "" {
		t.Fatalf("an error answer under the bound: %v", err)
	}

	boundFor(t, 1000)
	_, err = c.Get("broken", nil)
	if !errors.As(err, &apiErr) || apiErr.Status != 500 || apiErr.CategoryName() != "server" {
		t.Fatalf("an error answer past the bound: %v", err)
	}
	if apiErr.Raw != nil || !strings.Contains(apiErr.Message, "not read") || !strings.Contains(apiErr.Message, "more than 1000 JSON values") {
		t.Fatalf("its body was decoded, or the message does not say why it is missing: %q, raw %v", apiErr.Message, apiErr.Raw)
	}
	if AnswerTooManyValues(err) || !strings.Contains(HintFor(err), "Server-side failure") {
		t.Fatalf("a 500 reads as the refusal: hint %q", HintFor(err))
	}
}

// The capabilities and version answers are decoded too, so they are counted.
func TestBoundCoversTheCapabilitiesAndVersionAnswers(t *testing.T) {
	big := tinyAnswer(3000)

	base, _ := valueInstall(t, map[string][]byte{"/api/v3/capabilities": big}, nil)
	boundFor(t, 1000)
	c := newClient(base, "key")
	if c.SupportsCapability("features", "staged_writes") || !AnswerTooManyValues(c.CapabilitiesError()) {
		t.Fatalf("capabilities past the bound: %v", c.CapabilitiesError())
	}
	if err := c.requireFeature("staged_writes", "--staged", "x"); !AnswerTooManyValues(err) {
		t.Fatalf("a feature check on refused capabilities: %v", err)
	}

	base, asked := valueInstall(t, map[string][]byte{"/api/versions": big, "/api/v3/small": tinyAnswer(10)}, nil)
	boundFor(t, 1000)
	c = newClient(base, "key")
	c.ensureCapabilities()
	if c.currentBaseURL() != base+"/api/v3" {
		t.Fatalf("base URL %s after a refused version answer", c.currentBaseURL())
	}
	if !AnswerTooManyValues(c.CapabilitiesError()) {
		t.Fatalf("capabilities after a refused version answer: %v", c.CapabilitiesError())
	}
	if _, err := c.Get("small", nil); !AnswerTooManyValues(err) {
		t.Fatalf("the answer after a refused version answer: %v", err)
	}
	if fmt.Sprint(*asked) != "[/api/versions]" {
		t.Fatalf("asked %v after a refused version answer, want nothing more", *asked)
	}
}

// Every read of an answer's body in this package goes through readAnswer, so
// none is decoded uncounted: a .Body is closed or handed to readAnswer, and
// io.ReadAll is called in readAnswer alone. It reads this package's source,
// so a body read through another package's helper is not seen.
func TestAnswerBodiesAreReadOnlyByReadAnswer(t *testing.T) {
	files, err := filepath.Glob("*.go")
	if err != nil {
		t.Fatal(err)
	}
	fset := token.NewFileSet()
	checked := 0
	for _, name := range files {
		if strings.HasSuffix(name, "_test.go") {
			continue
		}
		src, err := os.ReadFile(name)
		if err != nil {
			t.Fatal(err)
		}
		f, err := parser.ParseFile(fset, name, src, 0)
		if err != nil {
			t.Fatal(err)
		}
		checked++
		for _, decl := range f.Decls {
			fn, ok := decl.(*ast.FuncDecl)
			if !ok || fn.Body == nil {
				continue
			}
			var stack []ast.Node
			ast.Inspect(fn.Body, func(n ast.Node) bool {
				if n == nil {
					stack = stack[:len(stack)-1]
					return true
				}
				parent := ast.Node(nil)
				if len(stack) > 0 {
					parent = stack[len(stack)-1]
				}
				stack = append(stack, n)
				sel, ok := n.(*ast.SelectorExpr)
				if !ok {
					return true
				}
				pos := fset.Position(sel.Pos())
				if pkg, ok := sel.X.(*ast.Ident); ok && pkg.Name == "io" && sel.Sel.Name == "ReadAll" && fn.Name.Name != "readAnswer" {
					t.Errorf("%s: %s calls io.ReadAll; read an answer with readAnswer, which counts its values", pos, fn.Name.Name)
				}
				if sel.Sel.Name != "Body" {
					return true
				}
				if closer, ok := parent.(*ast.SelectorExpr); ok && closer.X == sel && closer.Sel.Name == "Close" {
					return true
				}
				if call, ok := parent.(*ast.CallExpr); ok && len(call.Args) > 0 && call.Args[0] == sel {
					if id, ok := call.Fun.(*ast.Ident); ok && id.Name == "readAnswer" {
						return true
					}
				}
				t.Errorf("%s: %s uses a .Body other than closing it or handing it to readAnswer", pos, fn.Name.Name)
				return true
			})
		}
	}
	if checked < 3 {
		t.Fatalf("read %d source files; run from the package directory", checked)
	}
}
