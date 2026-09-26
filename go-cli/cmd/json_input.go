package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
)

// errTrailingJSON is decodeOneJSON's answer for input with something after
// its first JSON value. A json.Decoder stops after the first value, so
// without this check `{"events":[…]}{"events":[…]}` (two files
// concatenated, or a stray object pasted after the first) would send the
// first and silently drop the rest (CLAUDE.md #4).
var errTrailingJSON = errors.New("there is more after the first JSON value, which would be ignored")

// decodeOneJSON decodes exactly one JSON value from data into v, with
// numbers kept as json.Number so they reach the server as written. Only
// whitespace may follow the value; anything else — a second value, or text
// that is not JSON — is errTrailingJSON.
func decodeOneJSON(data []byte, v interface{}) error {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()
	if err := dec.Decode(v); err != nil {
		return err
	}
	var extra json.RawMessage
	if err := dec.Decode(&extra); !errors.Is(err, io.EOF) {
		return errTrailingJSON
	}
	return nil
}
