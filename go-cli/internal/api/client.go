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

// HintFor returns an actionable recovery hint for an error, or "" when there is
// nothing useful to add. It augments — never replaces — the error message.
func HintFor(err error) string {
	if err == nil {
		return ""
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		switch {
		case apiErr.Status == 401 || apiErr.Status == 403:
			return "Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong."
		case apiErr.Status == 404:
			return "Not found. Run the matching `... list` to find valid ids (ids are internal — not the public ones in tracking links; some commands accept --public)."
		case apiErr.Status == 422 && len(apiErr.FieldErrors) > 0:
			return "Fix the field(s) listed above and retry."
		}
		return ""
	}
	var reqErr *RequestError
	if errors.As(err, &reqErr) {
		if reqErr.Kind == "network" {
			return "Check the server URL (`p202 config get`) and that the instance is reachable."
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

func (c *Client) Put(path string, body interface{}) ([]byte, error) {
	return c.do("PUT", path, nil, body)
}

func (c *Client) Delete(path string) error {
	_, err := c.do("DELETE", path, nil, nil)
	return err
}

func (c *Client) do(method, path string, params map[string]string, body interface{}) ([]byte, error) {
	u := c.baseURL + "/" + strings.TrimLeft(path, "/")

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
