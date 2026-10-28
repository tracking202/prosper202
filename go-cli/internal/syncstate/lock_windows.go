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
	overlapped := lockRegion()
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
	overlapped := lockRegion()
	_ = windows.UnlockFileEx(windows.Handle(file.Fd()), 0, 1, 0, &overlapped)
	_ = file.Close()
}

// lockRegion is the byte range LockFileEx locks: one byte at a very high
// offset, past any content the file will ever hold.
//
// It deliberately does NOT cover byte 0. Windows byte-range locks are
// mandatory, not advisory, so locking the start of the file made the
// "pid=... time=..." line unreadable to a contending process: readLockHolder's
// os.ReadFile failed with ERROR_LOCK_VIOLATION and the contention message fell
// back to the generic form, never naming the holder — while the unix test
// asserts it does. Locking past the data keeps the diagnostic readable and the
// mutual exclusion identical, since every participant locks the same range.
// Locking a range beyond end-of-file is legal on Windows.
func lockRegion() windows.Overlapped {
	return windows.Overlapped{
		Offset:     0,
		OffsetHigh: 0x8000_0000,
	}
}
