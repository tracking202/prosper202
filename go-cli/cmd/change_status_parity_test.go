package cmd

import (
	"os"
	"reflect"
	"regexp"
	"testing"
)

// The statuses `change list --status` accepts are the server's enum, in its
// order: a state the server adds is one the CLI would refuse to filter by.
func TestStagedChangeStatusesAreTheServers(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Support", "StagedChangeStatus.php"))
	if err != nil {
		t.Fatal(err)
	}
	var server []string
	for _, m := range regexp.MustCompile(`(?m)^\s*case \w+ = '([^']+)';`).FindAllStringSubmatch(string(src), -1) {
		server = append(server, m[1])
	}
	if len(server) < 5 {
		t.Fatalf("read %d states from the server's enum: the scan is blind, not the lists in step", len(server))
	}
	if !reflect.DeepEqual(server, stagedChangeStatuses) {
		t.Errorf("server states %v, CLI accepts %v", server, stagedChangeStatuses)
	}
}
