package cmd

import (
	"bytes"
	"crypto/sha256"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/metrics"
	"p202/internal/output"
)

// Row statuses of `conversion import`.
const (
	importReady           = "ready"
	importInvalid         = "invalid"
	importDuplicateInFile = "duplicate_in_file"
	importDuplicate       = "duplicate"
	importClickNotFound   = "click_not_found"
	importCreated         = "created"
	importStaged          = "staged"
	importFailed          = "failed"
)

// importStatusOrder is the order statuses are counted and summarised in.
var importStatusOrder = []string{importCreated, importStaged, importReady, importDuplicate,
	importClickNotFound, importDuplicateInFile, importInvalid, importFailed}

// conversionImportColumns keeps the key fields first in tables; --fields overrides.
var conversionImportColumns = []string{"row", "subid", "click_id", "transaction_id", "payout", "conv_time",
	"status", "conv_id", "recorded_payout", "change_id", "reason"}

// conversionImportRow is one line of the file and what became of it.
type conversionImportRow struct {
	Row            int    `json:"row"`
	Subid          string `json:"subid"`
	ClickID        int64  `json:"click_id,omitempty"`
	TransactionID  string `json:"transaction_id,omitempty"`
	Payout         string `json:"payout,omitempty"`
	ConvTime       int64  `json:"conv_time,omitempty"`
	Status         string `json:"status"`
	ConvID         int64  `json:"conv_id,omitempty"`
	RecordedPayout string `json:"recorded_payout,omitempty"`
	ChangeID       string `json:"change_id,omitempty"`
	Reason         string `json:"reason,omitempty"`
	IdempotencyKey string `json:"idempotency_key,omitempty"`

	payoutUnits int64 // Payout in 0.00001 units, when hasPayout
	hasPayout   bool
}

// importRole is one column the import reads, and the headers that name it.
type importRole struct {
	key        string // meta.columns key
	flag       string
	label      string
	candidates []string // normalized header names, see normalizeImportHeader
}

var (
	importSubidRole  = importRole{"subid", "subid-column", "subid", []string{"subid", "sub_id", "aff_sub", "sub1", "click_id", "clickid", "s2"}}
	importPayoutRole = importRole{"payout", "payout-column", "payout", []string{"payout", "commission", "amount", "revenue"}}
	importTxidRole   = importRole{"transaction_id", "txid-column", "transaction id", []string{"transaction_id", "transactionid", "order_id", "orderid", "txid"}}
	importTimeRole   = importRole{"conv_time", "time-column", "conversion time", []string{"date", "time", "conversion_date", "created_at"}}
	importRoles      = []importRole{importSubidRole, importPayoutRole, importTxidRole, importTimeRole}
)

// importCell is one value from the file; bad says why a JSON value cannot be one.
type importCell struct {
	value string
	bad   string
}

type importRecord struct {
	row   int // file line (CSV) or 1-based array position (JSON)
	cells []importCell
}

type importTable struct {
	format  string
	headers []string
	records []importRecord
}

// importColumnChoice is the column chosen for a role: -1 when the file has none.
type importColumnChoice struct {
	index  int
	header string
	via    string // "auto" or "--<flag>"
}

const maxImportFileSize = 64 << 20

// readConversionImportFile parses a CSV file with a header row, or a JSON array of objects.
func readConversionImportFile(path string) (*importTable, error) {
	hint := "Pass a CSV file with a header row, or a JSON array of objects, exported from the network's conversion report."
	info, err := os.Stat(path)
	if err != nil {
		var pe *fs.PathError
		if errors.As(err, &pe) {
			err = pe.Err // the message already names the path
		}
		return nil, validationError("cannot read %s: %v", path, err).WithHint("Check the path. %s", hint)
	}
	if info.IsDir() {
		return nil, validationError("%s is a directory", path).WithHint("%s", hint)
	}
	if info.Size() > maxImportFileSize {
		return nil, validationError("%s is %d MB; the import reads files up to %d MB", path, info.Size()>>20, maxImportFileSize>>20).
			WithHint("Split the export into smaller files (by date range) and import each one.")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, validationError("cannot read %s: %v", path, err).WithHint("Check the path and its permissions.")
	}
	data = bytes.TrimPrefix(data, []byte("\xef\xbb\xbf"))
	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 {
		return nil, validationError("%s is empty", path).WithHint("%s", hint)
	}
	switch trimmed[0] {
	case '[':
		return parseImportJSON(path, trimmed)
	case '{':
		return nil, validationError("%s holds a JSON object; the import reads a JSON array of objects", path).
			WithHint(`Wrap the conversions in a list: [{"subid": "123", "payout": "4.50"}, ...].`)
	}
	return parseImportCSV(path, data)
}

func parseImportJSON(path string, data []byte) (*importTable, error) {
	var objects []map[string]json.RawMessage
	if err := decodeOneJSON(data, &objects); err != nil {
		return nil, validationError("%s is not a JSON array of objects: %v", path, err).
			WithHint(`Each element must be an object, e.g. [{"subid": "123", "payout": "4.50", "transaction_id": "A1"}].`)
	}
	if len(objects) == 0 {
		return nil, validationError("%s is an empty JSON array; there is nothing to import", path).
			WithHint("Export the network's conversions for the period you need, then import that file.")
	}
	seen := map[string]bool{}
	var headers []string
	for _, obj := range objects {
		for k := range obj {
			if !seen[k] {
				seen[k] = true
				headers = append(headers, k)
			}
		}
	}
	sort.Strings(headers)
	t := &importTable{format: "json", headers: headers}
	for i, obj := range objects {
		rec := importRecord{row: i + 1, cells: make([]importCell, len(headers))}
		for j, h := range headers {
			if raw, ok := obj[h]; ok {
				rec.cells[j] = importJSONCell(raw)
			}
		}
		t.records = append(t.records, rec)
	}
	return t, nil
}

