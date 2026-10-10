package api

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"time"

	"p202/internal/config"
)

const maxResponseSize = 10 << 20 // 10 MB

// maxDownloadSize bounds a file download (an attribution export's CSV).
// Download refuses a larger body rather than returning a short file.
const maxDownloadSize = 64 << 20 // 64 MB

// OpDownloadTooLarge marks a download refused because the body exceeded
// maxDownloadSize.
const OpDownloadTooLarge = "download_too_large"

// stagedMode, when set (the root --staged flag), stamps staged=1 onto every
// mutating request so the server records the write as a proposal (a staged
// change with a server-issued id) instead of executing it. The
// /staged-changes endpoints themselves are exempt — applying or discarding a
// proposal must never itself be staged. Servers advertise support via
// features.staged_writes; on writes that cannot be staged the server fails
// closed with a 422 rather than executing.
var stagedMode = false

// SetStagedMode turns proposal mode on or off for every subsequent request.
func SetStagedMode(on bool) {
	stagedMode = on
}

// StagedMode reports whether proposal mode is on.
func StagedMode() bool {
	return stagedMode
}

type Client struct {
	rootURL string
	apiKey  string
	http    *http.Client

	// mu guards every field below it. A Client is shared across goroutines
	// (cmd/crud.go fans bulk tracker-URL fetches over a worker pool with one
	// client; cmd/shell.go keeps a long-lived one), and ensureCapabilities()
	// lazily rewrites baseURL after version negotiation while in-flight
	// requests are reading it — an unsynchronized read/write pair.
	mu                 sync.Mutex
	baseURL            string
	capabilities       map[string]interface{}
	capabilitiesLoaded bool
	capabilitiesErr    error
}

// currentBaseURL returns the negotiated base URL under the lock.
func (c *Client) currentBaseURL() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.baseURL
}

type APIError struct {
	Status      int
	Message     string
	Category    string
	FieldErrors map[string]string
	Raw         map[string]interface{}
}

type RequestError struct {
	Kind string
	Op   string
	Err  error
}

func (e *APIError) Error() string {
	msg := fmt.Sprintf("API error (%d): %s", e.Status, e.Message)
	if len(e.FieldErrors) > 0 {
		for k, v := range e.FieldErrors {
			msg += fmt.Sprintf("\n  %s: %s", k, v)
		}
	}
	return msg
}

func (e *APIError) CategoryName() string {
	if e.Category != "" {
		return e.Category
	}
	return categoryForHTTPStatus(e.Status)
}

func (e *RequestError) Error() string {
	if e.Op == "" {
		return fmt.Sprintf("%s error: %v", e.Kind, e.Err)
	}
	return fmt.Sprintf("%s error (%s): %v", e.Kind, e.Op, e.Err)
}

func (e *RequestError) Unwrap() error {
	return e.Err
}

func (e *RequestError) CategoryName() string {
	return e.Kind
}

type categorizedError interface {
	CategoryName() string
}

func ErrorCategory(err error) string {
	if err == nil {
		return ""
	}
	var tagged categorizedError
	if errors.As(err, &tagged) {
		return strings.TrimSpace(strings.ToLower(tagged.CategoryName()))
	}
	return ""
}

// DeletedConversionID returns the conversion a POST /conversions 409 names when the request
// repeats a deleted conversion (details.deleted, details.conv_id), or 0 for any other error.
func DeletedConversionID(err error) int64 {
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 409 {
		return 0
	}
	details, _ := apiErr.Raw["details"].(map[string]interface{})
	if deleted, _ := details["deleted"].(bool); !deleted {
		return 0
	}
	id, _ := details["conv_id"].(float64)
	if id <= 0 || id != float64(int64(id)) {
		return 0
	}
	return int64(id)
}

// Hinted is implemented by errors that carry their own recovery hint.
type Hinted interface {
	HintText() string
}

