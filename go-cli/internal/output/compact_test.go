package output

import (
	"bytes"
	"encoding/json"
	"reflect"
	"strings"
	"testing"
)

// decodeEncodeCompact is the compact form as renderJSON made it before
// canonicalCompact: decode into interface{}, encode without HTML escaping.
func decodeEncodeCompact(data []byte) ([]byte, bool) {
	var parsed interface{}
	if json.Unmarshal(data, &parsed) != nil {
		return nil, false
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if enc.Encode(parsed) != nil {
		return nil, false
	}
	return buf.Bytes(), true
}

var compactCorpus = []string{
	`{"data":[{"aff_campaign_id":1000000,"aff_campaign_name":"Offer 1 - Summer","aff_campaign_url":"https://n.example.com/o/1?sub1=[[subid]]&sub2=<c1>","aff_campaign_payout":1.26,"public_id":"c1"}],"pagination":{"total":1,"limit":1,"offset":0}}`,
	`{"data":[{"id":1,"clicks":1234,"payout":1.26,"roi":44.0,"epc":0.0123,"cpc":0.0085,"click_time":1790000000,"country":"US"}]}`,
	`{"data":[0,0,0,1,0.0,1e2,1E2,1.5e-7,1e21,1e20,123456789012345678,9007199254740993,-1.0e-6,5e-324,1.7976931348623157e308]}`,
	`{"data":[{"a":0},{"a":0}],"pagination":{"total":2}}`,
	`{"b":1,"a":2,"A":3,"é":4,"z":{"y":[],"x":{}}}`,
	`{"b":1,"a":2,"A":3,"z":{"y":[],"x":{}},"v":"é 😀 ｡"}`,
	`{"s":"\u003c\u003e\u0026 <>& \/ \" \\ \n\t\r\b\f \u0001 \u001f \u007f é 😀 \ud83d\ude00"}`,
	`{"s":"\u2028 \u2029"}`,
	"{\"s\":\"\xe2\x80\xa8\"}",
	`{"a":1,"a":2}`,
	"{\"s\":\"\xff\"}",
	`[1,2,3]`, `"x"`, `null`, `true`, `12.50`, `  {"a" : [ 1 , 2 ] }  `,
	`{"a":1}{"b":2}`, `{"a":`, ``, `nul`, `{"a":1,}`,
	`{"masked":true,"data":[{"total_clicks":null,"roi":12.5}]}`,
}

func TestCanonicalCompactMatchesDecodeEncode(t *testing.T) {
	agreed := 0
	for _, in := range compactCorpus {
		want, wantOK := decodeEncodeCompact([]byte(in))
		got, ok := canonicalCompact([]byte(in))
		if !ok {
			continue // renderJSON takes the old path
		}
		if !wantOK {
			t.Errorf("%q: canonical form accepted what encoding/json refuses", in)
			continue
		}
		if !bytes.Equal(got, want) {
			t.Errorf("%q:\n got %s\nwant %s", in, got, want)
			continue
		}
		agreed++
	}
	if agreed < 12 {
		t.Fatalf("only %d inputs took the canonical path; the corpus no longer tests it", agreed)
	}
}

func FuzzCanonicalCompactMatchesDecodeEncode(f *testing.F) {
	for _, in := range compactCorpus {
		f.Add([]byte(in))
	}
	f.Fuzz(func(t *testing.T, in []byte) {
		got, ok := canonicalCompact(in)
		if !ok {
			return
		}
		want, wantOK := decodeEncodeCompact(in)
		if !wantOK {
			t.Fatalf("%q: canonical form accepted what encoding/json refuses", in)
		}
		if !bytes.Equal(got, want) && !sameButNegativeZero(got, want) {
			t.Fatalf("%q:\n got %s\nwant %s", in, got, want)
		}
	})
}

// sameButNegativeZero: RFC 8785 writes -0 as 0 and encoding/json keeps -0,
// the one difference between the two forms (TestCanonicalCompactNegativeZero).
func sameButNegativeZero(got, want []byte) bool {
	if !bytes.Equal(got, bytes.ReplaceAll(want, []byte("-0"), []byte("0"))) {
		return false
	}
	var a, b interface{}
	return json.Unmarshal(got, &a) == nil && json.Unmarshal(want, &b) == nil && reflect.DeepEqual(a, b)
}

func TestCanonicalCompactNegativeZero(t *testing.T) {
	got, ok := canonicalCompact([]byte(`[-0,-0.0]`))
	want, _ := decodeEncodeCompact([]byte(`[-0,-0.0]`))
	if !ok || string(got) != "[0,0]\n" || string(want) != "[-0,-0]\n" {
		t.Fatalf("got %q (ok %v), old path %q", got, ok, want)
	}
}

func TestCanonicalCompactLeavesInputAlone(t *testing.T) {
	in := []byte(`{ "b" : 1 , "a" : 2 }`)
	keep := string(in)
	if _, ok := canonicalCompact(in); !ok {
		t.Fatal("not canonicalized")
	}
	if string(in) != keep || !strings.Contains(string(in), " ") {
		t.Fatalf("input changed to %q", in)
	}
}

// RFC 8785 sorts names by UTF-16 code units and encoding/json by UTF-8
// bytes; they disagree on {"｡":1,"😀":2}, so a non-ASCII name takes the old
// path and the output is the old path's.
func TestCanonicalCompactNonASCIINameTakesOldPath(t *testing.T) {
	for _, in := range []string{`{"｡":1,"😀":2}`, `{"｡":1,"😀":2}`, `{"a":[{"x":{"😀":1,"｡":2}}]}`} {
		if got, ok := canonicalCompact([]byte(in)); ok {
			want, _ := decodeEncodeCompact([]byte(in))
			t.Fatalf("%s took the canonical path: got %s, old path %s", in, got, want)
		}
		want, _ := decodeEncodeCompact([]byte(in))
		printed := captureStdout(t, func() { renderJSON([]byte(in), true) })
		if printed != string(want) {
			t.Fatalf("%s: printed %s, old path %s", in, printed, want)
		}
	}
	if _, ok := canonicalCompact([]byte(`{"name":"｡ 😀 é","b":"😀"}`)); !ok {
		t.Fatal("non-ASCII values with ASCII names left the canonical path")
	}
}
