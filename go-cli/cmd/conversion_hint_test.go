package cmd

import (
	"fmt"
	"strings"
	"testing"

	"p202/internal/api"
)

// A conversion whose line items or CRM fields find no customer is refused
// with a 422 naming the field; the hint names the flags that supply one. The
// same fields are refused for their shape too, where a customer would not
// help, so only the no-customer sentence gets the hint.
func TestConversionCreateHintNamesTheCustomerFlagsOnlyForTheNoCustomerRefusal(t *testing.T) {
	noCustomer := "line items are stored on the customer's revenue event, and no customer is linked to this conversion (the click has none, and none was named): send customer_ref or customer_id with it. Nothing was recorded."
	cases := []struct {
		name     string
		err      error
		wantHint bool
	}{
		{"items without a customer", &api.APIError{Status: 422, FieldErrors: map[string]string{"items": noCustomer}}, true},
		{"customer_crm without a customer", &api.APIError{Status: 422, FieldErrors: map[string]string{"customer_crm": noCustomer}}, true},
		{"wrapped", fmt.Errorf("posting: %w", &api.APIError{Status: 422, FieldErrors: map[string]string{"items": noCustomer}}), true},
		{"items that are not a list", &api.APIError{Status: 422, FieldErrors: map[string]string{"items": "must be a list of line items"}}, false},
		{"a line item's own field", &api.APIError{Status: 422, FieldErrors: map[string]string{"items.0.unit_price": "must be a number"}}, false},
		{"another field", &api.APIError{Status: 422, FieldErrors: map[string]string{"click_id": "Required"}}, false},
		{"a server error", &api.APIError{Status: 500, FieldErrors: map[string]string{"items": noCustomer}}, false},
	}
	for _, tc := range cases {
		got := hintConversionCreateError(tc.err)
		hint := hintFor(got)
		named := strings.Contains(hint, "--customer-ref") && strings.Contains(hint, "--customer-id")
		if named != tc.wantHint {
			t.Errorf("%s: hint %q, want the customer flags named: %v", tc.name, hint, tc.wantHint)
		}
		if exitCodeForError(got) != exitCodeForError(tc.err) {
			t.Errorf("%s: the hint changed the exit code (%d, was %d)", tc.name, exitCodeForError(got), exitCodeForError(tc.err))
		}
	}
}