// HintFor returns an actionable recovery hint for an error, or "" when there is
// nothing useful to add. It augments — never replaces — the error message.
// An explicit hint attached to the error wins; otherwise the HTTP status or
// request failure kind selects a generic one.
// fieldErrorsSay reports whether any of the error's field messages contains
// one of the phrases.
func fieldErrorsSay(apiErr *APIError, phrases ...string) bool {
	for _, msg := range apiErr.FieldErrors {
		for _, phrase := range phrases {
			if strings.Contains(msg, phrase) {
				return true
			}
		}
	}
	return false
}

func HintFor(err error) string {
	if err == nil {
		return ""
	}
	var hinted Hinted
	if errors.As(err, &hinted) {
		if h := strings.TrimSpace(hinted.HintText()); h != "" {
			return h
		}
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		switch {
		case apiErr.Status == 403 && strings.Contains(strings.ToLower(apiErr.Message), "scope"):
			return "This key's scope does not cover the operation. Use a key with the needed scope, or mint one: `p202 user apikey create <user_id> --scope write` (scopes: *, read, write, <area>:read, <area>:write)."
		case apiErr.Status == 403 && strings.Contains(apiErr.Message, "' permission"):
			// Auth::requirePermission(): the key is fine; its user's role
			// lacks a permission the pages ask for too.
			return "The key is valid, but its user's role lacks the permission named above (the UI's pages ask for the same one). An admin can grant a role that has it: `p202 user role list` shows the roles, `p202 user role assign <user_id> <role_id>` grants one; or use the key of a user whose role has it."
		case apiErr.Status == 403 && strings.Contains(apiErr.Message, "Admin access required"):
			// Auth::requireAdmin(): the key is fine; its user is not an
			// Admin or the Super user, which the route asks for.
			return "The key is valid, but its user holds neither the Admin nor the Super user role, which this needs. `p202 whoami` shows the key's user and roles; use an admin's key, or have an admin grant the Admin role: `p202 user role assign <user_id> 2`."
		case apiErr.Status == 401 && strings.Contains(strings.ToLower(apiErr.Message), "deactivated"):
			// Auth::fromRequest(): the key is right, but its user is
			// switched off (not Active in Account › Users); another key
			// of the same user would be refused the same way.
			return "The key is valid, but its user is switched off. An admin can switch the user back on: `p202 user update <user_id> --user-active 1`; or use the key of an active user."
		case apiErr.Status == 401 || apiErr.Status == 403:
			return "Verify your API key: run `p202 config show`, then `p202 config set-key <key>` if it's wrong."
		case apiErr.Status == 404:
			return "Not found. Run the matching `... list` to find valid ids (ids are internal — not the public ones in tracking links; some commands accept --public)."
		// A 409 has several unrelated causes -- a repeat of a deleted
		// conversion, a still-running idempotent retry, a spent
		// Idempotency-Key, an interrupted apply, a staged change in the
		// wrong state, an actual duplicate -- and "update it
		// instead of creating" is wrong advice for all but the last. Match
		// the specific causes first; the duplicate stays the fallback.
		case apiErr.Status == 409 && DeletedConversionID(err) > 0:
			return "The click's ledger keeps a deleted conversion's key, so the same conversion is never recorded again; nothing was written. `p202 click conversions <click_id>` shows the deleted row. A different sale needs its own --transaction-id."
		case apiErr.Status == 409 && strings.Contains(strings.ToLower(apiErr.Message), "still in flight"):
			return "The first request carrying this Idempotency-Key is still running. Wait, then retry the same command to receive its recorded response."
		case apiErr.Status == 409 && strings.Contains(strings.ToLower(apiErr.Message), "idempotency-key"):
			return "That Idempotency-Key is spent and its outcome is unknown. Run the matching `... list` to see whether the record exists, then retry with a new --idempotency-key only if it does not."
		case apiErr.Status == 409 && strings.Contains(apiErr.Message, "apply_interrupted"):
			return "The write may or may not have landed. Run the matching `... list` to check, then stage it again if it did not; this change id can no longer be applied or discarded."
		case apiErr.Status == 409 && strings.Contains(apiErr.Message, "chg_"):
			return "Only a staged change can be applied or discarded. Run `p202 change show <change_id>` for its current status."
		// An If-Match header, or a body carrying the version/etag of an
		// older read, names a version the record no longer has: it changed
		// since it was read, so a whole-record write would undo that.
		case apiErr.Status == 409 && strings.Contains(apiErr.Message, "Version mismatch"):
			return "The record changed since it was read. Read it again (`... get <id>`) and make the change on that, or send only the fields to change, without version or etag."
		case apiErr.Status == 409:
			return "A matching record already exists. Run the matching `... list` to find it, then `... update` it instead of creating."
		case (apiErr.Status == 400 || apiErr.Status == 422) && strings.Contains(strings.ToLower(apiErr.Message), "staged is not supported"):
			return "This endpoint cannot be staged; drop --staged to run the command directly (capabilities lists features.staged_writes)."
		// The server refuses a field it does not write, and a read-only one
		// (an id, a public id, version) that differs from the record's,
		// rather than dropping it: nothing was written.
		case apiErr.Status == 422 && fieldErrorsSay(apiErr, "is not a field of", "is set by the server", "is read-only"):
			return "Nothing was written. Remove the field(s) named above: the message lists the fields this endpoint accepts, " +
				"and a read-only one may only be sent with the value the record already has (for `p202 import`, remove them " +
				"from the file's records). If a p202 command sent them by itself, the CLI and the server differ in version: " +
				"compare `p202 --version` with `p202 system version`."
		// A key that already recorded a request answers a retry of that request; a
		// different body under it is refused (an Idempotency-Key, an LTV event's
		// idempotency_key, a subscription event's transaction id). Retrying the
		// same command repeats the refusal.
		case apiErr.Status == 422 && fieldErrorsSay(apiErr, "Already used for"):
			return "Nothing was written: that key already recorded a different request (the message says what differs). " +
				"A retry must send exactly what the first request sent; a different event needs its own --idempotency-key " +
				"(a subscription renewal or refund without one, its own --transaction-id)."
		case apiErr.Status == 422 && fieldErrorsSay(apiErr, "Already recorded as conversion"):
			return "Nothing was written: the click already has a conversion under that transaction id, and this request states a different sale. " +
				"A re-send must state what was recorded (`p202 click conversions <click_id>` shows it); a different sale needs its own " +
				"--transaction-id; `p202 conversion create --click-id <id> --status reversed --transaction-id <id>` takes the recorded one back."
		case apiErr.Status == 422 && len(apiErr.FieldErrors) > 0:
			return "Fix the field(s) listed above and retry."
		case apiErr.Status == 400 || apiErr.Status == 422:
			return "The server rejected the request values; fix what the message names and retry (`--help` lists the flags)."
		case apiErr.Status == 429:
			return "Rate limited. Wait and retry with backoff; reduce --concurrency for bulk operations."
		case apiErr.Status >= 500:
			return "Server-side failure. Retry after a short wait; if it persists, run `p202 system health` and check the server logs."
		}
		return ""
	}
	var reqErr *RequestError
	if errors.As(err, &reqErr) {
		if reqErr.Op == OpDownloadTooLarge {
			return "Nothing was written. Create a smaller export (a shorter --time-from/--time-to range, or a coarser --group-by such as campaign) and download that one."
		}
		switch reqErr.Kind {
		case "network":
			return "Check the server URL (`p202 config show`) and that the instance is reachable; run `p202 config test` to verify the connection."
		case "validation":
			return "The request could not be built from the given values; check them and retry."
		}
	}
	return ""
}

