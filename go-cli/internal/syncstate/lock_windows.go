//go:build windows

package syncstate

import (
	"os"

	"golang.org/x/sys/windows"
)

// acquireLockFile takes an exclusive, non-blocking lock on path via LockFileEx,
// the Windows counterpart to flock(2). Windows releases the lock when the
// handle closes, including on abnormal termination, so a leftover lock file does
// not block later runs.
func acquireLockFile(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE, 0600)
	if err != nil {
		return nil, err
	}
	var overlapped windows.Overlapped
	err = windows.LockFileEx(
		windows.Handle(file.Fd()),
		windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY,
		0,
		1,
		0,
		&overlapped,
	)
	if err != nil {
		_ = file.Close()
		if err == windows.ERROR_LOCK_VIOLATION || err == windows.ERROR_IO_PENDING {
			return nil, ErrLockHeld
		}
		return nil, err
	}
	return file, nil
}

// releaseLockFile drops the lock. The file itself is left in place on purpose —
// see the AcquireLock doc comment.
func releaseLockFile(file *os.File) {
	var overlapped windows.Overlapped
	_ = windows.UnlockFileEx(windows.Handle(file.Fd()), 0, 1, 0, &overlapped)
	_ = file.Close()
}
