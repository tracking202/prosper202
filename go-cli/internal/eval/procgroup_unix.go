//go:build !windows

package eval

import (
	"os/exec"
	"syscall"
)

// setProcessGroup puts the command in a new process group so the runner can
// signal the command and everything it spawns as a unit.
func setProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

// killProcessGroup SIGKILLs the whole group. Negating the pid addresses the
// group, which is what reaches grandchildren still holding the output pipe.
func killProcessGroup(cmd *exec.Cmd) error {
	if cmd.Process == nil {
		return nil
	}
	if err := syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL); err != nil {
		return cmd.Process.Kill()
	}
	return nil
}
