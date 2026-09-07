package cmd

import (
	"io"
	"math"
	"os"
	"strings"
	"testing"
)

// captureBoth runs fn with os.Stdout and os.Stderr redirected, returning what
// each received. Both pipes are drained concurrently so a writer can never block
// on the kernel buffer.
func captureBoth(fn func()) (string, string) {
	oldStdout, oldStderr := os.Stdout, os.Stderr
	rOut, wOut, _ := os.Pipe()
	rErr, wErr, _ := os.Pipe()
	os.Stdout, os.Stderr = wOut, wErr

	outCh := make(chan []byte, 1)
	errCh := make(chan []byte, 1)
	go func() { b, _ := io.ReadAll(rOut); outCh <- b }()
	go func() { b, _ := io.ReadAll(rErr); errCh <- b }()

	fn()

	os.Stdout, os.Stderr = oldStdout, oldStderr
	_ = wOut.Close()
	_ = wErr.Close()
	stdout, stderr := <-outCh, <-errCh
	_ = rOut.Close()
	_ = rErr.Close()
	return string(stdout), string(stderr)
}

func TestRoundHalfAwayFromZero(t *testing.T) {
	cases := []struct {
		in     float64
		places int
		want   float64
	}{
		{1.2345, 2, 1.23},
		{1.235, 2, 1.24},
		{-1.235, 2, -1.24},
		{2.5, 0, 3},
		{-2.5, 0, -3},
		{0, 4, 0},
		{0.28861386, 4, 0.2886},
	}
	for _, tc := range cases {
		if got := round(tc.in, tc.places); math.Abs(got-tc.want) > 1e-9 {
			t.Errorf("round(%v, %d) = %v, want %v", tc.in, tc.places, got, tc.want)
		}
	}
}

// The previous implementation cast through int64, which is undefined in Go once
// the scaled value leaves the int64 range and turned NaN/Inf into an arbitrary
// finite number. Non-finite values must pass through so they are never reported
// as a plausible-looking figure.
func TestRoundPreservesNonFiniteAndLargeValues(t *testing.T) {
	if got := round(math.NaN(), 2); !math.IsNaN(got) {
		t.Errorf("round(NaN) = %v, want NaN", got)
	}
	if got := round(math.Inf(1), 2); !math.IsInf(got, 1) {
		t.Errorf("round(+Inf) = %v, want +Inf", got)
	}
	if got := round(math.Inf(-1), 2); !math.IsInf(got, -1) {
		t.Errorf("round(-Inf) = %v, want -Inf", got)
	}

	// 1e18 scaled by 10^4 overflows int64; the value must survive intact.
	const big = 1e18
	if got := round(big, 4); got != big {
		t.Errorf("round(%v, 4) = %v, want %v", big, got, big)
	}
}

// Many commands build their payload with json.Marshal and ignore the error.
// render() must not turn a nil payload into a silent, successful no-op.
func TestRenderReportsAnEmptyPayloadInsteadOfPrintingNothing(t *testing.T) {
	for _, data := range [][]byte{nil, {}, []byte("   \n")} {
		stdout, stderr := captureBoth(func() { render(data) })
		if strings.TrimSpace(stdout) != "" {
			t.Errorf("render(%q) wrote to stdout: %q", data, stdout)
		}
		if !strings.Contains(stderr, "could not be encoded") {
			t.Errorf("render(%q) did not report the failure on stderr: %q", data, stderr)
		}
	}
}
