//go:build !js

package cmd

// hostedBuild: a native build is not the hosted relay's engine.
const hostedBuild = false

// hostedArgsRefusal: a native build runs every command.
func hostedArgsRefusal([]string) error { return nil }

// hostedPinOutput: a native build's commands choose their output as usual.
func hostedPinOutput([]string) error { return nil }
