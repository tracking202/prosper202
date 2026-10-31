//go:build windows

package eval

import "os/exec"

// Windows has no process groups in the POSIX sense here; the context's
// default kill plus WaitDelay is the available behavior.
func setProcessGroup(cmd *exec.Cmd) {}

func killProcessGroup(cmd *exec.Cmd) error {
	if cmd.Process == nil {
		return nil
	}
	return cmd.Process.Kill()
}
