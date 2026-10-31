package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"strconv"
)

// errTrailingJSON is decodeOneJSON's answer for input with something after
// its first JSON value. A json.Decoder stops after the first value, so
// without this check `{"events":[…]}{"events":[…]}` (two files
// concatenated, or a stray object pasted after the first) would send the
// first and silently drop the rest (CLAUDE.md #4).
var errTrailingJSON = errors.New("there is more after the first JSON value, which would be ignored")

// jsonDuplicateKey names the first key given twice in one object anywhere
// in data, by its place under prefix ("rules.0.rule_name"), or "" when none
// is. A decoder keeps the last of the two without a word, so what was sent
// and shown would not be what is written (CLAUDE.md #4). data is decoded
// elsewhere: an error here is only a value that is not JSON.
func jsonDuplicateKey(data []byte, prefix string) (string, error) {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()
	return jsonDuplicateKeyIn(dec, prefix)
}

func jsonDuplicateKeyIn(dec *json.Decoder, at string) (string, error) {
	tok, err := dec.Token()
	if err != nil {
		return "", err
	}
	delim, ok := tok.(json.Delim)
	if !ok {
		return "", nil
	}
	place := func(key string) string {
		if at == "" {
			return key
		}
		return at + "." + key
	}
	switch delim {
	case '{':
		seen := map[string]bool{}
		for dec.More() {
			keyTok, err := dec.Token()
			if err != nil {
				return "", err
			}
			key, _ := keyTok.(string)
			if seen[key] {
				return place(key), nil
			}
			seen[key] = true
			if dup, err := jsonDuplicateKeyIn(dec, place(key)); err != nil || dup != "" {
				return dup, err
			}
		}
	case '[':
		for i := 0; dec.More(); i++ {
			if dup, err := jsonDuplicateKeyIn(dec, place(strconv.Itoa(i))); err != nil || dup != "" {
				return dup, err
			}
		}
	}
	_, err = dec.Token()
	return "", err
}

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
