package syncdata

import "testing"

func TestNewIDMappingReturnsEmpty(t *testing.T) {
	m := NewIDMapping()
	if m == nil {
		t.Fatal("NewIDMapping() returned nil")
	}
	all := m.All()
	if len(all) != 0 {
		t.Fatalf("new mapping should be empty, got %d entities", len(all))
	}
}

func TestSetAndGet(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "20")

	got, ok := m.Get("campaigns", "10")
	if !ok {
		t.Fatal("Get() returned false for known key")
	}
	if got != "20" {
		t.Fatalf("Get() = %q, want %q", got, "20")
	}
}

func TestSetEmptySourceIDIsNoop(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "", "20")

	all := m.All()
	if len(all) != 0 {
		t.Fatal("Set with empty sourceID should be a no-op")
	}
}

func TestSetEmptyTargetIDIsNoop(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "")

	all := m.All()
	if len(all) != 0 {
		t.Fatal("Set with empty targetID should be a no-op")
	}
}

func TestGetUnknownEntity(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "20")

	got, ok := m.Get("offers", "10")
	if ok {
		t.Fatalf("Get() should return false for unknown entity, got %q", got)
	}
	if got != "" {
		t.Fatalf("Get() should return empty string for unknown entity, got %q", got)
	}
}

func TestGetUnknownSourceID(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "20")

	got, ok := m.Get("campaigns", "999")
	if ok {
		t.Fatalf("Get() should return false for unknown sourceID, got %q", got)
	}
	if got != "" {
		t.Fatalf("Get() should return empty string for unknown sourceID, got %q", got)
	}
}

func TestAllReturnsDeepCopy(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "20")

	all := m.All()
	// Mutate the returned map.
	all["campaigns"]["10"] = "MUTATED"
	delete(all, "campaigns")

	// Original should be unaffected.
	got, ok := m.Get("campaigns", "10")
	if !ok || got != "20" {
		t.Fatalf("All() did not return a deep copy: Get() = (%q, %v)", got, ok)
	}
}

func TestMultipleEntitiesStoredIndependently(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "1", "100")
	m.Set("offers", "1", "200")
	m.Set("campaigns", "2", "101")

	gotC1, ok := m.Get("campaigns", "1")
	if !ok || gotC1 != "100" {
		t.Fatalf("campaigns/1 = (%q, %v), want (100, true)", gotC1, ok)
	}
	gotC2, ok := m.Get("campaigns", "2")
	if !ok || gotC2 != "101" {
		t.Fatalf("campaigns/2 = (%q, %v), want (101, true)", gotC2, ok)
	}
	gotO1, ok := m.Get("offers", "1")
	if !ok || gotO1 != "200" {
		t.Fatalf("offers/1 = (%q, %v), want (200, true)", gotO1, ok)
	}

	all := m.All()
	if len(all) != 2 {
		t.Fatalf("All() should have 2 entities, got %d", len(all))
	}
	if len(all["campaigns"]) != 2 {
		t.Fatalf("campaigns should have 2 mappings, got %d", len(all["campaigns"]))
	}
	if len(all["offers"]) != 1 {
		t.Fatalf("offers should have 1 mapping, got %d", len(all["offers"]))
	}
}

func TestSetOverwritesPreviousValue(t *testing.T) {
	m := NewIDMapping()
	m.Set("campaigns", "10", "20")
	m.Set("campaigns", "10", "30")

	got, ok := m.Get("campaigns", "10")
	if !ok || got != "30" {
		t.Fatalf("Set should overwrite: Get() = (%q, %v), want (30, true)", got, ok)
	}
}
