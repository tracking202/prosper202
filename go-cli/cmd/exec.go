package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	osexec "os/exec"
	"sort"
	"strings"
	"sync"

	"github.com/spf13/cobra"
)

type execResult struct {
	Profile  string
	Stdout   string
	Stderr   string
	ExitCode int
	Err      error
}

type execCall struct {
	Profile   string
	SubArgs   []string
	ForceJSON bool
}

var execProfileRunner = defaultExecProfileRunner

var execCmd = &cobra.Command{
	Use:                "exec [flags] -- <subcommand...>",
	Short:              "Run a subcommand across multiple profiles",
	DisableFlagParsing: false,
	Args:               cobra.ArbitraryArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		profiles, err := resolveMultiProfiles(cmd)
		if err != nil {
			return err
		}
		if len(profiles) == 0 {
			return fmt.Errorf("exec requires one of --all-profiles, --profiles, or --group")
		}

		subArgs := append([]string(nil), args...)
		if len(subArgs) == 0 {
			return fmt.Errorf("exec requires a subcommand after --")
		}
		if subArgs[0] == "exec" {
			return fmt.Errorf("recursive exec invocation is not allowed")
		}

		concurrency, _ := cmd.Flags().GetInt("concurrency")
		if concurrency < 1 {
			return fmt.Errorf("--concurrency must be at least 1")
		}
		if concurrency > len(profiles) {
			concurrency = len(profiles)
		}

		jobs := make(chan string, len(profiles))
		resultsCh := make(chan execResult, len(profiles))
		var wg sync.WaitGroup

		for i := 0; i < concurrency; i++ {
			wg.Add(1)
			go func() {
				defer wg.Done()
				for profile := range jobs {
					resultsCh <- execProfileRunner(execCall{
						Profile:   profile,
						SubArgs:   subArgs,
						ForceJSON: jsonOutput,
					})
				}
			}()
		}

		for _, profile := range profiles {
			jobs <- profile
		}
		close(jobs)

		wg.Wait()
		close(resultsCh)

		resultMap := map[string]execResult{}
		anyFailed := false
		for result := range resultsCh {
			resultMap[result.Profile] = result
			if result.Err != nil || result.ExitCode != 0 {
				anyFailed = true
			}
		}

		sortedProfiles := append([]string(nil), profiles...)
		sort.Strings(sortedProfiles)

		if jsonOutput {
			payload := map[string]interface{}{
				"results": map[string]interface{}{},
				"errors":  map[string]string{},
			}

			resultsObj := payload["results"].(map[string]interface{})
			errorsObj := payload["errors"].(map[string]string)
			for _, profile := range sortedProfiles {
				result := resultMap[profile]
				entry := map[string]interface{}{
					"exit_code": result.ExitCode,
					"stdout":    result.Stdout,
					"stderr":    result.Stderr,
				}

				var parsed interface{}
				if strings.TrimSpace(result.Stdout) != "" && json.Unmarshal([]byte(result.Stdout), &parsed) == nil {
					entry["output"] = parsed
				}
				resultsObj[profile] = entry
				if result.Err != nil {
					errorsObj[profile] = result.Err.Error()
				} else if result.ExitCode != 0 {
					errorsObj[profile] = fmt.Sprintf("exit code %d", result.ExitCode)
				}
			}

			data, _ := json.Marshal(payload)
			render(data)

			if anyFailed {
				return fmt.Errorf("one or more profiles failed")
			}
			return nil
		}

		for _, profile := range sortedProfiles {
			result := resultMap[profile]
			fmt.Printf("=== %s ===\n", profile)
			if strings.TrimSpace(result.Stdout) != "" {
				fmt.Println(strings.TrimRight(result.Stdout, "\n"))
			}
			if strings.TrimSpace(result.Stderr) != "" {
				fmt.Println(strings.TrimRight(result.Stderr, "\n"))
			}
			if result.Err != nil {
				fmt.Printf("error: %v\n", result.Err)
			}
			if result.ExitCode != 0 {
				fmt.Printf("exit code: %d\n", result.ExitCode)
			}
			fmt.Println()
		}

		if anyFailed {
			return fmt.Errorf("one or more profiles failed")
		}
		return nil
	},
}

func defaultExecProfileRunner(call execCall) execResult {
	executable, err := os.Executable()
	if err != nil {
		return execResult{Profile: call.Profile, ExitCode: 1, Err: err}
	}

	args := []string{"--profile", call.Profile}
	if call.ForceJSON {
		args = append(args, "--json")
	}
	args = append(args, call.SubArgs...)

	command := osexec.Command(executable, args...)
	output, err := command.CombinedOutput()
	result := execResult{
		Profile: call.Profile,
		Stdout:  string(output),
	}
	if err == nil {
		return result
	}

	result.ExitCode = 1
	result.Err = err
	if exitErr, ok := err.(*osexec.ExitError); ok {
		result.ExitCode = exitErr.ExitCode()
	}
	return result
}

func init() {
	execCmd.Flags().Int("concurrency", 5, "Number of concurrent profile executions")
	addMultiProfileFlags(execCmd)
	rootCmd.AddCommand(execCmd)
}
