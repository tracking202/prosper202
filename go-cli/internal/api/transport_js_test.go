//go:build js

package api

import (
	"net/http"
	"testing"
	"time"
)

type recordingTransport struct{ got *http.Request }

func (r *recordingTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	r.got = req
	return &http.Response{StatusCode: http.StatusOK, Body: http.NoBody, Request: req}, nil
}

// TestHostedRequestsRefuseRedirectsAndWaitLess: the hosted engine's requests
// carry the install's key, so none follows a redirect, and each gives up
// sooner than at a terminal.
func TestHostedRequestsRefuseRedirectsAndWaitLess(t *testing.T) {
	c := newClient("https://install.example/", "key")
	if c.http.Timeout != 20*time.Second {
		t.Fatalf("hosted request timeout = %s, want 20s", c.http.Timeout)
	}
	nr, ok := c.http.Transport.(noRedirectTransport)
	if !ok {
		t.Fatalf("hosted transport is %T, want noRedirectTransport", c.http.Transport)
	}

	rec := &recordingTransport{}
	nr.base = rec
	req, err := http.NewRequest("GET", "https://install.example/api/v3/capabilities", nil)
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer key")
	if _, err := nr.RoundTrip(req); err != nil {
		t.Fatal(err)
	}
	if got := rec.got.Header.Get(jsFetchRedirect); got != "manual" {
		t.Fatalf("%s = %q, want \"manual\" (Cloudflare's fetch takes only follow and manual)", jsFetchRedirect, got)
	}
	if req.Header.Get(jsFetchRedirect) != "" {
		t.Fatal("the caller's request was modified; the transport must clone it")
	}

	// fetch hands a redirect back as a response; the client must not follow it.
	to, _ := http.NewRequest("GET", "https://elsewhere.example/x", nil)
	for _, client := range []*http.Client{c.http, c.slowClient()} {
		if client.CheckRedirect == nil || client.CheckRedirect(to, []*http.Request{req}) == nil {
			t.Fatal("a hosted client must refuse to follow a redirect")
		}
		if _, ok := client.Transport.(noRedirectTransport); !ok {
			t.Fatalf("client transport is %T, want noRedirectTransport", client.Transport)
		}
	}
}