func NewFromConfig() (*Client, error) {
	profile, _, err := config.LoadProfileWithName("")
	if err != nil {
		return nil, err
	}
	if err := profile.Validate(); err != nil {
		return nil, err
	}
	return newClient(profile.URL, profile.APIKey), nil
}

func NewFromProfile(name string) (*Client, error) {
	profile, _, err := config.LoadProfileWithName(name)
	if err != nil {
		return nil, err
	}
	if err := profile.Validate(); err != nil {
		return nil, err
	}
	return newClient(profile.URL, profile.APIKey), nil
}

// NewURLOnly creates a client that only requires a configured URL (no API key).
// Use this for unauthenticated endpoints like system/health.
func NewURLOnly() (*Client, error) {
	profile, _, err := config.LoadProfileWithName("")
	if err != nil {
		return nil, err
	}
	if profile.URL == "" {
		return nil, fmt.Errorf("%w. Run: p202 config set-url <url>", config.ErrNoURL)
	}
	return newClient(profile.URL, profile.APIKey), nil // API key may be empty for URL-only endpoints.
}

func newClient(baseURL, apiKey string) *Client {
	rootURL := strings.TrimRight(baseURL, "/")
	return &Client{
		rootURL: rootURL,
		baseURL: rootURL + "/api/v3",
		apiKey:  apiKey,
		http:    &http.Client{Timeout: 30 * time.Second},
	}
}

