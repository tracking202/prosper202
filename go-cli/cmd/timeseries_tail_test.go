package cmd

import (
	"bytes"
	"encoding/json"
	"encoding/json/jsontext"
	"reflect"
	"strings"
	"testing"
)

// dataMembers counts the top-level members named data in any case.
func dataMembers(in []byte) int {
	dec := jsontext.NewDecoder(bytes.NewReader(in), jsontext.AllowInvalidUTF8(true), jsontext.AllowDuplicateNames(true))
	if tok, err := dec.ReadToken(); err != nil || tok.Kind() != '{' {
		return 0
	}
	n := 0
	for dec.PeekKind() == '"' {
		name, err := dec.ReadToken()
		if err != nil {
			return n
		}
		if strings.EqualFold(name.String(), "data") {
			n++
		}
		if dec.SkipValue() != nil {
			return n
		}
	}
	return n
}

// timeseriesTailByDecode is the decode timeseriesTailOf replaces.
func timeseriesTailByDecode(data []byte) (timeseriesTail, bool) {
	var resp struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
	}
	if json.Unmarshal(data, &resp) != nil {
		return timeseriesTail{}, false
	}
	t := timeseriesTail{Buckets: len(resp.Data), Truncated: resp.Truncated, Limit: resp.Limit}
	if len(resp.Data) > 0 {
		t.Last = resp.Data[len(resp.Data)-1]
	}
	return t, true
}

var timeseriesTailCorpus = []string{
	`{"data":[{"period":"2026-10-01","total_clicks":1},{"period":"2026-10-02","total_clicks":2}],"truncated":true,"limit":2000}`,
	`{"data":[{"period":"a"}],"truncated":false}`,
	`{"Data":[{"period":"a"},null],"LIMIT":5}`,
	`{"data":null,"truncated":null,"limit":null}`,
	`{"data":[]}`, `null`, `{}`, `[]`, `1`, `{"data":[0,0]}`, `{"data":[{"a":0},"x"]}`,
	`{"data":[{"period":1}],"limit":1.5}`, `{"data":{"period":"a"}}`, `{"data":[{"period":"a"}],"truncated":"yes"}`,
	`{"data":[{"period":"a"}]} x`, `{"data":[{"period":"a"}`, `{"x":[1,{"y":2}],"data":[{"period":"é\xff"}]}`,
	`{"data":[{"period":"a","period":"b"}]}`, `{"data":[{"period":"a"}],"limit":1e3}`, `{"data":[{}],"limit":-4}`,
}

func sameTail(a, b timeseriesTail) bool {
	return a.Buckets == b.Buckets && reflect.DeepEqual(a.Last, b.Last) && reflect.DeepEqual(a.Truncated, b.Truncated) && reflect.DeepEqual(a.Limit, b.Limit)
}

func TestTimeseriesTailMatchesDecode(t *testing.T) {
	ok := 0
	for _, in := range timeseriesTailCorpus {
		want, wantOK := timeseriesTailByDecode([]byte(in))
		got, gotOK := timeseriesTailOf([]byte(in))
		if gotOK != wantOK || (gotOK && !sameTail(got, want)) {
			t.Errorf("%s: got %+v (%v), decode %+v (%v)", in, got, gotOK, want, wantOK)
		}
		if gotOK {
			ok++
		}
	}
	if ok < 8 {
		t.Fatalf("only %d inputs decoded; the corpus no longer tests the reading", ok)
	}
}

func FuzzTimeseriesTailMatchesDecode(f *testing.F) {
	for _, in := range timeseriesTailCorpus {
		f.Add([]byte(in))
	}
	f.Fuzz(func(t *testing.T, in []byte) {
		want, wantOK := timeseriesTailByDecode(in)
		got, gotOK := timeseriesTailOf(in)
		if !gotOK && wantOK && dataMembers(in) > 1 {
			return // refused on purpose: see timeseriesTailOf
		}
		if gotOK != wantOK || (gotOK && !sameTail(got, want)) {
			t.Fatalf("%q: got %+v (%v), decode %+v (%v)", in, got, gotOK, want, wantOK)
		}
	})
}
