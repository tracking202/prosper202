//go:build !js

package api

import (
	"net/http"
	"time"
)

const requestTimeout = 30 * time.Second

// platformTransport is the transport as given everywhere but the hosted
// (js/wasm) build; see transport_js.go.
func platformTransport(base http.RoundTripper) http.RoundTripper { return base }
