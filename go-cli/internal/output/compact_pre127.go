//go:build !go1.27

package output

// canonicalCompact needs encoding/json/jsontext, which is Go 1.27's; before
// it the compact form decodes and re-encodes.
func canonicalCompact([]byte) ([]byte, bool) { return nil, false }
