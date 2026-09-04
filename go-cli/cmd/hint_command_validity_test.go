package cmd

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// backtickedP202Command matches a `p202 ...` invocation inside a backtick-quoted
// span in source text, which is how every recovery hint names a command.
var backtickedP202Command = regexp.MustCompile("`(p202 [^`]+)`")

// A hint that names a command which does not exist is worse than no hint: the
// shipped example was `p202 config get`, which cobra answers by printing the
// `config` help and exiting 0, so a scripted agent following the recovery step
// gets no configuration and reads the diagnostic as having succeeded.
//
// This walks the real command tree rather than a list, so a renamed or removed
// subcommand fails here instead of rotting in a hint string. Structural, per
// CLAUDE.md's "closing the loop" rule: the fix for one bad hint should catch
// the next one too.
func TestEveryCommandNamedInAHintExists(t *testing.T) {
	roots := []string{".", "../internal/api"}
	checked := 0

	for _, root := range roots {
		err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
			if err != nil || info.IsDir() || !strings.HasSuffix(path, ".go") {
				return err
			}
			if strings.HasSuffix(path, "_test.go") {
				return nil
			}
			src, readErr := os.ReadFile(path)
			if readErr != nil {
				return readErr
			}
			for _, m := range backtickedP202Command.FindAllStringSubmatch(string(src), -1) {
				invocation := m[1]
				words := strings.Fields(invocation)[1:] // drop "p202"
				// Keep only the leading subcommand path; stop at the first flag
				// or placeholder, which are arguments rather than command names.
				var pathWords []string
				for _, w := range words {
					if strings.HasPrefix(w, "-") || strings.HasPrefix(w, "<") || strings.HasPrefix(w, "[") {
						break
					}
					pathWords = append(pathWords, w)
				}
				if len(pathWords) == 0 {
					continue
				}
				checked++
				cmd, _, findErr := rootCmd.Find(pathWords)
				if findErr != nil || cmd == nil {
					t.Errorf("%s: hint names `%s`, but %q is not a command", path, invocation, strings.Join(pathWords, " "))
					continue
				}
				// Find falls back to the nearest parent, so a shorter resolution
				// means the trailing words were not subcommands. They are
				// acceptable only as arguments, which requires the resolved
				// command to actually run: a pure command group (config, user,
				// rotator) takes no arguments, so `p202 config get` resolves to
				// `p202 config`, prints help and exits 0 -- the failure this
				// test exists to catch.
				if got := strings.Fields(cmd.CommandPath())[1:]; len(got) != len(pathWords) && !cmd.Runnable() {
					t.Errorf("%s: hint names `%s`, but %q is a command group, so %q is not a subcommand of it",
						path, invocation, cmd.CommandPath(), pathWords[len(got)])
				}
			}
			return nil
		})
		if err != nil {
			t.Fatalf("walking %s: %v", root, err)
		}
	}

	if checked == 0 {
		t.Fatal("found no `p202 ...` hints to check - the matcher is wrong, not the tree")
	}
	t.Logf("checked %d commands named in hints", checked)
}
