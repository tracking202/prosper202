//go:build go1.27

package cmd

import (
	"bytes"
	"encoding/json"
	"encoding/json/jsontext"
	"errors"
	"io"
	"strings"
)

// timeseriesTailOf reads the buckets one at a time, keeping the last: a map
// per bucket is what a long series costs (1 MB of tiny buckets was 136 MiB
// of wasm memory). It answers what decoding the answer into
// struct{Data []map[string]interface{}; Truncated *bool; Limit *int} would:
// ok is false wherever that decode fails, and for a second data member, which
// that decode merges into the first one's buckets.
func timeseriesTailOf(data []byte) (timeseriesTail, bool) {
	var t timeseriesTail
	sawData := false
	dec := jsontext.NewDecoder(bytes.NewReader(data), jsontext.AllowInvalidUTF8(true), jsontext.AllowDuplicateNames(true))
	tok, err := dec.ReadToken()
	if err != nil {
		return t, false
	}
	switch tok.Kind() {
	case 'n':
	case '{':
		for {
			k := dec.PeekKind()
			if k == '}' {
				break
			}
			if k != '"' {
				return t, false
			}
			name, err := dec.ReadToken()
			if err != nil {
				return t, false
			}
			key := name.String()
			switch {
			case strings.EqualFold(key, "data"):
				if sawData {
					return t, false
				}
				sawData = true
				if !readTimeseriesBuckets(dec, &t) {
					return t, false
				}
			case strings.EqualFold(key, "truncated"):
				v, err := dec.ReadValue()
				if err != nil || json.Unmarshal(v, &t.Truncated) != nil {
					return t, false
				}
			case strings.EqualFold(key, "limit"):
				v, err := dec.ReadValue()
				if err != nil || json.Unmarshal(v, &t.Limit) != nil {
					return t, false
				}
			default:
				if dec.SkipValue() != nil {
					return t, false
				}
			}
		}
		if _, err := dec.ReadToken(); err != nil {
			return t, false
		}
	default:
		return t, false
	}
	if _, err := dec.ReadToken(); !errors.Is(err, io.EOF) {
		return t, false
	}
	return t, true
}

func readTimeseriesBuckets(dec *jsontext.Decoder, t *timeseriesTail) bool {
	switch dec.PeekKind() {
	case 'n':
		if _, err := dec.ReadToken(); err != nil {
			return false
		}
		t.Buckets, t.Last = 0, nil
		return true
	case '[':
	default:
		return false
	}
	if _, err := dec.ReadToken(); err != nil {
		return false
	}
	n := 0
	var last []byte
	for {
		k := dec.PeekKind()
		if k == ']' {
			break
		}
		v, err := dec.ReadValue()
		if err != nil {
			return false
		}
		if k != '{' && k != 'n' {
			return false
		}
		last = append(last[:0], v...)
		n++
	}
	if _, err := dec.ReadToken(); err != nil {
		return false
	}
	t.Buckets, t.Last = n, nil
	if n > 0 {
		var m map[string]interface{}
		if json.Unmarshal(last, &m) != nil {
			return false
		}
		t.Last = m
	}
	return true
}