// importJSONCell reads a JSON value as a cell: strings and numbers as written, null as empty.
func importJSONCell(raw json.RawMessage) importCell {
	text := strings.TrimSpace(string(raw))
	switch {
	case text == "" || text == "null":
		return importCell{}
	case text[0] == '"':
		var s string
		if err := json.Unmarshal(raw, &s); err != nil {
			return importCell{bad: "an unreadable string"}
		}
		return importCell{value: s}
	case text[0] == '-' || (text[0] >= '0' && text[0] <= '9'):
		return importCell{value: text}
	case text == "true" || text == "false":
		return importCell{value: text, bad: "a boolean"}
	case text[0] == '{':
		return importCell{bad: "an object"}
	default:
		return importCell{bad: "a list"}
	}
}

func parseImportCSV(path string, data []byte) (*importTable, error) {
	r := csv.NewReader(bytes.NewReader(data))
	r.Comma = sniffImportDelimiter(data)
	r.FieldsPerRecord = -1
	header, err := r.Read()
	if err != nil {
		return nil, validationError("%s is not a readable CSV file: %v", path, err).
			WithHint("Save the export as CSV with a header row (or as a JSON array of objects) and retry.")
	}
	t := &importTable{format: "csv"}
	blank := true
	for _, h := range header {
		h = strings.TrimSpace(h)
		if h != "" {
			blank = false
		}
		t.headers = append(t.headers, h)
	}
	if blank {
		return nil, validationError("%s has an empty header row", path).
			WithHint("The first line must name the columns, e.g. subid,payout,transaction_id,date.")
	}
	for {
		fields, err := r.Read()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, validationError("%s is not a readable CSV file: %v", path, err).
				WithHint("Fix the line the message names (an unbalanced quote is the usual cause) and retry; nothing was sent.")
		}
		line, _ := r.FieldPos(0)
		rec := importRecord{row: line, cells: make([]importCell, len(fields))}
		for i, f := range fields {
			rec.cells[i] = importCell{value: f}
		}
		t.records = append(t.records, rec)
	}
	if len(t.records) == 0 {
		return nil, validationError("%s has a header row but no conversions", path).
			WithHint("Export the network's conversions for the period you need, then import that file.")
	}
	return t, nil
}

// sniffImportDelimiter picks comma, semicolon or tab, whichever the header line uses most.
func sniffImportDelimiter(data []byte) rune {
	line := data
	if i := bytes.IndexAny(data, "\r\n"); i >= 0 {
		line = data[:i]
	}
	best, bestCount := ',', bytes.Count(line, []byte(","))
	for _, d := range []rune{';', '\t'} {
		if n := bytes.Count(line, []byte(string(d))); n > bestCount {
			best, bestCount = d, n
		}
	}
	return best
}

// normalizeImportHeader lowercases h and joins its words with underscores ("Sub ID" -> "sub_id").
func normalizeImportHeader(h string) string {
	var b strings.Builder
	pending := false
	for _, r := range strings.ToLower(strings.TrimSpace(h)) {
		if unicode.IsLetter(r) || unicode.IsDigit(r) {
			if pending && b.Len() > 0 {
				b.WriteByte('_')
			}
			pending = false
			b.WriteRune(r)
			continue
		}
		pending = true
	}
	return b.String()
}

func quoteImportHeaders(headers []string) string {
	q := make([]string, len(headers))
	for i, h := range headers {
		q[i] = strconv.Quote(h)
	}
	return strings.Join(q, ", ")
}

// chooseImportColumn finds role's column: the header --<flag> names, else the one common header it has.
func chooseImportColumn(headers []string, role importRole, override string) (importColumnChoice, error) {
	var matches []int
	if override != "" {
		want := normalizeImportHeader(override)
		for i, h := range headers {
			if strings.EqualFold(h, strings.TrimSpace(override)) || (want != "" && normalizeImportHeader(h) == want) {
				matches = append(matches, i)
			}
		}
		switch len(matches) {
		case 0:
			return importColumnChoice{}, validationError("--%s %q names no column; the file's headers are: %s", role.flag, override, quoteImportHeaders(headers)).
				WithHint("Pass one of those headers (case and punctuation do not matter).")
		case 1:
			return importColumnChoice{index: matches[0], header: headers[matches[0]], via: "--" + role.flag}, nil
		}
		return importColumnChoice{}, validationError("--%s %q matches %d columns: %s", role.flag, override, len(matches), quoteImportHeaders(pick(headers, matches))).
			WithHint("Rename one of the duplicate headers in the file so --%s names exactly one column.", role.flag)
	}
	for i, h := range headers {
		n := normalizeImportHeader(h)
		for _, c := range role.candidates {
			if n == c {
				matches = append(matches, i)
				break
			}
		}
	}
	switch len(matches) {
	case 0:
		if role.key != importSubidRole.key {
			return importColumnChoice{index: -1}, nil
		}
		return importColumnChoice{}, validationError("no subid column found; the file's headers are: %s (looked for %s)", quoteImportHeaders(headers), strings.Join(role.candidates, ", ")).
			WithHint("Pass --subid-column <header> naming the column that holds the Prosper202 subid (the click id your offer URL sends as [[subid]]).")
	case 1:
		return importColumnChoice{index: matches[0], header: headers[matches[0]], via: "auto"}, nil
	}
	return importColumnChoice{}, validationError("more than one column could be the %s: %s", role.label, quoteImportHeaders(pick(headers, matches))).
		WithHint("Choose one with --%s <header>.", role.flag)
}

