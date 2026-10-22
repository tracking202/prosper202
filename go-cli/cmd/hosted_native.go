//go:build !js

package cmd

import "github.com/spf13/cobra"

// hostedArgsRefusal: a native build runs every command.
func hostedArgsRefusal([]string) error { return nil }

// hostedJSONOnly: a native build's commands choose their output as usual.
func hostedJSONOnly(*cobra.Command) bool { return false }
