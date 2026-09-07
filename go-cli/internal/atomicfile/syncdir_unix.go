//go:build !windows

package atomicfile

import "os"

// syncDir fsyncs a directory so a rename into it is durable.
func syncDir(dir string) {
	d, err := os.Open(dir)
	if err != nil {
		return
	}
	_ = d.Sync()
	_ = d.Close()
}
