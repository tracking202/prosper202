package api

import "sync"

// Answer is a request this process sent that the server answered with a
// success: the method, the path as the caller named it (no query string),
// the status and the size of the body.
type Answer struct {
	Method string
	Path   string
	Status int
	Bytes  int
}

// lastAnswer is the most recent success read. A command handed nothing to
// print names it, because an empty answer otherwise reads as no results.
var lastAnswer struct {
	mu  sync.Mutex
	set bool
	a   Answer
}

func noteAnswer(a Answer) {
	lastAnswer.mu.Lock()
	defer lastAnswer.mu.Unlock()
	lastAnswer.set, lastAnswer.a = true, a
}

// LastAnswer is the most recent success read since ForgetLastAnswer, and
// false when there is none.
func LastAnswer() (Answer, bool) {
	lastAnswer.mu.Lock()
	defer lastAnswer.mu.Unlock()
	return lastAnswer.a, lastAnswer.set
}

// ForgetLastAnswer starts a command's record afresh, so a command that sends
// nothing is not told about the one before it (the shell and the MCP server
// run many commands in one process).
func ForgetLastAnswer() {
	lastAnswer.mu.Lock()
	defer lastAnswer.mu.Unlock()
	lastAnswer.set, lastAnswer.a = false, Answer{}
}
