//go:build js || wasip1

package syncstate

import (
	"errors"
	"os"
)

// A hosted (WebAssembly) run has no filesystem, so sync state, and the lock
// that guards it, does not exist there.
func acquireLockFile(path string) (*os.File, error) {
	return nil, errors.New("sync state is not available in a hosted run")
}

func releaseLockFile(file *os.File) {}
