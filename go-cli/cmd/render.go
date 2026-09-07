package cmd

import (
	"bytes"
	"fmt"
	"os"
	"strings"

	"p202/internal/output"
)

// renderOpts builds output.Opts from the global output flags.
func renderOpts() output.Opts {
	opts := output.Opts{
		JSON:       jsonOutput,
		CSV:        csvOutput,
		Quiet:      quietOutput,
		NDJSON:     ndjsonOutput,
		Wide:       wideOutput,
		RawHeaders: rawHeaders,
	}
	if strings.TrimSpace(fieldsFlag) != "" {
		for _, f := range strings.Split(fieldsFlag, ",") {
			if f = strings.TrimSpace(f); f != "" {
				opts.Fields = append(opts.Fields, f)
			}
		}
	}
	return opts
}

// render writes an API payload using the global output flags.
//
// An empty payload reaching here means the caller could not build one — several
// callers assemble theirs with json.Marshal and would otherwise pass nil on
// failure. Rendering nil prints nothing and exits 0, which reads as "no results"
// rather than "we failed to encode the results", so report it explicitly.
func render(data []byte) {
	if len(bytes.TrimSpace(data)) == 0 {
		fmt.Fprintln(os.Stderr, "Error: no output produced — the response payload could not be encoded.")
		return
	}
	output.RenderWith(data, renderOpts())
}
