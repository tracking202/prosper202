package cmd

import (
	"encoding/json"
	"fmt"
)

// maskedFigures reports whether the server masked an answer: a key whose
// user's role lacks access_to_campaign_data reads reports, clicks and
// conversions with the absolute clicks, leads and money null and
// `"masked": true`, as the pages print them as '?'.
func maskedFigures(data []byte) bool {
	var top struct {
		Masked bool `json:"masked"`
	}
	return json.Unmarshal(data, &top) == nil && top.Masked
}

// maskedReportFigures are the report fields a masked answer holds null:
// the server's CampaignFigures::REPORT (TestMaskedReportFiguresAreTheServers
// holds the two lists together). The ratios -- epc, avg_cpc, conv_rate, roi,
// cpa -- are not among them.
var maskedReportFigures = []string{
	"total_clicks", "total_click_throughs", "total_leads", "total_income", "total_cost", "total_net",
}

// errMaskedFigures is the refusal of a command that computes from the
// figures a masked answer hides. Its nulls are hidden values, not zeros:
// summing, ranking or forecasting them would print confident wrong numbers,
// so the command stops and says why.
func errMaskedFigures(command string) *CLIError {
	return &CLIError{
		Category: "auth",
		ExitCode: ExitAuth,
		Message: fmt.Sprintf("%s computes from clicks, leads and money, which this key's role may not see: "+
			"the server hid them (its role lacks the access_to_campaign_data permission)", command),
		Hint: "Use the key of a user whose role has access_to_campaign_data (`p202 whoami` shows this key's roles), " +
			"or have an admin grant one: `p202 user role list`, then `p202 user role assign <user_id> <role_id>`. " +
			"`p202 report summary` and `report breakdown` still show the ratios to this role.",
	}
}

// refuseMaskedFigures returns errMaskedFigures when data is a masked answer.
func refuseMaskedFigures(data []byte, command string) error {
	if maskedFigures(data) {
		return errMaskedFigures(command)
	}
	return nil
}
