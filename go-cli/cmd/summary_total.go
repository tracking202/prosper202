package cmd

import (
	"encoding/json"
	"fmt"
	"math"
	"math/big"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// The TOTAL row of `report summary` and `dashboard` across profiles is what
// GET /reports/summary (ReportsController::summary) would answer for every
// profile's traffic at once. Its counts and money are sums; each ratio is
// computed again from those sums, by the server's formula and to the
// decimals the server answers it in. The ratios used to be summed too:
// profiles at ROI 200% and 0% made a TOTAL of 200%, not 100%.
// TestSummaryTotalFollowsTheServersSQL holds these lists to the server's SQL.

// summaryCounts are the summary's counts, added across profiles.
var summaryCounts = []string{"total_clicks", "total_click_throughs", "total_leads"}

// summaryMoney are its amounts, added only across profiles whose servers
// state one currency.
var summaryMoney = []string{"total_income", "total_cost", "total_net"}

// summaryRatio is one of the summary's ratios: CASE WHEN SUM(den) > 0 THEN
// num / den (* 100 when percent) ELSE 0.
type summaryRatio struct {
	field, num, den string
	percent         bool
}

var summaryRatios = []summaryRatio{
	{"epc", "total_income", "total_clicks", false},
	{"avg_cpc", "total_cost", "total_clicks", false},
	{"conv_rate", "total_leads", "total_click_throughs", true},
	{"roi", "total_net", "total_cost", true},
	{"cpa", "total_cost", "total_leads", false},
}

// summaryMoneyScale is the decimals of a sum of 202_dataengine's income or
// cost (DECIMAL(n,5)); its counts are whole numbers.
const summaryMoneyScale = 5

// summaryCurrencyField is the summary's statement of the currency its money
// is in (from 1.9.79; older servers do not say).
const summaryCurrencyField = "currency"

var (
	summaryDecimal  = regexp.MustCompile(`^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]{1,3})?$`)
	summaryCurrency = regexp.MustCompile(`^[A-Z]{3}$`)
)

// summaryTotal is the TOTAL row and what it leaves out.
type summaryTotal struct {
	row map[string]interface{}
	// notTotalled is every field a profile's row has that the TOTAL leaves
	// out, with why.
	notTotalled map[string]string
	// currencies is each profile's stated currency, nil where its server
	// does not state one.
	currencies map[string]interface{}
}

func pow10(n int) *big.Int {
	return new(big.Int).Exp(big.NewInt(10), big.NewInt(int64(n)), nil)
}

// roundHalfAway is n / d rounded half away from zero, for d > 0.
func roundHalfAway(n, d *big.Int) *big.Int {
	q, r := new(big.Int).QuoRem(n, d, new(big.Int))
	if new(big.Int).Mul(new(big.Int).Abs(r), big.NewInt(2)).Cmp(d) >= 0 {
		if n.Sign() < 0 {
			q.Sub(q, big.NewInt(1))
		} else {
			q.Add(q, big.NewInt(1))
		}
	}
	return q
}

// mysqlDivisionDecimals is how many decimals MySQL and MariaDB keep of a / b
// for DECIMAL operands with aScale and bScale decimals, and how many the
// answer is sent with (strings/decimal.cc do_div_mod; measured on MySQL 8.0
// and MariaDB 10.11). Each operand's decimals are rounded up to whole 9-digit
// words, div_precision_increment less what that rounding added is added, and
// the quotient is truncated at the sum rounded up to a word: 9 decimals for
// epc, avg_cpc, cpa and conv_rate, 18 for roi. The answer has aScale +
// div_precision_increment decimals, rounded half away from zero when the row
// is sent. So 1/6*100 is 16.6667 (0.166666666 times 100) and 2.00000/7*100 is
// 28.571428500, not 28.571428571. div_precision_increment is the default, 4.
func mysqlDivisionDecimals(aScale, bScale int) (kept, sent int) {
	const word, increment = 9, 4
	up := func(n int) int { return (n + word - 1) / word * word }
	fa, fb := up(aScale), up(bScale)
	extra := increment - (fa - aScale) - (fb - bScale)
	if extra < 0 {
		extra = 0
	}
	return up(fa + fb + extra), aScale + increment
}

// mysqlQuotient is a / b (times mul) as the server answers it, for b > 0.
func mysqlQuotient(a, b *big.Rat, aScale, bScale int, mul int64) *big.Rat {
	kept, sent := mysqlDivisionDecimals(aScale, bScale)
	n := new(big.Int).Mul(a.Num(), b.Denom())
	n.Mul(n, pow10(kept))
	d := new(big.Int).Mul(a.Denom(), b.Num())
	q := new(big.Int).Quo(n, d) // truncated toward zero, as the server's long division
	q.Mul(q, big.NewInt(mul))
	if sent >= kept {
		return new(big.Rat).SetFrac(q, pow10(kept))
	}
	return new(big.Rat).SetFrac(roundHalfAway(q, pow10(kept-sent)), pow10(sent))
}

// summaryValue reads one profile's count or amount exactly: a JSON number
// (read with UseNumber), a numeric string (servers before 1.9.77 sent
// MySQL's strings) or null (their sum over no rows, which the server now
// answers as 0).
func summaryValue(v interface{}) (*big.Rat, error) {
	var text string
	switch val := v.(type) {
	case nil:
		return new(big.Rat), nil
	case json.Number:
		text = string(val)
	case string:
		text = strings.TrimSpace(val)
	case float64:
		if math.IsNaN(val) || math.IsInf(val, 0) {
			return nil, fmt.Errorf("not a finite number: %v", val)
		}
		text = strconv.FormatFloat(val, 'g', -1, 64)
	case int:
		text = strconv.Itoa(val)
	default:
		return nil, fmt.Errorf("not a number: %v", v)
	}
	if !summaryDecimal.MatchString(text) {
		return nil, fmt.Errorf("not a number: %q", text)
	}
	r, ok := new(big.Rat).SetString(text)
	if !ok {
		return nil, fmt.Errorf("not a number: %q", text)
	}
	if f, _ := r.Float64(); math.IsInf(f, 0) {
		return nil, fmt.Errorf("not a finite number: %s", text)
	}
	return r, nil
}

// checkSummaryRow refuses a profile's answer whose count or amount is not a
// number, naming the field, so the profile is reported as failed rather
// than read as 0.
func checkSummaryRow(row map[string]interface{}) error {
	for _, field := range append(append([]string{}, summaryCounts...), summaryMoney...) {
		v, ok := row[field]
		if !ok {
			continue
		}
		r, err := summaryValue(v)
		if err != nil {
			return fmt.Errorf("its %s is %w", field, err)
		}
		if isCount(field) && !r.IsInt() {
			return fmt.Errorf("its %s is not a whole number: %v", field, v)
		}
	}
	return nil
}

func isCount(field string) bool {
	for _, c := range summaryCounts {
		if c == field {
			return true
		}
	}
	return false
}

// profileCurrency is the currency a profile's answer states, or "".
func profileCurrency(row map[string]interface{}) string {
	s, ok := row[summaryCurrencyField].(string)
	if !ok {
		return ""
	}
	s = strings.ToUpper(strings.TrimSpace(s))
	if !summaryCurrency.MatchString(s) {
		return ""
	}
	return s
}

// totalSummaries builds the TOTAL row from each profile's summary.
//
// A field the TOTAL does not know (a newer server's) is left out, not
// summed: whether it is a count, an amount in the account's currency or a
// ratio cannot be told from its value, and summing a ratio is the defect
// this replaced. Each profile's row still carries it, and notTotalled names
// it, so it is shown, never totalled wrong; totalling it is a change here
// that TestSummaryTotalFollowsTheServersSQL asks for when the server adds one.
func totalSummaries(rows map[string]map[string]interface{}) (summaryTotal, error) {
	names := make([]string, 0, len(rows))
	carried := map[string][]string{} // field -> the profiles whose rows have it
	for name, row := range rows {
		names = append(names, name)
		for key := range row {
			carried[key] = append(carried[key], name)
		}
	}
	sort.Strings(names)

	out := summaryTotal{row: map[string]interface{}{}, notTotalled: map[string]string{}, currencies: map[string]interface{}{}}
	stated := map[string]bool{}
	unstated := false
	for _, name := range names {
		c := profileCurrency(rows[name])
		if c == "" {
			out.currencies[name] = nil
			unstated = true
			continue
		}
		out.currencies[name] = c
		stated[c] = true
	}
	// One profile adds nothing to anything, whatever it states.
	oneCurrency := len(names) == 1 || (!unstated && len(stated) == 1)
	if oneCurrency && len(stated) == 1 {
		for c := range stated {
			out.row[summaryCurrencyField] = c
		}
	}
	currencyReason := ""
	if !oneCurrency {
		parts := make([]string, 0, len(names))
		for _, name := range names {
			if c, _ := out.currencies[name].(string); c != "" {
				parts = append(parts, name+": "+c)
			} else {
				parts = append(parts, name+": not stated")
			}
		}
		currencyReason = fmt.Sprintf("the profiles' money is in different currencies (%s), and amounts in different currencies are not added", strings.Join(parts, ", "))
		if unstated {
			currencyReason = fmt.Sprintf("the profiles' money is not known to be in one currency (%s), and amounts in different currencies are not added; "+
				"a server before 1.9.79 does not state its currency, so upgrade it to total its money", strings.Join(parts, ", "))
		}
	}

	sums := map[string]*big.Rat{}
	missing := func(field string) []string {
		var lack []string
		for _, name := range names {
			if _, ok := rows[name][field]; !ok {
				lack = append(lack, name)
			}
		}
		return lack
	}
	sumField := func(field string, money bool) error {
		if len(carried[field]) == 0 {
			return nil
		}
		if money && !oneCurrency {
			out.notTotalled[field] = currencyReason
			return nil
		}
		if lack := missing(field); len(lack) > 0 {
			out.notTotalled[field] = fmt.Sprintf("%s's answer does not have it", strings.Join(lack, ", "))
			return nil
		}
		total := new(big.Rat)
		for _, name := range names {
			r, err := summaryValue(rows[name][field])
			if err == nil && !money && !r.IsInt() {
				err = fmt.Errorf("not a whole number: %v", rows[name][field])
			}
			if err != nil {
				return fmt.Errorf("profile %s field %s: %w", name, field, err)
			}
			total.Add(total, r)
		}
		if money {
			f, _ := total.Float64()
			if math.IsInf(f, 0) {
				return withHint(fmt.Errorf("field %s: the sum across profiles is not a finite number", field),
					"Read each server separately with `p202 report summary --profile <name> --json`.")
			}
			out.row[field] = f
		} else {
			out.row[field] = json.Number(total.Num().String())
		}
		sums[field] = total
		return nil
	}
	for _, field := range summaryCounts {
		if err := sumField(field, false); err != nil {
			return summaryTotal{}, err
		}
	}
	for _, field := range summaryMoney {
		if err := sumField(field, true); err != nil {
			return summaryTotal{}, err
		}
	}

	scale := func(field string) int {
		if isCount(field) {
			return 0
		}
		return summaryMoneyScale
	}
	for _, ratio := range summaryRatios {
		if len(carried[ratio.field]) == 0 {
			continue
		}
		num, den := sums[ratio.num], sums[ratio.den]
		if num == nil || den == nil {
			input := ratio.num
			if num != nil {
				input = ratio.den
			}
			switch reason, left := out.notTotalled[input]; {
			case left && reason == currencyReason:
				out.notTotalled[ratio.field] = currencyReason
			case left:
				out.notTotalled[ratio.field] = fmt.Sprintf("it is computed from %s, which the TOTAL leaves out", input)
			default:
				out.notTotalled[ratio.field] = fmt.Sprintf("it is computed from %s, which no profile's answer has", input)
			}
			continue
		}
		value := new(big.Rat)
		if den.Sign() > 0 {
			mul := int64(1)
			if ratio.percent {
				mul = 100
			}
			value = mysqlQuotient(num, den, scale(ratio.num), scale(ratio.den), mul)
		}
		f, _ := value.Float64()
		out.row[ratio.field] = f
	}

	known := map[string]bool{summaryCurrencyField: true, "profile": true}
	for _, f := range append(append([]string{}, summaryCounts...), summaryMoney...) {
		known[f] = true
	}
	for _, r := range summaryRatios {
		known[r.field] = true
	}
	for field := range carried {
		if !known[field] {
			out.notTotalled[field] = "this p202 does not know whether it adds up across profiles (the server is newer); each profile's row has it"
		}
	}
	return out, nil
}

// warnings states, one sentence per reason, what the TOTAL leaves out.
func (t summaryTotal) warnings() []string {
	byReason := map[string][]string{}
	for field, reason := range t.notTotalled {
		byReason[reason] = append(byReason[reason], field)
	}
	out := make([]string, 0, len(byReason))
	for reason, fields := range byReason {
		sort.Strings(fields)
		out = append(out, fmt.Sprintf("The TOTAL leaves out %s: %s.", strings.Join(fields, ", "), reason))
	}
	sort.Strings(out)
	return out
}
