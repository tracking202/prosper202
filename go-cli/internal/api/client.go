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
	return fmt.Sprintf("API error (%d): %s", e.Status, e.Message)
}

func NewFromConfig() (*Client, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}
	if err := cfg.Validate(); err != nil {
		return nil, err
	}
	return &Client{
		baseURL: strings.TrimRight(cfg.URL, "/") + "/api/v3",
		apiKey:  cfg.APIKey,
		http:    &http.Client{Timeout: 30 * time.Second},
	}, nil
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

	respBody, err := io.ReadAll(resp.Body)
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
