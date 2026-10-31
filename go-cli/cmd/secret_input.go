package cmd

import (
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"golang.org/x/term"
)

// readSecret reads a password-like value without echoing it: from the
// terminal with a hidden prompt, or, when stdin is piped (an agent, a
// script), as one line of stdin. term.ReadPassword on a pipe fails with
// "inappropriate ioctl for device", which left the piped caller no way in
// but a flag value — and a flag value sits in shell history and ps output.
//
// The pipe is read a byte at a time, never through a bufio.Reader: the
// interactive shell reads its own commands from the same stdin, and a
// buffered read would swallow them.
func readSecret(prompt string) (string, error) {
	if isTerminal(os.Stdin) {
		fmt.Fprint(os.Stderr, prompt)
		b, err := term.ReadPassword(int(os.Stdin.Fd()))
		fmt.Fprintln(os.Stderr)
		if err != nil {
			return "", fmt.Errorf("reading %s: %w", strings.TrimSuffix(strings.TrimSpace(prompt), ":"), err)
		}
		return string(b), nil
	}
	return readLineUnbuffered(os.Stdin)
}

// readLineUnbuffered returns the next line of r without its line ending,
// reading no further than the newline.
func readLineUnbuffered(r io.Reader) (string, error) {
	var line []byte
	buf := make([]byte, 1)
	for {
		n, err := r.Read(buf)
		if n == 1 {
			if buf[0] == '\n' {
				break
			}
			line = append(line, buf[0])
		}
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return "", fmt.Errorf("reading stdin: %w", err)
		}
	}
	return strings.TrimRight(string(line), "\r"), nil
}

// readNewPassword reads a new password: twice at a terminal, so a typo is
// caught before it locks someone out, and once from a pipe.
func readNewPassword(prompt string) (string, error) {
	pass, err := readSecret(prompt)
	if err != nil || pass == "" || !isTerminal(os.Stdin) {
		return pass, err
	}
	again, err := readSecret("Retype it (hidden): ")
	if err != nil {
		return "", err
	}
	if again != pass {
		return "", validationError("the two passwords did not match; nothing was changed")
	}
	return pass, nil
}