func (c *Client) SupportsCapability(path ...string) bool {
	v, ok := c.Capability(path...)
	if !ok {
		return false
	}
	switch val := v.(type) {
	case bool:
		return val
	case string:
		return strings.EqualFold(val, "true") || val == "1"
	default:
		return false
	}
}

func (c *Client) Capability(path ...string) (interface{}, bool) {
	c.ensureCapabilities()
	c.mu.Lock()
	caps := c.capabilities
	c.mu.Unlock()
	if len(path) == 0 {
		return caps, len(caps) > 0
	}
	var current interface{} = caps
	for _, key := range path {
		obj, ok := current.(map[string]interface{})
		if !ok {
			return nil, false
		}
		next, ok := obj[key]
		if !ok {
			return nil, false
		}
		current = next
	}
	return current, true
}

// CapabilitiesError reports why the server capabilities could not be loaded,
// or nil if they loaded successfully. It lets callers distinguish "the server
// does not grant this capability" from "the capabilities could not be fetched".
func (c *Client) CapabilitiesError() error {
	c.ensureCapabilities()
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.capabilitiesErr
}

// ensureCapabilities loads capabilities at most once. It holds mu for the whole
// negotiate-and-load sequence so concurrent callers see either the pre- or the
// post-negotiation baseURL, never a torn read, and only one of them performs
// the network round-trips.
func (c *Client) ensureCapabilities() {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.capabilitiesLoaded {
		return
	}
	c.capabilitiesLoaded = true

	c.negotiateVersionLocked()

	req, err := http.NewRequest("GET", c.baseURL+"/capabilities", nil)
	if err != nil {
		c.capabilitiesErr = err
		return
	}
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "p202-cli/2.0 (Go)")

	resp, err := c.http.Do(req)
	if err != nil {
		c.capabilitiesErr = err
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		c.capabilitiesErr = fmt.Errorf("capabilities request returned HTTP %d", resp.StatusCode)
		return
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseSize))
	if err != nil {
		c.capabilitiesErr = fmt.Errorf("reading capabilities response: %w", err)
		return
	}
	var decoded map[string]interface{}
	if err := json.Unmarshal(body, &decoded); err != nil {
		c.capabilitiesErr = fmt.Errorf("parsing capabilities response: %w", err)
		return
	}
	if data, ok := decoded["data"].(map[string]interface{}); ok {
		c.capabilities = data
		return
	}
	c.capabilities = decoded
}

// apiVersionPattern constrains the version segment the SERVER hands back. It is
// interpolated straight into every subsequent request path, so anything other
// than digits (a traversal like "3/../../admin", a query string, a stray space)
// must not be accepted from a remote response.
var apiVersionPattern = regexp.MustCompile(`^[0-9]{1,4}$`)

