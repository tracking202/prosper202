package cmd

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// The LTV commands offer their enum flags' values from lists kept here, and
// the server refuses any other value naming the parameter (LtvController::
// choices()): a value the CLI offers that the server lacks is a 422 the
// user was told would work, and one the server takes that the CLI lacks is
// refused before a request. GET /ltv/customers' sort and dir used to fall
// back to a default on the server, so a drift there was silent. These are
// read from the PHP, so a value added on one side and not the other fails
// here, in either direction.
func TestLtvListsAreTheServers(t *testing.T) {
	ltv := readPHP(t, "202-config", "Ltv", "MysqlLtvRepository.php")
	subscriptions := readPHP(t, "202-config", "Ltv", "MysqlSubscriptionRepository.php")
	webhooks := readPHP(t, "202-config", "Ltv", "MysqlWebhookRepository.php")

	for _, c := range []struct {
		flag   string
		cli    []string
		server []string
	}{
		{"ltv customers --sort", ltvCustomerSorts, phpConstList(t, ltv, "CUSTOMER_SORTS")},
		{"ltv customers --dir", sortDirections, phpConstList(t, ltv, "SORT_DIRECTIONS")},
		{"ltv customers --segment", ltvCustomerSegments, phpConstKeys(t, ltv, "CUSTOMER_SEGMENTS")},
		// breakdowns(): the acquisition dimensions, and product.
		{"ltv breakdown/predict --by", ltvDimensions, append(phpConstKeys(t, ltv, "ACQUISITION_BREAKDOWNS"), "product")},
		{"ltv subscriptions --status", ltvSubscriptionStatuses, phpConstList(t, subscriptions, "STATUSES")},
		{"ltv webhooks deliveries --status", ltvDeliveryStatuses, phpConstList(t, webhooks, "DELIVERY_STATUSES")},
	} {
		if strings.Join(c.cli, ",") != strings.Join(c.server, ",") {
			t.Errorf("%s offers [%s]; the server takes [%s]", c.flag, strings.Join(c.cli, " "), strings.Join(c.server, " "))
		}
	}
}

func readPHP(t *testing.T, parts ...string) string {
	t.Helper()
	src, err := os.ReadFile(filepath.Join(append([]string{"..", ".."}, parts...)...))
	if err != nil {
		t.Fatal(err)
	}
	return string(src)
}

// phpConstList reads a `const NAME = ['a', 'b', ...];` list of strings, on
// one line or several. A list it cannot find, or one with fewer than two
// values, fails the test: the scan is blind, not the list short.
func phpConstList(t *testing.T, php, name string) []string {
	t.Helper()
	m := regexp.MustCompile(`(?s)const ` + name + ` = \[(.*?)\];`).FindStringSubmatch(php)
	if m == nil {
		t.Fatalf("no const %s this test can read", name)
	}
	values := phpStringList(m[1])
	if len(values) < 2 {
		t.Fatalf("read %d values from const %s: the scan is blind", len(values), name)
	}
	return values
}

// phpConstKeys reads the keys of a `const NAME = [ 'key' => ..., ];` map
// whose entries start on their own line at one indentation step in.
func phpConstKeys(t *testing.T, php, name string) []string {
	t.Helper()
	m := regexp.MustCompile(`(?s)const ` + name + ` = \[\n(.*?)\n    \];`).FindStringSubmatch(php)
	if m == nil {
		t.Fatalf("no const %s this test can read", name)
	}
	var keys []string
	for _, k := range regexp.MustCompile(`(?m)^        '(\w+)' =>`).FindAllStringSubmatch(m[1], -1) {
		keys = append(keys, k[1])
	}
	if len(keys) < 2 {
		t.Fatalf("read %d keys from const %s: the scan is blind", len(keys), name)
	}
	return keys
}
