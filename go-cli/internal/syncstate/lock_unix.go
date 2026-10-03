//go:build !windows

package syncstate

import (
	"errors"
	"os"
	"syscall"
)

// acquireLockFile takes an exclusive, non-blocking advisory lock on path via
// flock(2). The lock belongs to the open file description, so the kernel
// releases it when the process exits by any means — that is what makes a
// leftover lock file harmless rather than a permanent block.
func acquireLockFile(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE, 0600)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		_ = file.Close()
		if errors.Is(err, syscall.EWOULDBLOCK) || errors.Is(err, syscall.EAGAIN) {
			return nil, ErrLockHeld
		}
		return nil, err
	}
	return file, nil
}

// releaseLockFile drops the lock. Closing the descriptor releases the flock on
// its own; the explicit LOCK_UN keeps the intent obvious. The file itself is
// left in place on purpose — see the AcquireLock doc comment.
func releaseLockFile(file *os.File) {
	_ = syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
	_ = file.Close()
}
