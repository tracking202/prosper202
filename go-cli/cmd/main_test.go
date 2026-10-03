package cmd

import (
	"os"
	"testing"
)

// TestMain makes the suite independent of where it runs: inside an AI agent
// (CLAUDECODE, AI_AGENT, ...) or with P202_OUTPUT exported, every command
// would print JSON and the table assertions would fail. Tests that need
// those variables set them with t.Setenv.
func TestMain(m *testing.M) {
	for _, name := range append([]string{outputEnvVar}, agentEnvVars...) {
		_ = os.Unsetenv(name)
	}
	os.Exit(m.Run())
}
