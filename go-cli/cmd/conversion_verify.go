package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/config"

	"github.com/spf13/cobra"
)

// trackingBaseURL returns the scheme://host of the active profile for hitting
// the (non-API) redirect and postback endpoints.
func trackingBaseURL() (string, error) {
	prof, _, err := config.LoadProfileWithName(profileName)
	if err != nil {
		return "", err
	}
	if prof.URL == "" {
		return "", fmt.Errorf("%w. Run: p202 config set-url <url>", config.ErrNoURL)
	}
	return strings.TrimRight(prof.URL, "/"), nil
}

// conversionPostbackURLCmd is in setup_code.go: it prints what the API's
// GET /conversions/postback-code builds, on the install's tracking domain.

var conversionSimulateCmd = &cobra.Command{
	Use:   "simulate",
	Short: "Prove the click -> subid -> postback -> conversion loop works for a tracker",
	Long: "Fires a synthetic click through the tracker, reads back the subid, fires the\n" +
		"real S2S postback (gpb.php), and reports whether it was accepted — so you can\n" +
		"validate the loop before pointing a network's postback at this instance.\n\n" +
		"The subid is read from the field the campaign's offer URL puts [[subid]] in\n" +
		"(clickref for Awin, aff_sub for TUNE, /pubref: for Partnerize, ...), and an\n" +
		"offer URL without [[subid]] fails. For a redirector, a landing page, or a\n" +
		"campaign the key cannot read, the networks' usual SubID fields are read\n" +
		"instead, and every one that carries an id must carry the same one.",
	RunE: func(cmd *cobra.Command, args []string) error {
		trackerID, _ := cmd.Flags().GetString("tracker")
		if trackerID == "" {
			return validationError("--tracker is required (the internal tracker_id from `tracker list`)")
		}
		payout, _ := cmd.Flags().GetString("payout")
		if payout == "" {
			payout = "1.00"
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		base, err := trackingBaseURL()
		if err != nil {
			return err
		}

		// 1. Resolve the tracker link.
		urlData, err := c.Get("trackers/"+trackerID+"/url", nil)
		if err != nil {
			return err
		}
		var resp struct {
			Data struct {
				DirectURL string `json:"direct_url"`
			} `json:"data"`
		}
		if json.Unmarshal(urlData, &resp) != nil || resp.Data.DirectURL == "" {
			return validationError("tracker has no resolvable link").WithHint("Check the tracker with `p202 tracker get <id>`; it needs a landing page or redirect URL before it can be verified.")
		}
		link := resp.Data.DirectURL
		if !strings.HasPrefix(link, "http") {
			link = scheme(base) + "://" + link
		}

		// 2. Fire a click and capture the subid from the redirect.
		clickRes, err := probeClient().Get(withLinkParam(link, "t202kw", "simulate"))
		if err != nil {
			return withHint(fmt.Errorf("click request failed: %w", err), "The tracking link itself could not be fetched; check that the configured URL is reachable from here and that the tracker is active.")
		}
		loc := clickRes.Header.Get("Location")
		clickRes.Body.Close()
		offers := trackerOffersFor(c, trackerID)
		var match subidMatch
		if offers.decides {
			match, err = subidFromCampaign(loc, offers.urls)
		} else {
			match, err = subidFromNetworkFields(loc)
		}
		if err != nil {
			return withHint(err, "%s", subidHint(err, offers))
		}
		subid := match.ID
		source := "campaign URL"
		if !match.FromCampaign {
			source = "network SubID fields (" + offers.why + ")"
		}

		// 3. Fire the real S2S postback (gpb.php).
		pbURL := fmt.Sprintf("%s/tracking202/static/gpb.php?amount=%s&subid=%s", base, payout, subid)
		pbRes, err := (&http.Client{Timeout: 15 * time.Second}).Get(pbURL)
		if err != nil {
			return withHint(fmt.Errorf("postback request failed: %w", err), "The postback URL could not be fetched; check the server URL and that the postback endpoint (tracking202/static/gpb.php) is reachable.")
		}
		body, _ := io.ReadAll(pbRes.Body)
		pbRes.Body.Close()

		pass := pbRes.StatusCode >= 200 && pbRes.StatusCode < 300
		rows := []map[string]interface{}{{
			"tracker_id":      trackerID,
			"subid":           subid,
			"subid_field":     match.Field,
			"subid_source":    source,
			"postback_status": fmt.Sprintf("%d", pbRes.StatusCode),
			"result":          map[bool]string{true: "PASS", false: "FAIL"}[pass],
			"detail":          strings.TrimSpace(string(body)),
		}}
		render(rowsToJSON(rows))
		if !pass {
			return partialFailureError("postback returned HTTP %d — conversions from this network would not record", pbRes.StatusCode)
		}
		return nil
	},
}

// trackerOffers is what a tracker's own record says about where its
// redirect goes: when decides, to one of urls; otherwise why not.
type trackerOffers struct {
	campaignID string
	urls       []offerURL
	decides    bool
	why        string
	unreadable bool // why: a read failed, rather than a tracker with no campaign
	redirector bool
}

// trackerOffersFor reads the tracker and its campaign. Only a tracker that
// sends the click straight to a campaign it can read lets that campaign's
// URLs decide which field is [[subid]]; a redirector's rules, a landing page,
// or a failed read leave the redirect to the network fields, and why says so.
func trackerOffersFor(c *api.Client, trackerID string) trackerOffers {
	data, err := c.Get("trackers/"+trackerID, nil)
	if err != nil {
		return trackerOffers{why: "tracker " + trackerID + " could not be read: " + err.Error(), unreadable: true}
	}
	var tr struct {
		Data struct {
			AffCampaignID json.Number `json:"aff_campaign_id"`
			RotatorID     json.Number `json:"rotator_id"`
			LandingPageID json.Number `json:"landing_page_id"`
		} `json:"data"`
	}
	if err := json.Unmarshal(data, &tr); err != nil {
		return trackerOffers{why: "tracker " + trackerID + " could not be read: " + err.Error(), unreadable: true}
	}
	id := tr.Data.AffCampaignID.String()
	switch {
	case positiveNumber(tr.Data.RotatorID):
		return trackerOffers{campaignID: id, why: "the tracker is a redirector, whose rules choose the campaign", redirector: true}
	case positiveNumber(tr.Data.LandingPageID):
		return trackerOffers{campaignID: id, why: "the tracker sends the click to a landing page first"}
	case !positiveNumber(tr.Data.AffCampaignID):
		return trackerOffers{why: "the tracker names no campaign"}
	}
	data, err = c.Get("campaigns/"+id, nil)
	if err != nil {
		return trackerOffers{campaignID: id, why: "campaign " + id + " could not be read: " + err.Error(), unreadable: true}
	}
	var camp struct {
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &camp); err != nil {
		return trackerOffers{campaignID: id, why: "campaign " + id + " could not be read: " + err.Error(), unreadable: true}
	}
	// dl.php takes slots 2-5 only when the campaign rotates (rotateTrackerUrl).
	slots := campaignURLFields[:1]
	if rotate := fmt.Sprint(camp.Data["aff_campaign_rotate"]); rotate == "1" || rotate == "true" {
		slots = campaignURLFields
	}
	out := trackerOffers{campaignID: id, decides: true}
	for _, f := range slots {
		if s, ok := camp.Data[f].(string); ok && s != "" {
			out.urls = append(out.urls, offerURL{slot: f, url: s})
		}
	}
	return out
}

func positiveNumber(n json.Number) bool {
	v, err := n.Int64()
	return err == nil && v > 0
}

func subidHint(err error, offers trackerOffers) string {
	page := "https://prosper.tracking202.com/documentation/tools-and-resources/common-network-subid-parameters"
	var se *subidError
	if !errors.As(err, &se) {
		return "Open `p202 tracker get <id>` and confirm the landing page/redirect is configured."
	}
	id := offers.campaignID
	if id == "" {
		id = "<id>"
	}
	flag := "--aff-campaign-url"
	if se.slot.slot != "" {
		flag = se.slot.slotFlag()
	}
	update := "`p202 campaign update " + id + " " + flag + " <url>`"
	switch se.kind {
	case subidNoClick:
		return "The tracker could not record the click; check the server with `p202 system health`."
	case subidNotBare:
		return "Make the network's SubID field exactly [[subid]], with nothing else in it (see `p202 campaign get " + id + "`), then " + update + "."
	case subidNoToken:
		return "Put [[subid]] in the network's SubID field of the offer URL with " + update + "; the fields are listed in " + page + "."
	case subidAmbiguous:
		switch {
		case offers.unreadable:
			return "The campaign URL, which says which field is [[subid]], could not be read; use a key that can read trackers and campaigns."
		case offers.redirector:
			return "Simulate a tracker made for the destination campaign, whose URL says which field is [[subid]]: `p202 tracker create-with-url --aff-campaign-id <id>`."
		}
		return "Leave [[subid]] the only click id in the network's SubID fields (" + page + ")."
	}
	if offers.decides {
		return "The tracking link did not redirect to the campaign's offer URL: check `p202 tracker get <id>` (cloaking, landing page) and `p202 campaign get " + id + "`."
	}
	return "The tracking link did not redirect with a subid: open `p202 tracker get <id>` and confirm the landing page/redirect is configured and BlazerCache is current."
}

func scheme(base string) string {
	if strings.HasPrefix(base, "https") {
		return "https"
	}
	return "http"
}

func init() {
	conversionSimulateCmd.Flags().String("tracker", "", "Internal tracker_id to simulate a conversion through")
	conversionSimulateCmd.Flags().String("payout", "1.00", "Conversion payout amount to post")
	conversionCmd.AddCommand(conversionSimulateCmd, conversionPostbackURLCmd)
}