// negotiateVersionLocked must be called with c.mu held.
func (c *Client) negotiateVersionLocked() {
	req, err := http.NewRequest("GET", c.rootURL+"/api/versions", nil)
	if err != nil {
		return
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "p202-cli/2.0 (Go)")

	resp, err := c.http.Do(req)
	if err != nil {
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		return
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseSize))
	if err != nil {
		return
	}
	var decoded map[string]interface{}
	if err := json.Unmarshal(body, &decoded); err != nil {
		return
	}

	var preferred string
	if data, ok := decoded["data"].(map[string]interface{}); ok {
		preferred, _ = data["preferred"].(string)
	} else {
		preferred, _ = decoded["preferred"].(string)
	}
	preferred = strings.TrimSpace(preferred)
	if preferred == "" {
		return
	}

	preferred = strings.TrimPrefix(strings.ToLower(preferred), "v")
	if !apiVersionPattern.MatchString(preferred) {
		// Keep the compiled-in default rather than trusting a malformed or
		// hostile version string from the server.
		return
	}
	c.baseURL = c.rootURL + "/api/v" + preferred
}

func (c *Client) Get(path string, params map[string]string) ([]byte, error) {
	return c.do("GET", path, params, nil)
}

// GetWithHeaders is Get with extra request headers — for endpoints whose
// credential travels as a header rather than as the API key or a query
// parameter (the public app schema endpoint's X-P202-App-Token; query
// strings land in access logs, headers do not).
func (c *Client) GetWithHeaders(path string, params map[string]string, headers map[string]string) ([]byte, error) {
	return c.doWithHeaders("GET", path, params, nil, headers)
}

func (c *Client) Post(path string, body interface{}) ([]byte, error) {
	return c.do("POST", path, nil, body)
}

// AppTokenHeader carries an app registration's token to the public app
// routes; a request that sends it sends no API key.
const AppTokenHeader = "X-P202-App-Token"

// PostWithHeaders is Post with extra request headers — for the public app
// intake, which is selected by X-P202-App-Token rather than the API key.
func (c *Client) PostWithHeaders(path string, body interface{}, headers map[string]string) ([]byte, error) {
	return c.doWithHeaders("POST", path, nil, body, headers)
}

// PostIdempotent sends a create with an Idempotency-Key header. Retrying the
// same key and payload replays the recorded response (idempotent_replay: true
// in the body) instead of creating a duplicate. Requires a server whose
// capabilities report features.create_idempotency; older servers ignore the
// header and create normally.
func (c *Client) PostIdempotent(path string, body interface{}, idempotencyKey string) ([]byte, error) {
	key := strings.TrimSpace(idempotencyKey)
	if key == "" {
		return c.Post(path, body)
	}
	return c.doWithHeaders("POST", path, nil, body, map[string]string{"Idempotency-Key": key})
}

// UpdateWriteTimeout is how long PostUpdate waits for an answer. The Update
// endpoints write one transaction per subid or report line, so a long list
// or a large report takes longer than the 30 seconds every other request
// gets — and a client that gives up while the server is still writing tells
// the caller "failed" about a write that is landing.
const UpdateWriteTimeout = 15 * time.Minute

// PostUpdate sends one of the Update endpoints' requests (POST /clicks/cpc,
// /conversions/subids, …/delete, …/reset, /conversions/uploads): a POST
// whose preview is `?dry_run=1` on the write's own route, so it takes query
// parameters. It waits up to UpdateWriteTimeout, and reads an answer of up to
// maxDownloadSize, refusing a larger one rather than truncating it (a revenue
// report's answer lists every line it did not record). A dry run is a read,
// so --staged never stamps it; the commands refuse --staged for the writes.
func (c *Client) PostUpdate(path string, params map[string]string, body interface{}) ([]byte, error) {
	slow := &http.Client{Timeout: UpdateWriteTimeout, Transport: c.http.Transport}
	return c.doWith(slow, "POST", path, params, body, nil, maxDownloadSize, true)
}

func (c *Client) Put(path string, body interface{}) ([]byte, error) {
	return c.do("PUT", path, nil, body)
}

// Patch sends a partial update: the server changes only the fields the body
// names (the /ltv customer, company and field updates). It goes through the
// same request path as Put, so --staged stamps it the same way and a server
// that cannot stage it refuses rather than performing it.
func (c *Client) Patch(path string, body interface{}) ([]byte, error) {
	return c.do("PATCH", path, nil, body)
}

