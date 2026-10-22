//go:build !js

package cmd

// hostedArgsRefusal: a native build runs every command.
func hostedArgsRefusal([]string) error { return nil }

// hostedPinOutput: a native build's commands choose their output as usual.
func hostedPinOutput([]string) error { return nil }
