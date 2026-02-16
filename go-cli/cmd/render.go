package cmd

import "p202/internal/output"

func render(data []byte) {
	if csvOutput {
		output.RenderCSV(data)
		return
	}
	output.Render(data, jsonOutput)
}
