package cmd

// timeseriesTail is what timeseriesTruncationWarning reads from an answer:
// how many buckets, the last one, and the server's truncated and limit.
type timeseriesTail struct {
	Buckets   int
	Last      map[string]interface{}
	Truncated *bool
	Limit     *int
}
