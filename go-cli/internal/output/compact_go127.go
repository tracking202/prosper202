//go:build go1.27

package output

import (
	"bytes"
	"encoding/json/jsontext"
)

// canonicalCompact is the compact form without decoding the answer into
// maps: RFC 8785 sorts members, formats numbers as float64 and drops
// whitespace, as the decode-and-encode path does, while allocating a copy of
// the bytes rather than a map and a boxed value per JSON value. ok is false
// where the two could differ (invalid JSON or UTF-8, a duplicate name, U+2028
// or U+2029, which encoding/json escapes and RFC 8785 does not, a number past
// float64, which RFC 8785 clamps to the largest and encoding/json refuses);
// the caller then takes the old path. One difference remains: -0 is written
// as 0.
func canonicalCompact(data []byte) ([]byte, bool) {
	v := jsontext.Value(bytes.Clone(data))
	if err := v.Canonicalize(); err != nil {
		return nil, false
	}
	if bytes.Contains(v, []byte("\u2028")) || bytes.Contains(v, []byte("\u2029")) || bytes.Contains(v, []byte("1.7976931348623157e+308")) {
		return nil, false
	}
	return append(v, '\n'), true
}
