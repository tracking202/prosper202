package shell

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestStateSetGet(t *testing.T) {
	s := NewState()
	s.Set("campaigns", json.RawMessage(`[{"id":1},{"id":2}]`))

	got, ok := s.Get("campaigns")
	if !ok {
		t.Fatal("expected variable to exist")
	}
	if string(got) != `[{"id":1},{"id":2}]` {
		t.Errorf("got %s, want [{'id':1},{'id':2}]", string(got))
	}
}

func TestStateGetMissing(t *testing.T) {
	s := NewState()
	_, ok := s.Get("nonexistent")
	if ok {
		t.Error("expected variable to not exist")
	}
}

func TestStateSetLast(t *testing.T) {
	s := NewState()
	s.SetLast(json.RawMessage(`{"status":"ok"}`))

	got, ok := s.Get("_")
	if !ok {
		t.Fatal("expected $_ to exist")
	}
	if string(got) != `{"status":"ok"}` {
		t.Errorf("got %s", string(got))
	}
}

func TestStateDelete(t *testing.T) {
	s := NewState()
	s.Set("x", json.RawMessage(`1`))

	if !s.Delete("x") {
		t.Error("expected delete to return true")
	}
	if s.Delete("x") {
		t.Error("expected second delete to return false")
	}
	if _, ok := s.Get("x"); ok {
		t.Error("expected variable to be gone")
	}
}

func TestStateNames(t *testing.T) {
	s := NewState()
	s.Set("b", json.RawMessage(`2`))
	s.Set("a", json.RawMessage(`1`))
	s.Set("c", json.RawMessage(`3`))

	names := s.Names()
	if len(names) != 3 {
		t.Fatalf("expected 3 names, got %d", len(names))
	}
	if names[0] != "a" || names[1] != "b" || names[2] != "c" {
		t.Errorf("expected sorted names, got %v", names)
	}
}

func TestStateCount(t *testing.T) {
	s := NewState()
	if s.Count() != 0 {
		t.Error("expected count 0")
	}
	s.Set("x", json.RawMessage(`1`))
	if s.Count() != 1 {
		t.Error("expected count 1")
	}
}

func TestStateFormatVarsList(t *testing.T) {
	s := NewState()
	result := s.FormatVarsList()
	if result != "No variables stored.\n" {
		t.Errorf("expected empty message, got %q", result)
	}

	s.Set("name", json.RawMessage(`"hello"`))
	result = s.FormatVarsList()
	if !strings.Contains(result, "$name") {
		t.Errorf("expected $name in output, got %q", result)
	}
	if !strings.Contains(result, `"hello"`) {
		t.Errorf("expected value in output, got %q", result)
	}
}

func TestStateFormatVarsListTruncatesLong(t *testing.T) {
	s := NewState()
	long := strings.Repeat("x", 200)
	s.Set("big", json.RawMessage(`"`+long+`"`))
	result := s.FormatVarsList()
	if !strings.Contains(result, "...") {
		t.Error("expected truncation indicator")
	}
}
