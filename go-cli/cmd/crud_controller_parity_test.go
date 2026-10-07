package cmd

import (
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// controllerFieldsRaw are fields a controller declares readonly but reads
// from the raw request in its own create()/update(), so a flag for them is
// a write. Each needs the reason, because "readonly" otherwise means the
// API drops the value.
var controllerFieldsRaw = map[string]string{
	"campaigns.app_registration_id":  "CampaignsController::create()/update() read it raw (registrationLink) before the readonly field list drops it",
	"campaigns.attribution_model_id": "CampaignsController::create()/update() read it raw (attributionModelLink) before the readonly field list drops it",
}

type controllerField struct {
	required bool
	readonly bool
}

// The API drops a field it does not know without a word
// (Controller::validatePayload), so a CLI flag that names a field the
// controller lacks reports success and saves nothing. Five did:
// campaign --aff_campaign_cpc, --aff_campaign_postback_url,
// --aff_campaign_postback_append and aff-network --aff_network_postback_url,
// --aff_network_postback_append — columns that exist nowhere in the schema.
// This reads each CRUD entity's controller and holds every flag to a field
// it writes, and Required to what it requires.
func TestCRUDFlagsMatchTheirControllers(t *testing.T) {
	root := filepath.Join("..", "..")
	classes := crudControllerClasses(t, root)
	for _, entity := range crudEntities {
		class, ok := classes[entity.Endpoint]
		if !ok {
			t.Errorf("%s: endpoint %q is not in api/v3/index.php's $crudMap", entity.Name, entity.Endpoint)
			continue
		}
		fields := controllerFields(t, filepath.Join(root, "api", "v3", "Controllers", class+".php"))
		var required []string
		for name, f := range fields {
			if f.required {
				required = append(required, name)
			}
		}
		var cliRequired []string
		for _, f := range entity.Fields {
			def, ok := fields[f.Name]
			if !ok {
				t.Errorf("%s --%s: %s has no such field, so the API drops the value and answers success", entity.Name, f.Name, class)
				continue
			}
			if def.readonly && controllerFieldsRaw[entity.Endpoint+"."+f.Name] == "" {
				t.Errorf("%s --%s: %s declares it readonly, so the API drops the value", entity.Name, f.Name, class)
			}
			if f.Required {
				cliRequired = append(cliRequired, f.Name)
			}
		}
		sort.Strings(required)
		sort.Strings(cliRequired)
		if strings.Join(required, ",") != strings.Join(cliRequired, ",") {
			t.Errorf("%s: CLI requires [%s] on create, %s requires [%s]", entity.Name,
				strings.Join(cliRequired, " "), class, strings.Join(required, " "))
		}
	}
}

// crudControllerClasses reads api/v3/index.php's $crudMap: endpoint → class.
func crudControllerClasses(t *testing.T, root string) map[string]string {
	t.Helper()
	src, err := os.ReadFile(filepath.Join(root, "api", "v3", "index.php"))
	if err != nil {
		t.Fatal(err)
	}
	block := regexp.MustCompile(`(?s)\$crudMap = \[(.*?)\];`).FindSubmatch(src)
	if block == nil {
		t.Fatal("api/v3/index.php has no `$crudMap = [...]`: the scan is blind, not the map empty")
	}
	out := map[string]string{}
	for _, m := range regexp.MustCompile(`'([a-z-]+)'\s*=>\s*\\Api\\V3\\Controllers\\(\w+)::class`).FindAllSubmatch(block[1], -1) {
		out[string(m[1])] = string(m[2])
	}
	if len(out) < len(crudEntities) {
		t.Fatalf("read %d entries from $crudMap, fewer than the %d CLI entities", len(out), len(crudEntities))
	}
	return out
}

// controllerFields reads the `return [...]` of a controller's fields():
// one `'name' => [ ... ],` entry per line, the shape every CRUD controller
// uses. A body it cannot read that way fails the test rather than reading
// as "no fields".
func controllerFields(t *testing.T, path string) map[string]controllerField {
	t.Helper()
	src, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	body := regexp.MustCompile(`(?s)protected function fields\(\): array\s*\{\s*return \[(.*?)\n\s*\];`).FindSubmatch(src)
	if body == nil {
		t.Fatalf("%s: no `fields(): array { return [...]; }` this test can read", path)
	}
	entry := regexp.MustCompile(`^\s*'(\w+)'\s*=>\s*\[(.*)\],?\s*$`)
	out := map[string]controllerField{}
	for _, line := range strings.Split(string(body[1]), "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "//") {
			continue
		}
		m := entry.FindStringSubmatch(line)
		if m == nil {
			t.Fatalf("%s: cannot read this fields() line, so the field list would be incomplete: %q", path, trimmed)
		}
		out[m[1]] = controllerField{
			required: strings.Contains(m[2], `'required' => true`),
			readonly: strings.Contains(m[2], `'readonly' => true`),
		}
	}
	if len(out) == 0 {
		t.Fatalf("%s: read no fields", path)
	}
	return out
}

// `update --<clearable> ""` sends the empty value (the setup page's
// emptied box); any other field refuses it before a request.
func TestCRUDUpdateClearsOnlyClearableFields(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{}}`)
	if _, _, err := executeCommand("campaign", "update", "5", "--aff_campaign_url_2", ""); err != nil {
		t.Fatalf("clearing url_2: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "PUT" {
		t.Fatalf("requests = %+v, want one PUT", *seen)
	}
	if v, ok := (*seen)[0].Body["aff_campaign_url_2"]; !ok || v != "" {
		t.Errorf("body = %v, want aff_campaign_url_2 sent as \"\"", (*seen)[0].Body)
	}

	_, seen = goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("campaign", "update", "5", "--aff_campaign_name", "")
	if err == nil || exitCodeForError(err) != 1 {
		t.Fatalf("err = %v, want a validation error for an empty name", err)
	}
	if len(*seen) != 0 {
		t.Errorf("requests = %+v, want none", *seen)
	}
}

// A missing required field is named before the CLI needs a server at all:
// no config here, and the error is about the flag, not the URL.
func TestCRUDCreateNamesAMissingRequiredFlagBeforeConfig(t *testing.T) {
	setTestHome(t, t.TempDir())
	_, _, err := executeCommand("campaign", "create", "--aff_campaign_name", "X", "--aff_campaign_url", "https://x.example")
	if err == nil || !strings.Contains(err.Error(), "--aff_campaign_payout") {
		t.Fatalf("err = %v, want the missing --aff_campaign_payout named", err)
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 campaign create --help") {
		t.Errorf("hint = %q", hint)
	}
}
