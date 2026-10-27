package api

import (
	"errors"
	"fmt"
	"io"
	"sync"
)

// OpAnswerTooManyValues marks a call refused because the install's answers
// held more JSON values than LimitAnswerValues allows. The answer that passed
// the bound was not decoded.
const OpAnswerTooManyValues = "answer_too_many_values"

// answerBound is LimitAnswerValues' bound and what the answers read since it
// was set have used of it. Decoding JSON into interface{} costs memory per
// value, not per byte, so a read budget in bytes does not bound what a call
// holds: 1 MB of {"a":0} is 260,000 values. The count runs over every answer
// the process reads, because a command that reads every page keeps each
// page's rows.
var answerBound struct {
	mu    sync.Mutex
	limit int  // 0: no bound
	seen  int  // values in the answers read so far
	over  bool // an answer passed the bound
}

// LimitAnswerValues bounds the JSON values in all the answers read from now
// on, counting from zero; 0 removes the bound. The hosted MCP engine sets it
// for each call (cmd's hostedAnswerValues); nothing else does.
func LimitAnswerValues(n int) {
	answerBound.mu.Lock()
	defer answerBound.mu.Unlock()
	answerBound.limit, answerBound.seen, answerBound.over = n, 0, false
}

// AnswerTooManyValues reports whether err holds, anywhere in its chain, the
// refusal of an answer past LimitAnswerValues' bound: a command may wrap it
// in an error of its own (requireFeature does, as a network error).
func AnswerTooManyValues(err error) bool {
	for err != nil {
		var reqErr *RequestError
		if !errors.As(err, &reqErr) {
			return false
		}
		if reqErr.Op == OpAnswerTooManyValues {
			return true
		}
		err = reqErr.Err
	}
	return false
}

// readAnswer reads an answer's body, at most limit bytes, and counts its
// values toward the bound before anything can decode it. It is the only
// reader of an answer's body in this package
// (TestAnswerBodiesAreReadOnlyByReadAnswer). When the answers pass the bound,
// tooMany is the refusal and body is nil, so there is nothing to decode;
// after that, every later answer that holds a value is refused too.
func readAnswer(r io.Reader, limit int64) (body []byte, tooMany *RequestError, err error) {
	body, err = io.ReadAll(io.LimitReader(r, limit))
	if err != nil {
		return nil, nil, err
	}
	if tooMany := countAnswer(body); tooMany != nil {
		return nil, tooMany, nil
	}
	return body, nil, nil
}

func countAnswer(body []byte) *RequestError {
	answerBound.mu.Lock()
	defer answerBound.mu.Unlock()
	if answerBound.limit <= 0 {
		return nil
	}
	room := answerBound.limit - answerBound.seen
	if answerBound.over {
		room = 0
	}
	n, over := JSONValuesOver(body, room)
	if over {
		answerBound.over = true
		return &RequestError{Kind: "validation", Op: OpAnswerTooManyValues, Err: fmt.Errorf(
			"the install's answers to this call hold more than %d JSON values, more than a hosted call decodes", answerBound.limit)}
	}
	answerBound.seen += n
	return nil
}

