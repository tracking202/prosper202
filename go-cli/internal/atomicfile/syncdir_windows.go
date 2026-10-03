//go:build windows

package atomicfile

// syncDir is a no-op on Windows: a directory handle cannot be opened for
// synchronisation the way it can on POSIX. MoveFileEx already replaces the
// destination entry atomically, so the rename is not observed half-applied;
// only the flush-to-platter ordering guarantee is unavailable.
func syncDir(string) {}
