package api

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

// walkValues counts the values in a decoded tree: every object, array and
// scalar, not member names.
func walkValues(v interface{}) int {
	n := 1
	switch x := v.(type) {
	case map[string]interface{}:
		for _, e := range x {
			n += walkValues(e)
		}
	case []interface{}:
		for _, e := range x {
			n += walkValues(e)
		}
	}
	return n
}

// unmarshalValues is what json.Unmarshal builds from b: its tree's values,
// or none when it refuses b.
func unmarshalValues(b []byte) int {
	var v interface{}
	if json.Unmarshal(b, &v) != nil {
		return 0
	}
	return walkValues(v)
}

// decodeLoopValues is what a json.Decoder builds reading one value after
// another until it fails: it takes values with nothing between them (00 is
// two numbers, truefalse two literals).
func decodeLoopValues(b []byte) int {
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	n := 0
	for {
		var v interface{}
		if dec.Decode(&v) != nil {
			return n
		}
		n += walkValues(v)
	}
}

// tokenValues is what json.Decoder.Token builds before its first error: it
// hands back each value token as it reads it, so a malformed body costs
// every value ahead of the fault.
func tokenValues(b []byte) int {
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	type frame struct{ object, keyNext bool }
	var open []frame
	n := 0
	value := func() {
		n++
		if len(open) > 0 && open[len(open)-1].object {
			open[len(open)-1].keyNext = true
		}
	}
	for {
		tok, err := dec.Token()
		if err != nil {
			return n
		}
		switch t := tok.(type) {
		case json.Delim:
			switch t {
			case '{', '[':
				value()
				open = append(open, frame{object: t == '{', keyNext: t == '{'})
			default:
				if len(open) > 0 {
					open = open[:len(open)-1]
				}
			}
		case string:
			if len(open) > 0 && open[len(open)-1].object && open[len(open)-1].keyNext {
				open[len(open)-1].keyNext = false
				continue
			}
			value()
		default:
			value()
		}
	}
}

func countOf(b []byte) int {
	n, over := JSONValuesOver(b, len(b)+1)
	if over {
		panic("a body cannot hold more values than bytes")
	}
	return n
}

func TestJSONValuesOverCountsValues(t *testing.T) {
	for _, s := range []string{
		`{"data":[{"a":0},{"a":0}],"pagination":{"total":2,"limit":50,"offset":0}}`,
		`[0,-1.5e+3,1E-7,-0,0.25,123456789012345678901234567890,true,false,null]`,
		// Brackets, commas, colons and escaped quotes inside strings are text.
		`["a:b","[x]","{y}","c,d","\"q\":","\\",{"k\"}":"v,]"},"\\\"",""]`,
		`{"s":"é \\ \/ \" \u0041 \ud83d\ude00 \n\t","t":"]}{[","u":"\\\\"}`,
		// Member names are not values, whatever they hold.
		`{"[":1,"{":{"]":[]},":":":","\"":"\""}`,
		`{ "a" : "b" , "c" : [ 1 , 2 ] , "d\"" : { } , "e\\" : "f\\" }`,
		"{\r\n\t\"a\" :\n[\t]\r}",
		`[[[[[[[[[[{"deep":[[[]]]}]]]]]]]]]]`,
		`[{},[],{},[],{"a":{}},{"b":[]}]`,
		`"plain"`, `12`, `-3.5`, `true`, `null`, `{}`, `[]`, `""`,
		`{"a":1,"a":2}`,
	} {
		b := []byte(s)
		if !json.Valid(b) {
			t.Fatalf("%s is not JSON", s)
		}
		// Every value the document holds, a repeated member's included:
		// json.Unmarshal builds each before it replaces it.
		want := tokenValues(b)
		if got := unmarshalValues(b); got > want {
			t.Fatalf("%s: Unmarshal builds %d, the document holds %d", s, got, want)
		}
		if n, over := JSONValuesOver(b, want); over || n != want {
			t.Errorf("%s: counted %d (over %v), want %d", s, n, over, want)
		}
		if _, over := JSONValuesOver(b, want-1); !over {
			t.Errorf("%s: %d values are not over %d", s, want, want-1)
		}
	}
}