func pick(headers []string, idx []int) []string {
	out := make([]string, len(idx))
	for i, j := range idx {
		out[i] = headers[j]
	}
	return out
}

// importColumns are the four chosen columns, keyed by role key.
type importColumns map[string]importColumnChoice

func chooseImportColumns(headers []string, overrides map[string]string) (importColumns, error) {
	cols := importColumns{}
	used := map[int]string{}
	for _, role := range importRoles {
		ch, err := chooseImportColumn(headers, role, overrides[role.key])
		if err != nil {
			return nil, err
		}
		if ch.index >= 0 {
			if other, ok := used[ch.index]; ok {
				return nil, validationError("column %q was chosen as both the %s and the %s", ch.header, other, role.label).
					WithHint("Name a different column with --%s <header>.", role.flag)
			}
			used[ch.index] = role.label
		}
		cols[role.key] = ch
	}
	return cols, nil
}

// parseImportClickID derives the click id from a subid as Prosper202\Click\ClickId::parse does
// for the postback and the uploads: digits, no sign, no leading zero, within bigint.
func parseImportClickID(subid string) (int64, bool) {
	if !clickIDPattern.MatchString(subid) {
		return 0, false
	}
	id, err := strconv.ParseInt(subid, 10, 64)
	if err != nil || id <= 0 {
		return 0, false
	}
	return id, true
}

var importAmountPattern = regexp.MustCompile(`^(-)?(\d+)(?:\.(\d+))?$`)

// parseImportAmount reads a payout as RevenueUploadImporter::parseAmount and Amount::toUnits do:
// currency signs, thousands separators and spaces dropped, 5 decimals rounded half away from zero.
func parseImportAmount(raw string) (units int64, canonical string, ok bool) {
	clean := strings.NewReplacer("$", "", ",", "", " ", "", "\u00a0", "").Replace(strings.TrimSpace(raw))
	m := importAmountPattern.FindStringSubmatch(clean)
	if m == nil {
		return 0, "", false
	}
	whole := strings.TrimLeft(m[2], "0")
	if len(whole) > 13 {
		return 0, "", false
	}
	frac := m[3]
	roundUp := false
	if len(frac) > 5 {
		roundUp = frac[5] >= '5'
		frac = frac[:5]
	}
	frac += strings.Repeat("0", 5-len(frac))
	w, _ := strconv.ParseInt("0"+whole, 10, 64)
	f, _ := strconv.ParseInt(frac, 10, 64)
	units = w*100000 + f
	if roundUp {
		units++
	}
	if m[1] == "-" {
		units = -units
	}
	return units, formatImportUnits(units), true
}

// formatImportUnits prints 0.00001 units with at least two decimals ("12.50", "0.12345").
func formatImportUnits(units int64) string {
	sign := ""
	if units < 0 {
		sign, units = "-", -units
	}
	frac := strings.TrimRight(fmt.Sprintf("%05d", units%100000), "0")
	for len(frac) < 2 {
		frac += "0"
	}
	return fmt.Sprintf("%s%d.%s", sign, units/100000, frac)
}

var importTimeLayouts = []string{
	"2006-01-02 15:04:05",
	"2006-01-02T15:04:05",
	"2006-01-02 15:04",
	"2006-01-02T15:04",
	"2006-01-02",
}

var importZonedLayouts = []string{time.RFC3339Nano, "2006-01-02 15:04:05Z07:00", "2006-01-02 15:04:05 -0700"}

// parseImportTime reads a conversion time: unix seconds or milliseconds, an ISO date or --time-format.
func parseImportTime(raw, layout string, loc *time.Location) (int64, string) {
	if layout != "" {
		t, err := time.ParseInLocation(layout, raw, loc)
		if err != nil {
			return 0, fmt.Sprintf("time %q does not match --time-format %q", raw, layout)
		}
		return importUnix(raw, t)
	}
	if isAllDigits(raw) {
		// Fixed widths only: an 8-digit 20260203 read as seconds would land in 1970.
		n, err := strconv.ParseInt(raw, 10, 64)
		switch {
		case err == nil && len(raw) == 10:
			return importUnix(raw, time.Unix(n, 0))
		case err == nil && len(raw) == 13:
			return importUnix(raw, time.UnixMilli(n))
		case len(raw) == 8:
			if t, err := time.ParseInLocation("20060102", raw, loc); err == nil {
				return importUnix(raw, t)
			}
		}
		return 0, fmt.Sprintf("time %q is neither unix seconds (10 digits), milliseconds (13) nor a YYYYMMDD date", raw)
	}
	for _, l := range importZonedLayouts {
		if t, err := time.Parse(l, raw); err == nil {
			return importUnix(raw, t)
		}
	}
	for _, l := range importTimeLayouts {
		if t, err := time.ParseInLocation(l, raw, loc); err == nil {
			return importUnix(raw, t)
		}
	}
	return 0, fmt.Sprintf("time %q is not a unix timestamp or a date like 2026-02-03 14:05:00 (pass --time-format for other layouts)", raw)
}