func (c *Client) Delete(path string) error {
	_, err := c.do("DELETE", path, nil, nil)
	return err
}

// DeleteReturning is Delete keeping the response body — used when the
// response carries content, e.g. the 202 staged-change envelope under
// --staged.
func (c *Client) DeleteReturning(path string) ([]byte, error) {
	return c.do("DELETE", path, nil, nil)
}

// DeletePreview asks the server what a delete would remove without removing
// it (DELETE with dry_run=1) and returns the preview body. A server that
// supports previews fails closed per endpoint: paths without preview support
// reject rather than deleting. A server too old to know the parameter at all
// would ignore it and perform the delete, so support is confirmed first.
func (c *Client) DeletePreview(path string) ([]byte, error) {
	if err := c.requireFeature("delete_dry_run", "--dry-run",
		"Upgrade the server to 1.9.75 or later, or omit --dry-run and confirm the delete deliberately."); err != nil {
		return nil, err
	}
	return c.do("DELETE", path, map[string]string{"dry_run": "1"}, nil)
}

// requireFeature refuses to send a request whose safety depends on server
// support that is not advertised. These parameters are query parameters: a
// server that predates the feature ignores the unknown parameter and performs
// the real write, turning a preview or a proposal into an executed delete.
// Refusing to send is the only fail-closed option available to the client.
func (c *Client) requireFeature(flag, flagName, remedy string) error {
	if c.SupportsCapability("features", flag) {
		return nil
	}
	if err := c.CapabilitiesError(); err != nil {
		return &RequestError{
			Kind: "network",
			Op:   "verify_" + flag + "_support",
			Err: fmt.Errorf("could not confirm the server supports %s (%w); refusing to send it, "+
				"because a server without support would perform the write instead", flagName, err),
		}
	}
	return &APIError{
		Status:   422,
		Category: "validation",
		Message: fmt.Sprintf("this server does not support %s (capabilities.features.%s is not set); "+
			"refusing to send it, because a server without support would perform the write instead",
			flagName, flag),
		FieldErrors: map[string]string{flag: remedy},
	}
}

// readOnlyPost lists the POST endpoints that compute over their body and
// store nothing — reads that arrive as POST because the payload is their
// input. There is no proposal to record for them, so --staged never stamps
// them (the server would answer "staged is not supported").
var readOnlyPost = map[string]bool{
	"apps/verify":    true, // a postback's signature
	"goals/validate": true, // a goal definition
	"goals/evaluate": true, // definitions against events
}

func (c *Client) do(method, path string, params map[string]string, body interface{}) ([]byte, error) {
	return c.doWithHeaders(method, path, params, body, nil)
}

// Download GETs a file endpoint and returns its bytes. A JSON read above
// is capped by truncation; a file cannot be, because a short CSV reads as a
// complete one — so a body over maxDownloadSize is an error here.
func (c *Client) Download(path string) ([]byte, error) {
	return c.doLimited("GET", path, nil, nil, nil, maxDownloadSize, true)
}

func (c *Client) doWithHeaders(method, path string, params map[string]string, body interface{}, headers map[string]string) ([]byte, error) {
	return c.doLimited(method, path, params, body, headers, maxResponseSize, false)
}

func (c *Client) doLimited(method, path string, params map[string]string, body interface{}, headers map[string]string, limit int64, strict bool) ([]byte, error) {
	return c.doWith(c.http, method, path, params, body, headers, limit, strict)
}

