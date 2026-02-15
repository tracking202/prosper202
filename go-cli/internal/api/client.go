package api

import (
	"bytes"
	"encoding/json"
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
	baseURL string
	apiKey  string
	http    *http.Client
}

type APIError struct {
	Status      int
	Message     string
	FieldErrors map[string]string
	Raw         map[string]interface{}
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
	return &Client{
		baseURL: strings.TrimRight(baseURL, "/") + "/api/v3",
		apiKey:  apiKey,
		http:    &http.Client{Timeout: 30 * time.Second},
	}
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
			return nil, fmt.Errorf("encoding request body: %w", err)
		}
		bodyReader = bytes.NewReader(data)
	}

	req, err := http.NewRequest(method, u, bodyReader)
	if err != nil {
		return nil, fmt.Errorf("creating request: %w", err)
	}

	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "p202-cli/2.0 (Go)")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseSize))
	if err != nil {
		return nil, fmt.Errorf("reading response: %w", err)
	}

	if resp.StatusCode >= 400 {
		return nil, parseAPIError(resp.StatusCode, respBody)
	}

	return respBody, nil
}

func parseAPIError(status int, body []byte) *APIError {
	ae := &APIError{Status: status, Message: fmt.Sprintf("HTTP %d", status)}

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
