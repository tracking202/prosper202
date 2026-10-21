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
		"offer URL without [[subid]] fails, as does a redirect that more than one\n" +
		"rotated offer URL fits with a different id. For a redirector, a landing\n" +
		"page, or a campaign the key cannot read, the networks' usual SubID fields\n" +
		"are read instead, and every one that carries an id must carry the same one.",
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
		// Read before the click, so a failed read records no click.
		offers, err := trackerOffersFor(c, trackerID)
		if err != nil {
			return err
		}

		// 2. Fire a click and capture the subid from the redirect.
		clickRes, err := probeClient().Get(withLinkParam(link, "t202kw", "simulate"))
		if err != nil {
			return withHint(fmt.Errorf("click request failed: %w", err), "The tracking link itself could not be fetched; check that the configured URL is reachable from here and that the tracker is active.")
		}
		loc := clickRes.Header.Get("Location")
		clickRes.Body.Close()
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
// or a read the key is refused (403, 404) leave the redirect to the network
// fields, and why says so. Any other failed read, or a record without the
// fields that decide, is an error: it says nothing about the redirect.
func trackerOffersFor(c *api.Client, trackerID string) (trackerOffers, error) {
	tr, refused, err := readRecord(c, "tracker", trackerID)
	if err != nil {
		return trackerOffers{}, err
	}
	if refused != "" {
		return trackerOffers{why: refused, unreadable: true}, nil
	}
	var ids [3]int64
	for i, key := range []string{"aff_campaign_id", "rotator_id", "landing_page_id"} {
		if ids[i], err = recordInt(tr, key); err != nil {
			return trackerOffers{}, malformedRecord("tracker", trackerID, err)
		}
	}
	campaignID, rotatorID, landingPageID := ids[0], ids[1], ids[2]
	id := fmt.Sprint(campaignID)
	switch {
	case rotatorID > 0:
		return trackerOffers{campaignID: id, why: "the tracker is a redirector, whose rules choose the campaign", redirector: true}, nil
	case landingPageID > 0:
		return trackerOffers{campaignID: id, why: "the tracker sends the click to a landing page first"}, nil
	case campaignID <= 0:
		return trackerOffers{why: "the tracker names no campaign"}, nil
	}
	camp, refused, err := readRecord(c, "campaign", id)
	if err != nil {
		return trackerOffers{}, err
	}
	if refused != "" {
		return trackerOffers{campaignID: id, why: refused, unreadable: true}, nil
	}
	// dl.php takes slots 2-5 only when the campaign rotates, and only the
	// ones PHP reads as true (rotateTrackerUrl).
	rotate, err := recordInt(camp, "aff_campaign_rotate")
	if err != nil {
		return trackerOffers{}, malformedRecord("campaign", id, err)
	}
	slots := campaignURLFields[:1]
	if rotate != 0 {
		slots = campaignURLFields
	}
	out := trackerOffers{campaignID: id, decides: true}
	for _, f := range slots {
		raw, ok := camp[f]
		if !ok {
			return trackerOffers{}, malformedRecord("campaign", id, fmt.Errorf("it has no %s", f))
		}
		var s *string
		if err := json.Unmarshal(raw, &s); err != nil {
			return trackerOffers{}, malformedRecord("campaign", id, fmt.Errorf("%s is not text: %w", f, err))
		}
		if s != nil && *s != "" && *s != "0" {
			out.urls = append(out.urls, offerURL{slot: f, url: *s})
		}
	}
	return out, nil
}

// readRecord GETs a tracker's or a campaign's data. refused says why when
// the key may not read it (403, 404); any other failure is err.
func readRecord(c *api.Client, kind, id string) (rec map[string]json.RawMessage, refused string, err error) {
	what := kind + " " + id
	data, err := c.Get(kind+"s/"+id, nil)
	if err != nil {
		var ae *api.APIError
		if errors.As(err, &ae) && (ae.Status == http.StatusForbidden || ae.Status == http.StatusNotFound) {
			return nil, what + " could not be read: " + err.Error(), nil
		}
		return nil, "", fmt.Errorf("reading %s, which says where the click is sent: %w", what, err)
	}
	var body struct {
		Data map[string]json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(data, &body); err != nil {
		return nil, "", malformedRecord(kind, id, err)
	}
	if body.Data == nil {
		return nil, "", malformedRecord(kind, id, errors.New("it has no data"))
	}
	return body.Data, "", nil
}

// recordInt reads a whole-number field the record must have.
func recordInt(rec map[string]json.RawMessage, key string) (int64, error) {
	raw, ok := rec[key]
	if !ok {
		return 0, fmt.Errorf("it has no %s", key)
	}
	var n json.Number
	if err := json.Unmarshal(raw, &n); err != nil {
		return 0, fmt.Errorf("%s is not a number: %w", key, err)
	}
	v, err := n.Int64()
	if err != nil {
		return 0, fmt.Errorf("%s is not a whole number (%s)", key, raw)
	}
	return v, nil
}

func malformedRecord(kind, id string, err error) error {
	return &CLIError{Category: "server", ExitCode: ExitServer,
		Message: fmt.Sprintf("the server's answer for %s %s is not one it sends (%v)", kind, id, err),
		Hint:    fmt.Sprintf("Check the server with `p202 system health`, and see what it sends with `p202 %s get %s --json`.", kind, id)}
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
		case len(se.fits) > 0:
			var flags, noSubid []string
			for _, o := range se.fits {
				update := "`p202 campaign update " + id + " " + o.slotFlag() + " <url>`"
				if fields, ok := templateSubidFields(o.url); !ok || len(fields) == 0 {
					noSubid = append(noSubid, update)
				}
				flags = append(flags, update)
			}
			if len(noSubid) > 0 {
				return "Put [[subid]] in the network's SubID field of each offer URL without one, with " + strings.Join(noSubid, " or ") + "; the fields are listed in " + page + "."
			}
			return "The campaign rotates its offer URLs in turn, so running the simulation again may send the click to a URL whose redirect no other URL fits. To settle it for every click, make the URLs differ outside their [[tokens]] (see `p202 campaign get " + id + "`) with " + strings.Join(flags, " or ") + "."
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
