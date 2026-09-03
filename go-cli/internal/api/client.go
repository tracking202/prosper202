package api

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"p202/internal/config"
)

const maxResponseSize = 10 << 20 // 10 MB

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
	baseURL string
	apiKey  string
	http    *http.Client

	capabilities       map[string]interface{}
	capabilitiesLoaded bool
	capabilitiesErr    error
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

// Hinted is implemented by errors that carry their own recovery hint.
type Hinted interface {
	HintText() string
}

// HintFor returns an actionable recovery hint for an error, or "" when there is
// nothing useful to add. It augments — never replaces — the error message.
// An explicit hint attached to the error wins; otherwise the HTTP status or
// request failure kind selects a generic one.
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
		case apiErr.Status == 401 || apiErr.Status == 403:
			return "Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong."
		case apiErr.Status == 404:
			return "Not found. Run the matching `... list` to find valid ids (ids are internal — not the public ones in tracking links; some commands accept --public)."
		case apiErr.Status == 409:
			return "A matching record already exists. Run the matching `... list` to find it, then `... update` it instead of creating."
		case (apiErr.Status == 400 || apiErr.Status == 422) && strings.Contains(strings.ToLower(apiErr.Message), "staged is not supported"):
			return "This endpoint cannot be staged; drop --staged to run the command directly (capabilities lists features.staged_writes)."
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
		switch reqErr.Kind {
		case "network":
			return "Check the server URL (`p202 config get`) and that the instance is reachable; run `p202 config test` to verify the connection."
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
		return nil, fmt.Errorf("no URL configured. Run: p202 config set-url <url>")
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
	if len(path) == 0 {
		return c.capabilities, len(c.capabilities) > 0
	}
	var current interface{} = c.capabilities
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
	return c.capabilitiesErr
}

func (c *Client) ensureCapabilities() {
	if c.capabilitiesLoaded {
		return
	}
	c.capabilitiesLoaded = true

	c.negotiateVersion()

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

func (c *Client) negotiateVersion() {
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
	if preferred == "" {
		return
	}
	c.baseURL = c.rootURL + "/api/v" + preferred
}

func (c *Client) Get(path string, params map[string]string) ([]byte, error) {
	return c.do("GET", path, params, nil)
}

func (c *Client) Post(path string, body interface{}) ([]byte, error) {
	return c.do("POST", path, nil, body)
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

func (c *Client) Put(path string, body interface{}) ([]byte, error) {
	return c.do("PUT", path, nil, body)
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

func (c *Client) do(method, path string, params map[string]string, body interface{}) ([]byte, error) {
	return c.doWithHeaders(method, path, params, body, nil)
}

func (c *Client) doWithHeaders(method, path string, params map[string]string, body interface{}, headers map[string]string) ([]byte, error) {
	u := c.baseURL + "/" + strings.TrimLeft(path, "/")

	if stagedMode &&
		(method == "POST" || method == "PUT" || method == "PATCH" || method == "DELETE") &&
		!strings.HasPrefix(strings.TrimLeft(path, "/"), "staged-changes") &&
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

	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "p202-cli/2.0 (Go)")
	if idx := strings.LastIndex(c.baseURL, "/api/v"); idx != -1 {
		req.Header.Set("X-P202-API-Version", c.baseURL[idx+5:])
	}
	for name, value := range headers {
		req.Header.Set(name, value)
	}

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, &RequestError{Kind: "network", Op: "send_request", Err: err}
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseSize))
	if err != nil {
		return nil, &RequestError{Kind: "network", Op: "read_response", Err: err}
	}

	if resp.StatusCode >= 400 {
		return nil, parseAPIError(resp.StatusCode, respBody)
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