func importUnix(raw string, t time.Time) (int64, string) {
	if t.Unix() <= 0 {
		return 0, fmt.Sprintf("time %q is not after 1970", raw)
	}
	return t.Unix(), ""
}

func isAllDigits(s string) bool {
	if s == "" {
		return false
	}
	for _, r := range s {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

var importOffsetPattern = regexp.MustCompile(`^([+-])(\d{2}):?(\d{2})$`)

// parseImportTimezone reads --timezone: UTC, Local, an IANA name or an offset like +05:30.
func parseImportTimezone(tz string) (*time.Location, error) {
	tz = strings.TrimSpace(tz)
	switch {
	case strings.EqualFold(tz, "UTC") || tz == "Z":
		return time.UTC, nil
	case strings.EqualFold(tz, "Local"):
		return time.Local, nil
	}
	if m := importOffsetPattern.FindStringSubmatch(tz); m != nil {
		h, _ := strconv.Atoi(m[2])
		mins, _ := strconv.Atoi(m[3])
		if h <= 14 && mins < 60 {
			secs := h*3600 + mins*60
			if m[1] == "-" {
				secs = -secs
			}
			return time.FixedZone(tz, secs), nil
		}
	}
	loc, err := time.LoadLocation(tz)
	if err != nil {
		return nil, validationError("--timezone %q is not a time zone", tz).
			WithHint("Use UTC, an IANA name such as America/New_York, or an offset such as -05:00 (the zone the network's export writes its dates in).")
	}
	return loc, nil
}

// conversionImportKey is a row's Idempotency-Key: the same row always gets the same key, and a
// row that differs in any sent field gets another.
func conversionImportKey(r conversionImportRow) string {
	convTime := ""
	if r.ConvTime != 0 {
		convTime = strconv.FormatInt(r.ConvTime, 10)
	}
	sum := sha256.Sum256([]byte("p202 conversion import v1\n" +
		strconv.FormatInt(r.ClickID, 10) + "\n" +
		strconv.Quote(r.TransactionID) + "\n" +
		strconv.Quote(r.Payout) + "\n" +
		convTime))
	return "conv-import-v1-" + hex.EncodeToString(sum[:20])
}

// planConversionImport reads every record into a row: invalid, duplicate_in_file, or ready.
func planConversionImport(t *importTable, cols importColumns, layout string, loc *time.Location) []conversionImportRow {
	rows := make([]conversionImportRow, 0, len(t.records))
	firstTx := map[string]int{}   // click + transaction id (+ sign) -> row
	firstClick := map[int64]int{} // click -> first ready row
	for _, rec := range t.records {
		cell := func(role importRole) (importCell, bool) {
			i := cols[role.key].index
			if i < 0 {
				return importCell{}, false
			}
			if i >= len(rec.cells) {
				return importCell{}, true
			}
			return rec.cells[i], true
		}
		r := conversionImportRow{Row: rec.row, Status: importReady}
		var reasons []string

		sub, _ := cell(importSubidRole)
		r.Subid = strings.TrimSpace(sub.value)
		switch id, ok := parseImportClickID(r.Subid); {
		case sub.bad != "":
			reasons = append(reasons, "subid is "+sub.bad+", not a string or number")
		case r.Subid == "":
			reasons = append(reasons, "subid is empty")
		case !ok:
			reasons = append(reasons, fmt.Sprintf("subid %q is not a Prosper202 click id (digits only, no leading zero)", r.Subid))
		default:
			r.ClickID = id
		}

		if c, has := cell(importPayoutRole); has {
			v := strings.TrimSpace(c.value)
			switch units, canon, ok := parseImportAmount(v); {
			case c.bad != "":
				reasons = append(reasons, "payout is "+c.bad+", not a number")
			case v == "":
			case !ok:
				reasons = append(reasons, fmt.Sprintf("payout %q is not a number", v))
			default:
				r.Payout, r.payoutUnits, r.hasPayout = canon, units, true
			}
		}

		if c, has := cell(importTxidRole); has {
			v := strings.TrimSpace(c.value)
			switch {
			case c.bad != "":
				reasons = append(reasons, "transaction id is "+c.bad+", not a string or number")
			case len(v) > 255:
				reasons = append(reasons, fmt.Sprintf("transaction id is %d bytes; the ledger keeps at most 255", len(v)))
			default:
				r.TransactionID = v
			}
		}

		if c, has := cell(importTimeRole); has {
			v := strings.TrimSpace(c.value)
			switch {
			case c.bad != "":
				reasons = append(reasons, "time is "+c.bad+", not a date or timestamp")
			case v == "":
			default:
				ts, why := parseImportTime(v, layout, loc)
				if why != "" {
					reasons = append(reasons, why)
				}
				r.ConvTime = ts
			}
		}

		if r.hasPayout && r.payoutUnits < 0 && r.TransactionID == "" {
			reasons = append(reasons, "a negative payout needs the transaction id of the sale it reverses")
		}
		if len(reasons) > 0 {
			r.Status, r.Reason = importInvalid, strings.Join(reasons, "; ")
			rows = append(rows, r)
			continue
		}

		if r.TransactionID != "" {
			key := fmt.Sprintf("%d\x00%s\x00%t", r.ClickID, r.TransactionID, r.hasPayout && r.payoutUnits < 0)
			if first, ok := firstTx[key]; ok {
				r.Status, r.Reason = importDuplicateInFile, fmt.Sprintf("same subid and transaction id as row %d", first)
			} else {
				firstTx[key] = r.Row
			}
		} else if first, ok := firstClick[r.ClickID]; ok {
			r.Status = importDuplicateInFile
			r.Reason = fmt.Sprintf("row %d already converts click %d; a row without a transaction id converts its click only once, as the postback does", first, r.ClickID)
		}
		if r.Status == importReady {
			if _, ok := firstClick[r.ClickID]; !ok {
				firstClick[r.ClickID] = r.Row
			}
			r.IdempotencyKey = conversionImportKey(r)
		}
		rows = append(rows, r)
	}
	return rows
}

// clickLedgerAnswer is the part of GET /clicks/{id}/conversions the import reads.
type clickLedgerAnswer struct {
	Data []struct {
		ConvID         int64   `json:"conv_id"`
		Amount         string  `json:"amount"`
		Deleted        bool    `json:"deleted"`
		TransactionID  *string `json:"transaction_id"`
		ReversesConvID *int64  `json:"reverses_conv_id"`
	} `json:"data"`
	Click struct {
		Lead bool `json:"lead"`
	} `json:"click"`
}

// isClickNotFound matches the 404 ClicksController and ConversionsController give for a click
// the account does not have (an unknown route answers a bare "Not found").
func isClickNotFound(err error) bool {
	var apiErr *api.APIError
	return errors.As(err, &apiErr) && apiErr.Status == 404 && strings.HasPrefix(strings.ToLower(apiErr.Message), "click not found")
}

// abortsImport reports errors that every later request would repeat (a refused key, no connection).
func abortsImport(err error) bool {
	cat := api.ErrorCategory(err)
	return cat == "auth" || cat == "network"
}

// oneLine flattens an error for a table cell.
func oneLine(err error) string {
	return strings.Join(strings.Fields(err.Error()), " ")
}

// checkImportClicks reads each distinct click's conversions once (read-only), marks rows the
// ledger already answers, and returns every conversion id each click had before the import.
func checkImportClicks(c *api.Client, rows []conversionImportRow) (map[int64]map[int64]bool, error) {
	var order []int64
	byClick := map[int64][]int{}
	for i, r := range rows {
		if r.Status != importReady {
			continue
		}
		if _, ok := byClick[r.ClickID]; !ok {
			order = append(order, r.ClickID)
		}
		byClick[r.ClickID] = append(byClick[r.ClickID], i)
	}
	before := map[int64]map[int64]bool{}
	mark := func(click int64, status, reason string) {
		for _, i := range byClick[click] {
			rows[i].Status, rows[i].Reason = status, reason
		}
	}
	for _, click := range order {
		data, err := c.Get(fmt.Sprintf("clicks/%d/conversions", click), nil)
		if err != nil {
			var apiErr *api.APIError
			switch {
			case isClickNotFound(err):
				mark(click, importClickNotFound, fmt.Sprintf("no click %d in this account", click))
				continue
			case errors.As(err, &apiErr) && apiErr.Status == 404:
				return nil, withHint(fmt.Errorf("checking click %d: the server has no GET /clicks/{id}/conversions: %w", click, err),
					"Upgrade the server to 1.9.76 or later (`p202 system version` shows it). The import reads each click's conversions before writing, so that a re-run cannot record a sale twice; nothing was written.")
			case abortsImport(err):
				return nil, fmt.Errorf("checking click %d (row %d) before writing anything: %w", click, rows[byClick[click][0]].Row, err)
			}
			mark(click, importFailed, "could not read the click's conversions: "+oneLine(err))
			continue
		}
		var ans clickLedgerAnswer
		if err := json.Unmarshal(data, &ans); err != nil {
			mark(click, importFailed, fmt.Sprintf("unreadable answer from GET /clicks/%d/conversions: %v", click, err))
			continue
		}
		seen := map[int64]bool{}
		for _, conv := range ans.Data {
			seen[conv.ConvID] = true
		}
		before[click] = seen
		for _, i := range byClick[click] {
			resolveImportRow(&rows[i], ans)
		}
	}
	return before, nil
}

// resolveImportRow marks a row duplicate when the click's ledger already holds it: the same
// transaction id, or any conversion for an id-less row. A negative row matches a reversal of
// that id or a negative row with it (what a reversal with no sale on file is recorded as), since
// the server would reverse that row again.
func resolveImportRow(r *conversionImportRow, ans clickLedgerAnswer) {
	if r.TransactionID == "" {
		if ans.Click.Lead {
			r.Status = importDuplicate
			r.Reason = fmt.Sprintf("click %d already converted; a row without a transaction id converts its click only once, as the postback does", r.ClickID)
		}
		return
	}
	reversal := r.hasPayout && r.payoutUnits < 0
	for _, conv := range ans.Data {
		if conv.TransactionID == nil || *conv.TransactionID != r.TransactionID {
			continue
		}
		negative := conv.ReversesConvID != nil || strings.HasPrefix(strings.TrimSpace(conv.Amount), "-")
		if reversal != negative {
			continue
		}
		r.Status, r.ConvID = importDuplicate, conv.ConvID
		switch {
		case reversal:
			r.Reason = fmt.Sprintf("conversion %d already reverses this transaction id", conv.ConvID)
		case conv.Deleted:
			r.Reason = fmt.Sprintf("conversion %d had this transaction id and was deleted; the ledger keeps its key, so it is not recorded again", conv.ConvID)
		default:
			r.Reason = fmt.Sprintf("already recorded as conversion %d (same transaction id)", conv.ConvID)
		}
		return
	}
}

// sendConversionImport posts every ready row, sales before negative rows so a reversal finds its
// sale whatever the file's order. It stops at an error every later row would repeat and returns
// that row's number (0 when it did not stop).
func sendConversionImport(c *api.Client, rows []conversionImportRow, before map[int64]map[int64]bool) int {
	var order []int
	for _, negatives := range []bool{false, true} {
		for i, r := range rows {
			if r.Status == importReady && (r.hasPayout && r.payoutUnits < 0) == negatives {
				order = append(order, i)
			}
		}
	}
	for _, i := range order {
		r := &rows[i]
		body := map[string]interface{}{"click_id": r.ClickID}
		if r.TransactionID != "" {
			body["transaction_id"] = r.TransactionID
		}
		if r.hasPayout {
			body["payout"] = r.Payout
		}
		if r.ConvTime != 0 {
			body["conv_time"] = r.ConvTime
		}
		data, err := c.PostIdempotent("conversions", body, r.IdempotencyKey)
		if err != nil {
			if isClickNotFound(err) {
				r.Status, r.Reason = importClickNotFound, fmt.Sprintf("no click %d in this account", r.ClickID)
				continue
			}
			r.Status, r.Reason = importFailed, oneLine(err)
			if abortsImport(err) {
				for j := range rows {
					if rows[j].Status == importReady {
						rows[j].Status = importFailed
						rows[j].Reason = fmt.Sprintf("not sent: the import stopped at row %d (%s error)", r.Row, api.ErrorCategory(err))
					}
				}
				return r.Row
			}
			continue
		}
		if cid, ok := stagedChangeID(data); ok {
			r.Status, r.ChangeID = importStaged, cid
			continue
		}
		var resp struct {
			Data   map[string]interface{} `json:"data"`
			Replay bool                   `json:"idempotent_replay"`
		}
		convID := 0
		ok := json.Unmarshal(data, &resp) == nil
		if ok {
			convID, ok = extractIntField(resp.Data, "conv_id")
		}
		if !ok {
			r.Status = importFailed
			r.Reason = "the server's answer names no conversion id; the conversion may be recorded, so re-run the same command to find out"
			continue
		}
		r.ConvID = int64(convID)
		switch {
		case resp.Replay:
			r.Status = importDuplicate
			r.Reason = fmt.Sprintf("already recorded as conversion %d by an earlier run (its Idempotency-Key was replayed)", convID)
		case before[r.ClickID][r.ConvID]:
			r.Status = importDuplicate
			r.Reason = fmt.Sprintf("the server matched conversion %d, already recorded", convID)
		default:
			r.Status = importCreated
			if units, canon, ok := parseImportAmount(fmt.Sprint(resp.Data["click_payout"])); ok {
				r.RecordedPayout, r.payoutUnits, r.hasPayout = canon, units, true
			}
			if before[r.ClickID] == nil {
				before[r.ClickID] = map[int64]bool{}
			}
			before[r.ClickID][r.ConvID] = true // a later row the server matches to it is a duplicate
		}
	}
	return 0
}

func countImportStatuses(rows []conversionImportRow) map[string]int {
	counts := map[string]int{}
	for _, s := range importStatusOrder {
		counts[s] = 0
	}
	for _, r := range rows {
		counts[r.Status]++
	}
	return counts
}

// describeImportCounts lists the non-zero counts in a fixed order ("3 created, 1 duplicate").
func describeImportCounts(counts map[string]int) string {
	var parts []string
	for _, s := range importStatusOrder {
		if counts[s] > 0 {
			parts = append(parts, fmt.Sprintf("%d %s", counts[s], s))
		}
	}
	if len(parts) == 0 {
		return "nothing"
	}
	return strings.Join(parts, ", ")
}

// importPayoutTotal sums the payouts of rows with status (recorded ones for created rows).
func importPayoutTotal(rows []conversionImportRow, status string) (string, int) {
	var units int64
	without := 0
	for _, r := range rows {
		if r.Status != status {
			continue
		}
		if r.hasPayout {
			units += r.payoutUnits
		} else {
			without++
		}
	}
	return formatImportUnits(units), without
}

func renderConversionImport(rows []conversionImportRow, meta map[string]interface{}) error {
	if rows == nil {
		rows = []conversionImportRow{}
	}
	data, err := json.Marshal(map[string]interface{}{"data": rows, "meta": meta})
	if err != nil {
		return fmt.Errorf("encoding the import rows: %w", err)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = conversionImportColumns
	}
	output.RenderWith(data, opts)
	return nil
}

func describeImportColumns(cols importColumns) (string, map[string]interface{}) {
	var parts []string
	meta := map[string]interface{}{}
	for _, role := range importRoles {
		ch := cols[role.key]
		if ch.index < 0 {
			parts = append(parts, role.label+"=(none)")
			meta[role.key] = nil
			continue
		}
		parts = append(parts, fmt.Sprintf("%s=%q (%s)", role.label, ch.header, ch.via))
		meta[role.key] = ch.header
	}
	return strings.Join(parts, ", "), meta
}

func runConversionImport(cmd *cobra.Command, args []string) (retErr error) {
	done := metrics.Timer("conversion-import", "conversions")
	defer func() { done(retErr == nil, errString(retErr)) }()

	// Every flag and the whole file are checked before a client is built.
	overrides := map[string]string{}
	for _, role := range importRoles {
		v, _ := cmd.Flags().GetString(role.flag)
		overrides[role.key] = strings.TrimSpace(v)
	}
	tz, _ := cmd.Flags().GetString("timezone")
	loc, err := parseImportTimezone(tz)
	if err != nil {
		return err
	}
	layout, _ := cmd.Flags().GetString("time-format")
	layout = strings.TrimSpace(layout)
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	checkClicks, _ := cmd.Flags().GetBool("check-clicks")
	force, _ := cmd.Flags().GetBool("force")

	table, err := readConversionImportFile(args[0])
	if err != nil {
		return err
	}
	cols, err := chooseImportColumns(table.headers, overrides)
	if err != nil {
		return err
	}
	if layout != "" && cols[importTimeRole.key].index < 0 {
		return validationError("--time-format was given but the file has no time column").
			WithHint("Name the column with --time-column <header>, or drop --time-format.")
	}
	rows := planConversionImport(table, cols, layout, loc)
	colText, colMeta := describeImportColumns(cols)
	output.Success("Columns: %s.", colText)
	meta := map[string]interface{}{"format": table.format, "columns": colMeta, "dry_run": dryRun}

	summarize := func() map[string]int {
		counts := countImportStatuses(rows)
		summary := map[string]interface{}{"rows": len(rows)}
		for s, n := range counts {
			summary[s] = n
		}
		if dryRun {
			total, without := importPayoutTotal(rows, importReady)
			summary["ready_payout"], summary["ready_without_payout"] = total, without
		} else {
			total, _ := importPayoutTotal(rows, importCreated)
			summary["payout_imported"] = total
		}
		meta["summary"] = summary
		return counts
	}

	if dryRun && !checkClicks {
		counts := summarize()
		if err := renderConversionImport(rows, meta); err != nil {
			return err
		}
		total, without := importPayoutTotal(rows, importReady)
		output.Success("Dry run: %d row(s): %s; the ready rows carry %s in payouts (%d at the campaign's default payout). Clicks were not checked (add --check-clicks) and nothing was written; drop --dry-run to import.",
			len(rows), describeImportCounts(counts), total, without)
		return nil
	}

	counts := countImportStatuses(rows)
	if !dryRun && counts[importReady] == 0 {
		first := rows[0]
		return validationError("none of the %d row(s) can be imported (row %d: %s)", len(rows), first.Row, first.Reason).
			WithHint("Run the same command with --dry-run to see every row's reason. If the subid column is the wrong one (it was %q), choose another with --subid-column <header>.", cols[importSubidRole.key].header)
	}

	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	before, err := checkImportClicks(c, rows)
	if err != nil {
		return err
	}

	if dryRun {
		counts := summarize()
		if err := renderConversionImport(rows, meta); err != nil {
			return err
		}
		output.Success("Dry run: %d row(s): %s. Nothing was written; drop --dry-run to import the ready rows.", len(rows), describeImportCounts(counts))
		if counts[importFailed] > 0 {
			return partialFailureError("could not check the click of %d row(s)", counts[importFailed]).
				WithHint("Rows with status failed carry the server's answer; fix it and re-run the dry run.")
		}
		return nil
	}

	ready := countImportStatuses(rows)[importReady]
	if ready > 0 && !force && !api.StagedMode() {
		total, without := importPayoutTotal(rows, importReady)
		fmt.Fprintf(os.Stderr, "%d conversion(s) will be recorded: %s in payouts from the file, %d at the campaign's default payout. %s. `--dry-run --check-clicks` lists every row.\n",
			ready, total, without, describeImportCounts(countImportStatuses(rows)))
		if !confirmPrompt("Import %d conversion(s)?", ready) {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	stoppedAt := 0
	if ready > 0 {
		stoppedAt = sendConversionImport(c, rows, before)
	}

	counts = summarize()
	if err := renderConversionImport(rows, meta); err != nil {
		return err
	}
	if counts[importStaged] > 0 {
		output.Success("Staged %d conversion(s) of %d row(s) (%s); apply them with `p202 change apply <change_id>`.", counts[importStaged], len(rows), describeImportCounts(counts))
	} else {
		total, _ := importPayoutTotal(rows, importCreated)
		output.Success("Imported %d of %d row(s) (%s); %s recorded in payouts.", counts[importCreated], len(rows), describeImportCounts(counts), total)
	}
	if counts[importFailed] == 0 {
		return nil
	}
	retry := "Rows with status failed carry the server's error. Fix the cause and re-run the same command: rows already recorded come back as duplicate (each row keeps its Idempotency-Key and transaction id, and each click's conversions are read first), so only the failures are sent again."
	if stoppedAt > 0 {
		retry = fmt.Sprintf("The import stopped at row %d, so no later row was sent. Check the connection and key with `p202 config test`, then re-run the same command: rows already recorded come back as duplicate, so only the rest are sent.", stoppedAt)
	}
	return partialFailureError("failed to record %d of %d row(s)", counts[importFailed], len(rows)).WithHint("%s", retry)
}

func newConversionImportCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "import <file>",
		Short: "Record a network's conversion export (CSV or JSON) against its clicks, safely re-runnable",
		Long: "Reads a network's conversion export — a CSV file with a header row, or a JSON array of\n" +
			"objects — and records each line as a conversion on the click its subid names.\n\n" +
			"Columns (auto-detected from the header, case and punctuation ignored; the flags override):\n" +
			"  subid           required: subid, sub_id, aff_sub, sub1, click_id, clickid, s2 (--subid-column)\n" +
			"  payout          optional: payout, commission, amount, revenue (--payout-column); $ and\n" +
			"                  thousands separators are dropped; absent = the campaign's default payout\n" +
			"  transaction id  optional: transaction_id, order_id, txid (--txid-column)\n" +
			"  time            optional: date, time, conversion_date, created_at (--time-column); unix\n" +
			"                  seconds or ms, or 2026-02-03[ 14:05[:00]] in --timezone (default UTC),\n" +
			"                  RFC 3339, or any Go layout given with --time-format; absent = now\n" +
			"More than one candidate header for a column is refused: name the one to use.\n\n" +
			"The subid is read as the postback (gpb.php) reads it: the click id, digits only, no\n" +
			"leading zero. Rows are marked invalid (with the reason) or duplicate_in_file (the same\n" +
			"click and transaction id as an earlier row; without a transaction id a click converts\n" +
			"once, as the postback does). A negative payout with a transaction id reverses that sale;\n" +
			"negative rows are sent after the sales, so a newest-first report reverses the right one.\n\n" +
			"--dry-run shows that plan without contacting the server; add --check-clicks to also read\n" +
			"each click's conversions (GET /clicks/{id}/conversions, read-only, once per click).\n" +
			"Without --dry-run the clicks are always read first, then you confirm (--force skips;\n" +
			"--staged records proposals instead), then each ready row is sent as POST /conversions\n" +
			"with an Idempotency-Key derived from its click, transaction id, payout and time.\n\n" +
			"Statuses: created, duplicate (the click already has it: same transaction id, or any\n" +
			"conversion for an id-less row, or the key was replayed), click_not_found, failed (with\n" +
			"the server's message), staged, invalid, duplicate_in_file (ready in a dry run). Re-running\n" +
			"the same file is safe: recorded rows come back as duplicate. Exit 5 if any row failed;\n" +
			"re-running the same command then sends only the rows not yet recorded.",
		Example: "  p202 conversion import a2hosting-feb.csv --dry-run\n" +
			"  p202 conversion import a2hosting-feb.csv --dry-run --check-clicks\n" +
			"  p202 conversion import export.csv --subid-column 'Sub ID 2' --time-column 'Sale Date' --timezone America/New_York\n" +
			"  p202 conversion import conversions.json --force --json",
		Args: func(cmd *cobra.Command, args []string) error {
			if len(args) != 1 {
				return validationError("conversion import takes one file, got %d argument(s)", len(args)).
					WithHint("Pass the network's export: `p202 conversion import <file.csv|file.json> --dry-run`.")
			}
			return nil
		},
		RunE: runConversionImport,
	}
	cmd.Flags().String("subid-column", "", "Header of the column holding the Prosper202 subid (default: auto-detect)")
	cmd.Flags().String("payout-column", "", "Header of the payout column (default: auto-detect; with no payout column the campaign's default payout applies)")
	cmd.Flags().String("txid-column", "", "Header of the transaction id column (default: auto-detect)")
	cmd.Flags().String("time-column", "", "Header of the conversion time column (default: auto-detect; with no time column the server records now)")
	cmd.Flags().String("time-format", "", "Go time layout of the time column, e.g. '01/02/2006 15:04' (default: unix or ISO dates)")
	cmd.Flags().String("timezone", "UTC", "Zone of times written without one: UTC, an IANA name, or an offset like -05:00")
	cmd.Flags().Bool("dry-run", false, "Show the plan and write nothing (no request unless --check-clicks)")
	cmd.Flags().Bool("check-clicks", false, "With --dry-run, also read each click's conversions to show click_not_found and duplicate rows")
	cmd.Flags().BoolP("force", "f", false, "Skip the confirmation prompt")
	for _, f := range []string{"subid-column", "payout-column", "txid-column", "time-column"} {
		emptyHint(cmd, f, "Omit --"+f+" to auto-detect the column, or pass its header exactly as the file's first line writes it.")
	}
	emptyHint(cmd, "time-format", "Omit --time-format to read unix timestamps and ISO dates, or pass a Go layout such as '01/02/2006 15:04'.")
	emptyHint(cmd, "timezone", "Omit --timezone for UTC, or pass an IANA name (America/New_York) or an offset (-05:00).")
	return cmd
}

func init() {
	conversionCmd.AddCommand(newConversionImportCmd())
}
