//go:build !go1.27

package cmd

import "encoding/json"

// timeseriesTailOf decodes every bucket; Go 1.27's jsontext reads them one
// at a time (timeseries_tail_go127.go).
func timeseriesTailOf(data []byte) (timeseriesTail, bool) {
	var resp struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
	}
	if json.Unmarshal(data, &resp) != nil {
		return timeseriesTail{}, false
	}
	t := timeseriesTail{Buckets: len(resp.Data), Truncated: resp.Truncated, Limit: resp.Limit}
	if len(resp.Data) > 0 {
		t.Last = resp.Data[len(resp.Data)-1]
	}
	return t, true
}
