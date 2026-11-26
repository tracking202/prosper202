package cmd

import (
	"encoding/json"
	"fmt"

	"github.com/spf13/cobra"
)

// pagedMoreRowsNote is the note under one page of a read whose answer has no `pagination` (the table renderer warns
// under those) and says rows remain after it (`truncated`, which every v3 answer to a request taking offset
// carries); "" for the last page and for a server from before `truncated`, which cannot say. The page's offset is
// the answer's own, at the top level or under meta, else 0.
func pagedMoreRowsNote(data []byte) string {
	var resp struct {
		Data       []json.RawMessage `json:"data"`
		Truncated  *bool             `json:"truncated"`
		Offset     *int              `json:"offset"`
		Pagination json.RawMessage   `json:"pagination"`
		Meta       struct {
			Offset *int `json:"offset"`
		} `json:"meta"`
	}
	if json.Unmarshal(data, &resp) != nil || resp.Truncated == nil || !*resp.Truncated || resp.Pagination != nil {
		return ""
	}
	offset := 0
	if resp.Offset != nil {
		offset = *resp.Offset
	} else if resp.Meta.Offset != nil {
		offset = *resp.Meta.Offset
	}
	next := offset + len(resp.Data)
	return fmt.Sprintf("Note: more rows remain after rows %d-%d; --offset %d reads the next page.", offset+1, next, next)
}

// notePagedRows prints pagedMoreRowsNote under a table, CSV or --quiet (noteForPeople); --json and --ndjson carry
// `truncated` itself.
func notePagedRows(cmd *cobra.Command, data []byte) {
	noteForPeople(cmd, pagedMoreRowsNote(data))
}
