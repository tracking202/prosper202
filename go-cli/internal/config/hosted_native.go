//go:build !js

package config

// hostedConfig is the js/wasm build's injected configuration; a native build
// always reads its config file.
func hostedConfig() (*Config, bool, error) { return nil, false, nil }
