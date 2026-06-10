package shell

import (
	"fmt"
	"strings"
	"unicode"
)

// TokenizeLine splits a command line into tokens, respecting quoted strings.
// Single quotes preserve literal content; double quotes allow spaces but no escapes.
// Returns an error for unterminated quotes.
func TokenizeLine(line string) ([]string, error) {
	var tokens []string
	var current strings.Builder
	inSingle := false
	inDouble := false

	for i := 0; i < len(line); i++ {
		ch := line[i]
		switch {
		case ch == '\'' && !inDouble:
			inSingle = !inSingle
		case ch == '"' && !inSingle:
			inDouble = !inDouble
		case (ch == ' ' || ch == '\t') && !inSingle && !inDouble:
			if current.Len() > 0 {
				tokens = append(tokens, current.String())
				current.Reset()
			}
		default:
			current.WriteByte(ch)
		}
	}

	if inSingle {
		return nil, fmt.Errorf("unterminated single quote")
	}
	if inDouble {
		return nil, fmt.Errorf("unterminated double quote")
	}

	if current.Len() > 0 {
		tokens = append(tokens, current.String())
	}
	return tokens, nil
}

// SplitCommands splits an input string on semicolons, respecting quoted strings.
// Lines starting with # (after trimming whitespace) are treated as comments and skipped.
// Empty commands are skipped.
func SplitCommands(input string) ([]string, error) {
	var commands []string
	var current strings.Builder
	inSingle := false
	inDouble := false

	for i := 0; i < len(input); i++ {
		ch := input[i]
		switch {
		case ch == '\'' && !inDouble:
			inSingle = !inSingle
			current.WriteByte(ch)
		case ch == '"' && !inSingle:
			inDouble = !inDouble
			current.WriteByte(ch)
		case ch == ';' && !inSingle && !inDouble:
			cmd := strings.TrimSpace(current.String())
			if cmd != "" && !isComment(cmd) {
				commands = append(commands, cmd)
			}
			current.Reset()
		case ch == '\n' && !inSingle && !inDouble:
			cmd := strings.TrimSpace(current.String())
			if cmd != "" && !isComment(cmd) {
				commands = append(commands, cmd)
			}
			current.Reset()
		default:
			current.WriteByte(ch)
		}
	}

	if inSingle {
		return nil, fmt.Errorf("unterminated single quote")
	}
	if inDouble {
		return nil, fmt.Errorf("unterminated double quote")
	}

	cmd := strings.TrimSpace(current.String())
	if cmd != "" && !isComment(cmd) {
		commands = append(commands, cmd)
	}
	return commands, nil
}

func isComment(s string) bool {
	trimmed := strings.TrimLeftFunc(s, unicode.IsSpace)
	return strings.HasPrefix(trimmed, "#")
}
