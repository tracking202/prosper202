package shell

import (
	"fmt"
	"strings"
)

// TokenizeLine splits a command line into tokens, respecting quoted strings.
// Single quotes preserve literal content; double quotes allow spaces but no escapes.
// A quoted empty string ("" or '') produces an empty token, so flags can be
// given explicitly empty values. A # at the start of a word begins a comment
// that runs to the end of the line. Returns an error for unterminated quotes.
func TokenizeLine(line string) ([]string, error) {
	var tokens []string
	var current strings.Builder
	inSingle := false
	inDouble := false
	quoted := false // current token contained quotes, so it survives even when empty

	flush := func() {
		if current.Len() > 0 || quoted {
			tokens = append(tokens, current.String())
		}
		current.Reset()
		quoted = false
	}

scan:
	for i := 0; i < len(line); i++ {
		ch := line[i]
		switch {
		case ch == '\'' && !inDouble:
			inSingle = !inSingle
			quoted = true
		case ch == '"' && !inSingle:
			inDouble = !inDouble
			quoted = true
		case (ch == ' ' || ch == '\t') && !inSingle && !inDouble:
			flush()
		case ch == '#' && !inSingle && !inDouble && current.Len() == 0 && !quoted:
			break scan
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

	flush()
	return tokens, nil
}

// SplitCommands splits an input string on semicolons and newlines, respecting
// quoted strings. A # at the start of a word begins a comment that runs to the
// next command separator, so both full-line comments and trailing comments
// after a command are stripped. Empty commands are skipped.
func SplitCommands(input string) ([]string, error) {
	var commands []string
	var current strings.Builder
	inSingle := false
	inDouble := false
	atWordStart := true // a # only begins a comment at the start of a word

	flush := func() {
		cmd := strings.TrimSpace(current.String())
		if cmd != "" {
			commands = append(commands, cmd)
		}
		current.Reset()
	}

	for i := 0; i < len(input); i++ {
		ch := input[i]
		switch {
		case ch == '\'' && !inDouble:
			inSingle = !inSingle
			current.WriteByte(ch)
			atWordStart = false
		case ch == '"' && !inSingle:
			inDouble = !inDouble
			current.WriteByte(ch)
			atWordStart = false
		case (ch == ';' || ch == '\n') && !inSingle && !inDouble:
			flush()
			atWordStart = true
		case ch == '#' && !inSingle && !inDouble && atWordStart:
			// Skip the comment up to (but not including) the next separator.
			for i+1 < len(input) && input[i+1] != ';' && input[i+1] != '\n' {
				i++
			}
		default:
			current.WriteByte(ch)
			atWordStart = ch == ' ' || ch == '\t'
		}
	}

	if inSingle {
		return nil, fmt.Errorf("unterminated single quote")
	}
	if inDouble {
		return nil, fmt.Errorf("unterminated double quote")
	}

	flush()
	return commands, nil
}
