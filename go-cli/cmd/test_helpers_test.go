package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"
)

// CapturedRequest stores request details for assertions in command tests.
type CapturedRequest struct {
	Method string
	Path   string
	Query  url.Values
	Header http.Header
	Body   map[string]interface{}
}

// NewCapturingServer returns a mock server and a shared capture struct.
// If responseJSON is empty, it returns {"data":{}}.
func NewCapturingServer(t *testing.T, responseJSON string, status int) (*httptest.Server, *CapturedRequest) {
	t.Helper()

	capture := &CapturedRequest{}
	if responseJSON == "" {
		responseJSON = `{"data":{}}`
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		capture.Method = r.Method
		capture.Path = r.URL.Path
		capture.Query = r.URL.Query()
		capture.Header = r.Header.Clone()

		if r.Body != nil {
			bodyBytes, _ := io.ReadAll(r.Body)
			if len(bodyBytes) > 0 {
				var parsed map[string]interface{}
				if json.Unmarshal(bodyBytes, &parsed) == nil {
					capture.Body = parsed
				}
			}
		}

		w.WriteHeader(status)
		_, _ = w.Write([]byte(responseJSON))
	}))

	return srv, capture
}
