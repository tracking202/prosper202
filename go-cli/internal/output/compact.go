package output

import (
	"bytes"
	"encoding/json/jsontext"
	"errors"
	"io"
	"unicode/utf8"
)

// canonicalCompact is the compact form without decoding the answer into
// maps: RFC 8785 sorts members, formats numbers as float64 and drops
// whitespace, as the decode-and-encode path does, while allocating a copy of
// the bytes rather than a map and a boxed value per JSON value. ok is false
// where the two could differ (invalid JSON or UTF-8, a duplicate name, U+2028
// or U+2029, which encoding/json escapes and RFC 8785 does not, a number past
// float64, which RFC 8785 clamps to the largest and encoding/json refuses,
// a non-ASCII member name, which RFC 8785 sorts by UTF-16 and encoding/json
// by UTF-8);
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
	if nonASCIIName(v) {
		return nil, false
	}
	return append(v, '\n'), true
}

// nonASCIIName reports whether an object member name in v holds a non-ASCII
// character; v is valid JSON. All-ASCII input skips the token walk.
func nonASCIIName(v []byte) bool {
	ascii := true
	for _, b := range v {
		if b >= utf8.RuneSelf {
			ascii = false
			break
		}
	}
	if ascii {
		return false
	}
	d := jsontext.NewDecoder(bytes.NewReader(v))
	for {
		kind, name := d.PeekKind(), false
		if kind == '"' {
			// A string at an even count inside an object is a name.
			if k, n := d.StackIndex(d.StackDepth()); k == '{' && n%2 == 0 {
				name = true
			}
		}
		if !name {
			if _, err := d.ReadToken(); err != nil {
				return !errors.Is(err, io.EOF)
			}
			continue
		}
		raw, err := d.ReadValue()
		if err != nil {
			return true
		}
		for _, b := range raw {
			if b >= utf8.RuneSelf || b == '\\' {
				return true
			}
		}
	}
}
