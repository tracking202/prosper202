//go:build !js

package cmd

// hostedArgsRefusal: a native build runs every command.
func hostedArgsRefusal([]string) error { return nil }