// A body the relay's read budget cut short, or one that is not JSON at all,
// is counted to its end: it never reads as a few values because it does not
// parse (CLAUDE.md #11).
func TestJSONValuesOverCountsMalformedBodies(t *testing.T) {
	tiny := `{"data":[` + strings.Repeat(`{"a":0},`, 20000) + `{"a":0}]}`
	for _, c := range []struct {
		name string
		body string
		min  int
	}{
		{"cut inside a member name", tiny[:len(tiny)/2] + `{"a`, 20000},
		{"cut after a colon", tiny[:len(tiny)/2] + `{"a":`, 20000},
		{"cut inside a number", tiny[:len(tiny)/2] + `{"a":12`, 20000},
		{"cut inside an escape", tiny[:len(tiny)/2] + `{"a":"\`, 20000},
		{"no closing brackets", strings.TrimSuffix(tiny, "]}"), 40000},
		{"a stray byte ahead", "x" + tiny, 40000},
		{"an HTML page ahead", "<html><body>502 Bad Gateway</body></html>" + tiny, 40000},
		{"no commas", "[" + strings.Repeat("0 ", 30000) + "]", 30000},
		{"values run together", strings.Repeat("0", 30000), 30000},
		{"literals run together", strings.Repeat("truefalsenull", 10000), 30000},
		{"concatenated documents", strings.Repeat(`{"a":0}`, 15000), 30000},
		{"newline-delimited", strings.Repeat("{\"a\":0}\n", 15000), 30000},
		{"brackets that never match", strings.Repeat("]}", 1000) + "[" + strings.Repeat("0,", 30000), 30000},
		{"strings where keys belong", "{" + strings.Repeat(`"a",`, 30000) + `"a"}`, 30000},
		{"member names with no values", "{" + strings.Repeat(`"a":,`, 10) + `"b":0}`, 2},
		{"a raw control byte in a string", "[\"a\x01b\"," + strings.Repeat("0,", 30000) + "0]", 30000},
		{"invalid UTF-8", "[\"\xff\xfe\"," + strings.Repeat("0,", 30000) + "0]", 30000},
	} {
		b := []byte(c.body)
		n := countOf(b)
		if n < c.min {
			t.Errorf("%s: counted %d, want at least %d", c.name, n, c.min)
		}
		for _, ref := range []struct {
			name string
			got  int
		}{{"Unmarshal", unmarshalValues(b)}, {"Decode loop", decodeLoopValues(b)}, {"Token", tokenValues(b)}} {
			if ref.got > n {
				t.Errorf("%s: %s builds %d values, counted %d", c.name, ref.name, ref.got, n)
			}
		}
		if _, over := JSONValuesOver(b, c.min-1); !over {
			t.Errorf("%s: not over %d", c.name, c.min-1)
		}
	}
}

// The count stops once it passes the bound, whatever follows.
func TestJSONValuesOverStopsPastTheBound(t *testing.T) {
	b := []byte("[" + strings.Repeat("0,", 1000) + "0]")
	n, over := JSONValuesOver(b, 10)
	if !over || n != 11 {
		t.Fatalf("got (%d, %v), want (11, true)", n, over)
	}
	if n, over := JSONValuesOver(nil, 0); over || n != 0 {
		t.Fatalf("an empty body: (%d, %v)", n, over)
	}
	if _, over := JSONValuesOver([]byte("0"), 0); !over {
		t.Fatal("one value is over a bound of 0")
	}
}

// For any bytes at all, the count is at least what each of Go's decoders
// builds from them before stopping, and for a JSON document it is exactly
// the values the document holds.
func FuzzJSONValuesOverBoundsEveryDecoder(f *testing.F) {
	for _, s := range []string{
		`{"a":[1,"x:",{"b":null}]}`, `[true,false,-0.5e-3,"\"",{}]`, `{"\\":"\\\\"}`,
		`truefalse`, `nulltrue`, `00`, `01`, `1-2`, `1.5.5`, `1e5e5`, `-`, `tru`,
		`"a""b"`, `[]{}`, `0"a"`, `[1 2]`, `{"a":1 "b":2}`, `["a":1]`, `{"a"}`,
		`{"a":"b":"c"}`, `{"a",:1}`, `{"a"::1}`, `[{"a":0},{"a":0}`, `{"data":[{"a":0}`,
		"[\"\x01\",0]", "[\"\xff\",0]", `"\`, `"\u12`, `{"a":0}{"a":0}`, "{\"a\":0}\n{\"a\":0}",
		`]]]]0`, `}{"a":0}`, `[[[[`, `{{{{"a":0`, `{"a":[}`, `{"a":{"b":{"c":[1,{"d":2}]}}}`,
		`["a":`, `"a":`, `{"a":0,"b"`, `[0,"x":`,
	} {
		f.Add([]byte(s))
	}
	f.Fuzz(func(t *testing.T, b []byte) {
		n := countOf(b)
		for _, ref := range []struct {
			name string
			got  int
		}{{"Unmarshal", unmarshalValues(b)}, {"Decode loop", decodeLoopValues(b)}, {"Token", tokenValues(b)}} {
			if ref.got > n {
				t.Fatalf("%q: %s builds %d values, counted %d", b, ref.name, ref.got, n)
			}
		}
		if json.Valid(b) {
			if want := tokenValues(b); n != want {
				t.Fatalf("%q: counted %d, the document holds %d", b, n, want)
			}
		}
		if n > 0 {
			if got, over := JSONValuesOver(b, n-1); !over || got != n {
				t.Fatalf("%q: %d values with a bound of %d: (%d, %v)", b, n, n-1, got, over)
			}
		}
	})
}

func TestAnswerBoundIsOffUntilSet(t *testing.T) {
	body := []byte("[" + strings.Repeat("0,", 999) + "0]")
	if err := countAnswer(body); err != nil {
		t.Fatalf("no bound set: %v", err)
	}
	LimitAnswerValues(1000)
	LimitAnswerValues(0)
	if err := countAnswer(body); err != nil {
		t.Fatalf("bound removed: %v", err)
	}
}

// The bound covers every answer of the call together; once an answer passes
// it, every later one that holds a value is refused, and setting the bound
// again starts the count from zero.
func TestAnswerBoundCountsEveryAnswerOfTheCall(t *testing.T) {
	t.Cleanup(func() { LimitAnswerValues(0) })
	page := []byte("[" + strings.Repeat("0,", 399) + "0]") // 401 values
	LimitAnswerValues(1000)
	for i := 1; i <= 2; i++ {
		if err := countAnswer(page); err != nil {
			t.Fatalf("answer %d (%d values in all): %v", i, 401*i, err)
		}
	}
	err := countAnswer(page)
	if err == nil {
		t.Fatal("1203 values in all passed a bound of 1000")
	}
	if err.Kind != "validation" || err.Op != OpAnswerTooManyValues || !strings.Contains(err.Error(), "more than 1000 JSON values") {
		t.Fatalf("refusal: %+v", err)
	}
	if !AnswerTooManyValues(err) || ErrorCategory(err) != "validation" || !strings.Contains(HintFor(err), "smaller limit") {
		t.Fatalf("refusal reads as %q, hint %q", ErrorCategory(err), HintFor(err))
	}
	if countAnswer([]byte("0")) == nil {
		t.Fatal("an answer after the refusal passed")
	}
	if err := countAnswer(nil); err != nil {
		t.Fatalf("an empty answer after the refusal: %v", err)
	}
	LimitAnswerValues(1000)
	if err := countAnswer(page); err != nil {
		t.Fatalf("a new bound kept the old count: %v", err)
	}
}

func TestAnswerTooManyValuesReadsTheWholeChain(t *testing.T) {
	refusal := &RequestError{Kind: "validation", Op: OpAnswerTooManyValues, Err: errString("x")}
	wrapped := &RequestError{Kind: "network", Op: "verify_staged_writes_support", Err: refusal}
	if !AnswerTooManyValues(wrapped) {
		t.Fatal("a refusal inside another request error was not found")
	}
	if !strings.Contains(HintFor(wrapped), "smaller limit") {
		t.Fatalf("hint %q", HintFor(wrapped))
	}
	if AnswerTooManyValues(&RequestError{Kind: "network", Op: "send_request", Err: errString("x")}) || AnswerTooManyValues(nil) {
		t.Fatal("another error read as the refusal")
	}
}

type errString string

func (e errString) Error() string { return string(e) }