// doWith is doLimited through a given HTTP client (PostUpdate's waits longer).
func (c *Client) doWith(httpClient *http.Client, method, path string, params map[string]string, body interface{}, headers map[string]string, limit int64, strict bool) ([]byte, error) {
	// Read once under the lock: version negotiation can rewrite baseURL from
	// another goroutine, and the URL and the version header below must agree.
	baseURL := c.currentBaseURL()
	u := baseURL + "/" + strings.TrimLeft(path, "/")

	if stagedMode &&
		(method == "POST" || method == "PUT" || method == "PATCH" || method == "DELETE") &&
		!strings.HasPrefix(strings.TrimLeft(path, "/"), "staged-changes") &&
		// Reads that arrive as POST (readOnlyPost) record no proposal;
		// stamping staged=1 would only earn the server's "staged is not
		// supported" rejection.
		!readOnlyPost[strings.TrimLeft(path, "/")] &&
		params["dry_run"] == "" {
		// A dry-run preview is a read; staging it would be rejected by the
		// server's mutual-exclusion check, so an explicit --dry-run wins
		// over the global --staged mode.
		if err := c.requireFeature("staged_writes", "--staged",
			"Upgrade the server to 1.9.75 or later, or drop --staged to perform the write directly."); err != nil {
			return nil, err
		}
		if params == nil {
			params = map[string]string{}
		}
		params["staged"] = "1"
	}

	if len(params) > 0 {
		v := url.Values{}
		for k, val := range params {
			v.Set(k, val)
		}
		u += "?" + v.Encode()
	}

	var bodyReader io.Reader
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return nil, &RequestError{Kind: "validation", Op: "encode_request_body", Err: err}
		}
		bodyReader = bytes.NewReader(data)
	}

	req, err := http.NewRequest(method, u, bodyReader)
	if err != nil {
		return nil, &RequestError{Kind: "validation", Op: "create_request", Err: err}
	}

	// The public app routes (the install intake, the schema) are selected
	// by the app's own token and never read the API key: sending it there
	// hands the account's credential to a route that does not need it.
	appRoute := false
	for name := range headers {
		if http.CanonicalHeaderKey(name) == http.CanonicalHeaderKey(AppTokenHeader) {
			appRoute = true
		}
	}
	if !appRoute {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "p202-cli/2.0 (Go)")
	if idx := strings.LastIndex(baseURL, "/api/v"); idx != -1 {
		req.Header.Set("X-P202-API-Version", baseURL[idx+5:])
	}
	for name, value := range headers {
		req.Header.Set(name, value)
	}

	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, &RequestError{Kind: "network", Op: "send_request", Err: err}
	}
	defer resp.Body.Close()

	readLimit := limit
	if strict {
		readLimit = limit + 1
	}
	respBody, err := io.ReadAll(io.LimitReader(resp.Body, readLimit))
	if err != nil {
		return nil, &RequestError{Kind: "network", Op: "read_response", Err: err}
	}

	if resp.StatusCode >= 400 {
		return nil, parseAPIError(resp.StatusCode, respBody)
	}
	if strict && int64(len(respBody)) > limit {
		// Not a network failure: the server answered in full and the file
		// is simply bigger than this client takes. The fix is a smaller
		// file, which HintFor names.
		return nil, &RequestError{Kind: "validation", Op: OpDownloadTooLarge, Err: fmt.Errorf("the file is larger than %d MB, the most this client downloads", limit>>20)}
	}

	return respBody, nil
}

func parseAPIError(status int, body []byte) *APIError {
	ae := &APIError{
		Status:   status,
		Message:  fmt.Sprintf("HTTP %d", status),
		Category: categoryForHTTPStatus(status),
	}

	var data map[string]interface{}
	if json.Unmarshal(body, &data) == nil {
		ae.Raw = data
		if msg, ok := data["message"].(string); ok {
			ae.Message = msg
		}
		if fe, ok := data["field_errors"].(map[string]interface{}); ok {
			ae.FieldErrors = make(map[string]string, len(fe))
			for k, v := range fe {
				ae.FieldErrors[k] = fmt.Sprintf("%v", v)
			}
		}
	}

	return ae
}

func categoryForHTTPStatus(status int) string {
	switch {
	case status == http.StatusUnauthorized || status == http.StatusForbidden:
		return "auth"
	case status >= 500:
		return "server"
	case status >= 400:
		return "validation"
	default:
		return "network"
	}
}
