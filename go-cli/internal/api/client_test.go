package api

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// newTestClient creates a Client that points to the given test server URL.
func newTestClient(baseURL string) *Client {
	return &Client{
		baseURL: baseURL,
		apiKey:  "test-api-key-1234",
		http:    http.DefaultClient,
	}
}

func TestGetSendsCorrectRequest(t *testing.T) {
	var (
		gotMethod string
		gotPath   string
		gotAuth   string
		gotParams map[string]string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		gotAuth = r.Header.Get("Authorization")
		gotParams = map[string]string{}
		for k, v := range r.URL.Query() {
			gotParams[k] = v[0]
		}
		w.WriteHeader(200)
		w.Write([]byte(`{"status":"ok"}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	params := map[string]string{"page": "2", "limit": "10"}
	data, err := c.Get("/campaigns", params)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}

	if gotMethod != "GET" {
		t.Errorf("method = %q, want GET", gotMethod)
	}
	if !strings.HasSuffix(gotPath, "/campaigns") {
		t.Errorf("path = %q, want suffix /campaigns", gotPath)
	}
	if gotAuth != "Bearer test-api-key-1234" {
		t.Errorf("Authorization = %q, want %q", gotAuth, "Bearer test-api-key-1234")
	}
	if gotParams["page"] != "2" {
		t.Errorf("param page = %q, want %q", gotParams["page"], "2")
	}
	if gotParams["limit"] != "10" {
		t.Errorf("param limit = %q, want %q", gotParams["limit"], "10")
	}
	if string(data) != `{"status":"ok"}` {
		t.Errorf("response body = %q, want %q", string(data), `{"status":"ok"}`)
	}
}

func TestGetWithNilParams(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.RawQuery != "" {
			t.Errorf("expected no query params, got %q", r.URL.RawQuery)
		}
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Get("/test", nil)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}
}

func TestPostSendsJSONBody(t *testing.T) {
	var (
		gotMethod      string
		gotContentType string
		gotBody        map[string]interface{}
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotContentType = r.Header.Get("Content-Type")
		body, _ := io.ReadAll(r.Body)
		json.Unmarshal(body, &gotBody)
		w.WriteHeader(201)
		w.Write([]byte(`{"id":1}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	payload := map[string]string{"name": "test-campaign", "url": "https://example.com"}
	data, err := c.Post("/campaigns", payload)
	if err != nil {
		t.Fatalf("Post() error: %v", err)
	}

	if gotMethod != "POST" {
		t.Errorf("method = %q, want POST", gotMethod)
	}
	if gotContentType != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", gotContentType)
	}
	if gotBody["name"] != "test-campaign" {
		t.Errorf("body.name = %v, want %q", gotBody["name"], "test-campaign")
	}
	if gotBody["url"] != "https://example.com" {
		t.Errorf("body.url = %v, want %q", gotBody["url"], "https://example.com")
	}
	if string(data) != `{"id":1}` {
		t.Errorf("response = %q, want %q", string(data), `{"id":1}`)
	}
}

func TestPutSendsJSONBody(t *testing.T) {
	var (
		gotMethod string
		gotBody   map[string]interface{}
		gotPath   string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		body, _ := io.ReadAll(r.Body)
		json.Unmarshal(body, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"id":5,"name":"updated"}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	payload := map[string]string{"name": "updated"}
	data, err := c.Put("/campaigns/5", payload)
	if err != nil {
		t.Fatalf("Put() error: %v", err)
	}

	if gotMethod != "PUT" {
		t.Errorf("method = %q, want PUT", gotMethod)
	}
	if !strings.HasSuffix(gotPath, "/campaigns/5") {
		t.Errorf("path = %q, want suffix /campaigns/5", gotPath)
	}
	if gotBody["name"] != "updated" {
		t.Errorf("body.name = %v, want %q", gotBody["name"], "updated")
	}
	if data == nil {
		t.Error("response should not be nil")
	}
}

func TestDeleteSendsDeleteMethod(t *testing.T) {
	var gotMethod, gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		w.WriteHeader(204)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	err := c.Delete("/campaigns/3")
	if err != nil {
		t.Fatalf("Delete() error: %v", err)
	}

	if gotMethod != "DELETE" {
		t.Errorf("method = %q, want DELETE", gotMethod)
	}
	if !strings.HasSuffix(gotPath, "/campaigns/3") {
		t.Errorf("path = %q, want suffix /campaigns/3", gotPath)
	}
}

func TestSuccessfulResponseReturnsBody(t *testing.T) {
	want := `{"data":[{"id":1},{"id":2}]}`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(want))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	data, err := c.Get("/items", nil)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}
	if string(data) != want {
		t.Errorf("body = %q, want %q", string(data), want)
	}
}

func TestRequestHeaders(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Verify all expected headers
		if got := r.Header.Get("Accept"); got != "application/json" {
			t.Errorf("Accept = %q, want application/json", got)
		}
		if got := r.Header.Get("User-Agent"); got != "p202-cli/2.0 (Go)" {
			t.Errorf("User-Agent = %q, want p202-cli/2.0 (Go)", got)
		}
		if got := r.Header.Get("Content-Type"); got != "application/json" {
			t.Errorf("Content-Type = %q, want application/json", got)
		}
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Get("/test", nil)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}
}