// JSONValuesOver counts the JSON values in b, each object, array, string,
// number, true, false and null (an object's member names are not values),
// and stops once the count passes max, answering (count so far, true).
//
// It does not stop at a syntax error. A malformed body (one the hosted
// relay's read budget cut short, or one that is not JSON at all) is read to
// its end, and a token that can begin a value counts wherever it stands: a
// number ends where the JSON grammar ends it (00 is two), a literal counts
// from its first letter (truefalse is two). Up to the first syntax error the
// count is exactly what a decoder builds, and after it only grows, so it is
// never less than what Go's JSON decoders build from b before they stop
// (FuzzJSONValuesOverBoundsEveryDecoder).
func JSONValuesOver(b []byte, max int) (int, bool) {
	n := 0
	var open containerStack
	// prev is the last token: '{', '[', '}', ']', ',', ':', 'k' (a member
	// name), 'v' (any other value) or 'x' (a byte that begins no token).
	prev := byte(0)
	for i := 0; i < len(b); {
		c := b[i]
		switch {
		case c == ' ' || c == '\t' || c == '\n' || c == '\r':
			i++
			continue
		case c == '"':
			end := stringEnd(b, i)
			if open.inObject() && (prev == '{' || prev == ',') && colonAt(b, end) {
				prev = 'k'
			} else {
				n++
				prev = 'v'
			}
			i = end
		case c == '{' || c == '[':
			n++
			open.push(c == '{')
			prev = c
			i++
		case c == '}' || c == ']':
			open.pop()
			prev = c
			i++
		case c == ',' || c == ':':
			prev = c
			i++
		case c == '-' || (c >= '0' && c <= '9'):
			n++
			prev = 'v'
			i = numberEnd(b, i)
		case c == 't' || c == 'f' || c == 'n':
			n++
			prev = 'v'
			i += literalLen(b[i:])
		default:
			prev = 'x'
			i++
		}
		if n > max {
			return n, true
		}
	}
	return n, false
}

// stringEnd is the index just past the string that opens at b[i], or len(b)
// when it is not closed.
func stringEnd(b []byte, i int) int {
	for j := i + 1; j < len(b); j++ {
		switch b[j] {
		case '\\':
			j++
		case '"':
			return j + 1
		}
	}
	return len(b)
}

// colonAt reports whether the next byte at or after i, past whitespace, is ':'.
func colonAt(b []byte, i int) bool {
	for ; i < len(b); i++ {
		switch b[i] {
		case ' ', '\t', '\n', '\r':
			continue
		case ':':
			return true
		}
		return false
	}
	return false
}

func isDigit(c byte) bool { return c >= '0' && c <= '9' }

// numberEnd is the index just past the number that begins at b[i], as JSON's
// grammar reads one: -?(0|[1-9][0-9]*)(.[0-9]+)?([eE][+-]?[0-9]+)?. It moves
// at least one byte.
func numberEnd(b []byte, i int) int {
	j := i
	if b[j] == '-' {
		j++
	}
	if j < len(b) && b[j] == '0' {
		j++
	} else {
		for j < len(b) && isDigit(b[j]) {
			j++
		}
	}
	if j+1 < len(b) && b[j] == '.' && isDigit(b[j+1]) {
		for j += 2; j < len(b) && isDigit(b[j]); j++ {
		}
	}
	if j < len(b) && (b[j] == 'e' || b[j] == 'E') {
		k := j + 1
		if k < len(b) && (b[k] == '+' || b[k] == '-') {
			k++
		}
		if k < len(b) && isDigit(b[k]) {
			for j = k + 1; j < len(b) && isDigit(b[j]); j++ {
			}
		}
	}
	if j == i {
		j++
	}
	return j
}

// literalLen is how many bytes of b, which begins with t, f or n, match the
// start of true, false or null: at least one.
func literalLen(b []byte) int {
	word := "null"
	switch b[0] {
	case 't':
		word = "true"
	case 'f':
		word = "false"
	}
	j := 1
	for j < len(b) && j < len(word) && b[j] == word[j] {
		j++
	}
	return j
}

// containerStack says, for each object or array still open, which it is: a
// bit each, so even a body of nothing but [ holds an eighth of its length.
type containerStack struct {
	bits  []uint64
	depth int
}

func (s *containerStack) push(object bool) {
	if s.depth/64 == len(s.bits) {
		s.bits = append(s.bits, 0)
	}
	mask := uint64(1) << (s.depth % 64)
	if object {
		s.bits[s.depth/64] |= mask
	} else {
		s.bits[s.depth/64] &^= mask
	}
	s.depth++
}

func (s *containerStack) pop() {
	if s.depth > 0 {
		s.depth--
	}
}

func (s *containerStack) inObject() bool {
	if s.depth == 0 {
		return false
	}
	d := s.depth - 1
	return s.bits[d/64]&(uint64(1)<<(d%64)) != 0
}
