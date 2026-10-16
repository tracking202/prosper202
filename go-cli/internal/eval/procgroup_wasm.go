//go:build js || wasip1

package eval

import "os/exec"

// A hosted (WebAssembly) run cannot start processes; eval never runs there.
func setProcessGroup(cmd *exec.Cmd) {}

func killProcessGroup(cmd *exec.Cmd) error { return nil }
