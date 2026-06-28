package cmd

import (
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

func render(data []byte) {
	output.RenderWith(data, renderOpts())
}