func TestError400ParsesMessage(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(400)
		w.Write([]byte(`{"message":"Invalid request parameters"}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Get("/bad", nil)
	if err == nil {
		t.Fatal("expected error for 400 response")
	}

	apiErr, ok := err.(*APIError)
	if !ok {
		t.Fatalf("error type = %T, want *APIError", err)
	}
	if apiErr.Status != 400 {
		t.Errorf("Status = %d, want 400", apiErr.Status)
	}
	if apiErr.Message != "Invalid request parameters" {
		t.Errorf("Message = %q, want %q", apiErr.Message, "Invalid request parameters")
	}
}

func TestError422ParsesFieldErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(422)
		w.Write([]byte(`{
			"message": "Validation failed",
			"field_errors": {
				"name": "is required",
				"url": "must be a valid URL"
			}
		}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Post("/campaigns", map[string]string{})
	if err == nil {
		t.Fatal("expected error for 422 response")
	}

	apiErr, ok := err.(*APIError)
	if !ok {
		t.Fatalf("error type = %T, want *APIError", err)
	}
	if apiErr.Status != 422 {
		t.Errorf("Status = %d, want 422", apiErr.Status)
	}
	if apiErr.Message != "Validation failed" {
		t.Errorf("Message = %q, want %q", apiErr.Message, "Validation failed")
	}
	if len(apiErr.FieldErrors) != 2 {
		t.Fatalf("FieldErrors has %d entries, want 2", len(apiErr.FieldErrors))
	}
	if apiErr.FieldErrors["name"] != "is required" {
		t.Errorf("FieldErrors[name] = %q, want %q", apiErr.FieldErrors["name"], "is required")
	}
	if apiErr.FieldErrors["url"] != "must be a valid URL" {
		t.Errorf("FieldErrors[url] = %q, want %q", apiErr.FieldErrors["url"], "must be a valid URL")
	}
}

func TestError500ReturnsGenericError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`Internal Server Error`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Get("/broken", nil)
	if err == nil {
		t.Fatal("expected error for 500 response")
	}

	apiErr, ok := err.(*APIError)
	if !ok {
		t.Fatalf("error type = %T, want *APIError", err)
	}
	if apiErr.Status != 500 {
		t.Errorf("Status = %d, want 500", apiErr.Status)
	}
	// When the body is not valid JSON, message falls back to "HTTP 500"
	if apiErr.Message != "HTTP 500" {
		t.Errorf("Message = %q, want %q", apiErr.Message, "HTTP 500")
	}
}

func TestError500WithJSONBody(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"Database connection lost"}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Get("/broken", nil)
	if err == nil {
		t.Fatal("expected error for 500 response")
	}

	apiErr, ok := err.(*APIError)
	if !ok {
		t.Fatalf("error type = %T, want *APIError", err)
	}
	if apiErr.Message != "Database connection lost" {
		t.Errorf("Message = %q, want %q", apiErr.Message, "Database connection lost")
	}
}

func TestAPIErrorErrorFormat(t *testing.T) {
	tests := []struct {
		name   string
		err    APIError
		expect string
	}{
		{
			name:   "400 with message",
			err:    APIError{Status: 400, Message: "Bad Request"},
			expect: "API error (400): Bad Request",
		},
		{
			name:   "404 with message",
			err:    APIError{Status: 404, Message: "Not found"},
			expect: "API error (404): Not found",
		},
		{
			name:   "500 generic",
			err:    APIError{Status: 500, Message: "HTTP 500"},
			expect: "API error (500): HTTP 500",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := tt.err.Error()
			if got != tt.expect {
				t.Errorf("Error() = %q, want %q", got, tt.expect)
			}
		})
	}
}

func TestPostWithNilBody(t *testing.T) {
	var gotBody []byte

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotBody, _ = io.ReadAll(r.Body)
		w.WriteHeader(201)
		w.Write([]byte(`{"created":true}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL)
	_, err := c.Post("/items", nil)
	if err != nil {
		t.Fatalf("Post() error: %v", err)
	}
	if len(gotBody) != 0 {
		t.Errorf("body should be empty for nil input, got %q", string(gotBody))
	}
}

func TestURLPathConcatenation(t *testing.T) {
	// Verify that leading slashes don't cause double-slashing
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	c := newTestClient(srv.URL + "/api/v3")

	// Path with leading slash
	_, err := c.Get("/campaigns", nil)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}
	if gotPath != "/api/v3/campaigns" {
		t.Errorf("path = %q, want /api/v3/campaigns", gotPath)
	}

	// Path without leading slash
	_, err = c.Get("campaigns", nil)
	if err != nil {
		t.Fatalf("Get() error: %v", err)
	}
	if gotPath != "/api/v3/campaigns" {
		t.Errorf("path = %q, want /api/v3/campaigns", gotPath)
	}
}

func TestAPIErrorCategoryByStatus(t *testing.T) {
	tests := []struct {
		status  int
		wantCat string
	}{
		{status: 400, wantCat: "validation"},
		{status: 401, wantCat: "auth"},
		{status: 403, wantCat: "auth"},
		{status: 500, wantCat: "server"},
	}

	for _, tt := range tests {
		t.Run(strings.TrimSpace(http.StatusText(tt.status)), func(t *testing.T) {
			err := parseAPIError(tt.status, []byte(`{"message":"boom"}`))
			if got := err.CategoryName(); got != tt.wantCat {
				t.Fatalf("CategoryName() = %q, want %q", got, tt.wantCat)
			}
			if got := ErrorCategory(err); got != tt.wantCat {
				t.Fatalf("ErrorCategory() = %q, want %q", got, tt.wantCat)
			}
		})
	}
}

func TestRequestErrorCarriesCategory(t *testing.T) {
	reqErr := &RequestError{
		Kind: "network",
		Op:   "send_request",
		Err:  io.EOF,
	}
	if got := ErrorCategory(reqErr); got != "network" {
		t.Fatalf("ErrorCategory() = %q, want network", got)
	}
	if !strings.Contains(reqErr.Error(), "network error") {
		t.Fatalf("unexpected error format: %q", reqErr.Error())
	}
}
