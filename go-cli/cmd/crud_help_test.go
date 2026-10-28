package cmd

import (
	"strings"
	"testing"
)

// A create's example names every flag the server requires, and every id
// flag says which list command its value comes from: the two things an
// agent otherwise learns from a 422.
func TestCRUDHelpShowsRequiredFlagsAndIDSources(t *testing.T) {
	for _, entity := range crudEntities {
		create, _, err := rootCmd.Find([]string{entity.Name, "create"})
		if err != nil || create == nil || create.Name() != "create" {
			t.Errorf("%s create not found", entity.Name)
			continue
		}
		for _, f := range entity.Fields {
			if f.Required && !strings.Contains(create.Example, "--"+strings.ReplaceAll(f.Name, "_", "-")+" ") {
				t.Errorf("%s create example lacks required --%s: %q", entity.Name, f.Name, create.Example)
			}
			src, isID := idSources[f.Name]
			if !isID {
				continue
			}
			flag := create.Flags().Lookup(f.Name)
			if flag == nil || !strings.Contains(flag.Usage, "p202 ") {
				t.Errorf("%s create --%s does not say where the id comes from (%s)", entity.Name, f.Name, src)
			}
		}
	}
}
