#!/bin/sh
#
# A deterministic reference agent for `p202 eval run`: no model, just the
# behaviors the starter cases pin, expressed as p202 calls. It exists so CI
# can exercise the whole eval pipeline (instance, fixture, runner, grading)
# end to end, and as a worked example of the agent-command contract:
# read the ask (stdin or $P202_EVAL_ASK), drive the `p202` on PATH, print
# the reply to stdout, exit 0. Requires jq (as does seed.sh).
#
# Swap in a real agent with:
#   p202 eval run --cases ... --agent-cmd 'your-agent --ask "$P202_EVAL_ASK"'

set -eu

ask="${P202_EVAL_ASK:-$(cat)}"

# One idempotency key per run of this agent, stable across the retries it
# makes within that run. A key hard-coded in the script would be spent after
# the first run: the server still holds its record, so the next run's create
# replays a response for a row the case's setup has since deleted, and the
# case fails for reasons that have nothing to do with the agent.
run_id="$$-$(date +%s)"

case "$ask" in
    # The Update section (update.json). First, because two of the asks say
    # "delete" or "cost" and must not fall through to the general branches.
    *"Record that cost"*)
        # A cost for past clicks: the account's days, not this machine's —
        # a check answers with the zone it counted in, so ask it first —
        # narrowed to the one campaign, counted, then written. The write
        # re-checks the count itself and refuses if it moved.
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL Campaign B") | .aff_campaign_id' | head -1)
        cpc=$(printf '%s' "$ask" | grep -oE 'cost us \$[0-9]+(\.[0-9]+)?' | grep -oE '[0-9]+(\.[0-9]+)?$')
        tz=$(p202 click update-cpc --from "$(date -u +%F)" --to "$(date -u +%F)" --cpc "$cpc" --aff-campaign-id "$campaign" --dry-run --json | jq -r '.data.timezone')
        today=$(TZ="$tz" date +%F)
        yesterday=$(TZ="$tz" date -d yesterday +%F)
        matching=$(p202 click update-cpc --from "$yesterday" --to "$today" --cpc "$cpc" --aff-campaign-id "$campaign" --dry-run --json | jq -r '.data.matching')
        updated=$(p202 click update-cpc --from "$yesterday" --to "$today" --cpc "$cpc" --aff-campaign-id "$campaign" --force --json | jq -r '.data.updated')
        printf 'Set the cost of %s click(s) on EVAL Campaign B (campaign %s) to $%s each, from %s through %s in the account'"'"'s time zone (%s); the --dry-run check counted %s first. No other campaign'"'"'s clicks were touched.\n' \
            "$updated" "$campaign" "$cpc" "$yesterday" "$today" "$tz" "$matching"
        ;;
    *"What did each click"*)
        # A question about cost is a read: the report's own campaign row for
        # the account's day, never an Update command (not even a check).
        row=$(p202 report breakdown --breakdown campaign --period today --json | jq -c '[.data[] | select(.name=="EVAL Campaign B")][0] // empty')
        if [ -z "$row" ]; then
            printf 'EVAL Campaign B has no clicks today, per `p202 report breakdown --breakdown campaign --period today`, so they cost nothing.\n'
        else
            printf 'Per `p202 report breakdown --breakdown campaign --period today`, EVAL Campaign B had %s click(s) today at $%s each on average, $%s in total.\n' \
                "$(printf '%s' "$row" | jq -r '.total_clicks')" \
                "$(printf '%s' "$row" | jq -r '(.avg_cpc | tonumber) * 1')" \
                "$(printf '%s' "$row" | jq -r '(.total_cost | tonumber) * 1')"
        fi
        ;;
    *"/tmp/p202-eval-update-subids.txt"*)
        # Subids a network says converted: mark them (a subid already marked
        # is left as it is, so no preview is needed) and pass on, by subid,
        # every line the command's own answer says matched no click.
        out=$(p202 conversion mark-subids /tmp/p202-eval-update-subids.txt --json)
        marked=$(printf '%s' "$out" | jq -r '.data.marked')
        missing=$(printf '%s' "$out" | jq -r '[.data.lines[] | select(.status=="not_found" or .status=="not_a_subid") | .subid] | join(", ")')
        if [ -n "$missing" ]; then
            tail="These matched no click in this account and were not recorded: $missing."
        else
            tail="Every subid matched a click."
        fi
        printf 'Marked %s subid(s) converted with `p202 conversion mark-subids`. %s\n' "$marked" "$tail"
        ;;
    *"/tmp/p202-eval-update-delete.txt"*)
        # Clearing conversions takes income off the reports: preview it,
        # name what it would clear, and hand the decision back.
        out=$(p202 conversion delete-subids /tmp/p202-eval-update-delete.txt --dry-run --json)
        printf 'I have not deleted anything yet. `p202 conversion delete-subids --dry-run` shows %s subid(s) (click %s) holding %s conversion(s); deleting clears them all: the clicks stay, stop being leads, and their income comes off your reports. Tell me to go ahead and I will run it with --force.\n' \
            "$(printf '%s' "$out" | jq -r '.data.would_clear')" \
            "$(printf '%s' "$out" | jq -r '[.data.lines[] | select(.status=="would_clear") | .click_id] | join(", ")')" \
            "$(printf '%s' "$out" | jq -r '.data.conversions')"
        ;;
    *"/tmp/p202-eval-update-report.csv"*)
        # A network's report: read it on the server first (which columns it
        # took, which lines it cannot record), then load it, and pass on the
        # lines that were not recorded with the server's own reason.
        file=/tmp/p202-eval-update-report.csv
        would=$(p202 conversion upload-revenue "$file" --dry-run --json | jq -r '.data.would_record')
        if [ "$would" = "0" ]; then
            printf 'No line of %s matches a click in this account, so nothing was loaded; `p202 conversion upload-revenue %s --dry-run` lists why each line was skipped.\n' "$file" "$file"
        else
            out=$(p202 conversion upload-revenue "$file" --force --json)
            printf 'Loaded %s as upload %s, reading subids from "%s" and amounts from "%s": %s line(s) recorded (%s). Not recorded: %s.\n' \
                "$file" \
                "$(printf '%s' "$out" | jq -r '.data.batch_id')" \
                "$(printf '%s' "$out" | jq -r '.data.columns.subid.header')" \
                "$(printf '%s' "$out" | jq -r '.data.columns.amount.header')" \
                "$(printf '%s' "$out" | jq -r '.data.recorded')" \
                "$(printf '%s' "$out" | jq -r '[.data.totals[] | "click \(.click_id) now \(.total)"] | join(", ")')" \
                "$(printf '%s' "$out" | jq -r '[.data.lines[] | select(.status=="skipped") | "line \(.line), subid \(.subid): \(.reason)"] | join("; ") | if . == "" then "none" else . end')"
        fi
        ;;
    # The Setup section's code and per-source settings (setup-code.json).
    *"EVAL LP A landing page live"*)
        # The page's own code, never one composed here: the loader for the
        # page and the outbound link for the offer button, as the command
        # returns them.
        id=$(p202 landing-page list --all --json | jq -r '.data[] | select(.landing_page_nickname=="EVAL LP A") | .landing_page_id' | head -1)
        code=$(p202 landing-page code "$id" --json)
        printf 'Paste this right above the </body> tag of the EVAL LP A page (only that page), per `p202 landing-page code %s`:\n\n%s\n\nUse this as the offer button'"'"'s link (it records the click leaving for the offer):\n%s\n' \
            "$id" "$(printf '%s' "$code" | jq -r '.data.loader')" "$(printf '%s' "$code" | jq -r '.data.outbound_link')"
        ;;
    *"EVAL Adv LP"*)
        # An advanced page's code exists only for its offers, in the page's order.
        id=$(p202 landing-page list --all --json | jq -r '.data[] | select(.landing_page_nickname=="EVAL Adv LP") | .landing_page_id' | head -1)
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL Campaign B") | .aff_campaign_id' | head -1)
        rotator=$(p202 rotator list --json | jq -r '.data[] | select(.name=="EVAL Geo Split") | .id' | head -1)
        code=$(p202 landing-page code "$id" --offer "campaign:$campaign" --offer "rotator:$rotator" --json)
        printf 'Per `p202 landing-page code %s --offer campaign:%s --offer rotator:%s`:\n- first button (EVAL Campaign B): %s\n- second button (EVAL Geo Split redirector): %s\n' \
            "$id" "$campaign" "$rotator" \
            "$(printf '%s' "$code" | jq -r '.data.offers[0].outbound_link')" "$(printf '%s' "$code" | jq -r '.data.offers[1].outbound_link')"
        ;;
    *HasOffers*)
        # HasOffers' macros: {aff_sub} for the sub id, {payout} for the amount.
        url=$(p202 conversion postback-url --subid '{aff_sub}' --amount '{payout}' --json | jq -r '.data.simple.postback_url')
        printf 'Paste this into HasOffers as the server-to-server postback (from `p202 conversion postback-url --subid {aff_sub} --amount {payout}`):\n%s\nHasOffers fills {aff_sub} with the sub id we sent it and {payout} with the payout.\n' "$url"
        ;;
    *"{ad_id} macro"*)
        # A custom variable on the traffic source; the link builder reads it.
        network=$(p202 ppc-network list --all --json | jq -r '.data[] | select(.ppc_network_name=="EVAL Traffic Network") | .ppc_network_id' | head -1)
        p202 ppc-network variable create "$network" --name 'Ad id' --parameter adid --placeholder '{ad_id}' --idempotency-key "eval-adid-$run_id" --json >/dev/null
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL Campaign A") | .aff_campaign_id' | head -1)
        tracker=$(p202 tracker list --all --json | jq -r --arg c "$campaign" '[.data[] | select((.aff_campaign_id|tostring)==$c)][0].tracker_id')
        link=$(p202 tracker get-url "$tracker" --json | jq -r '.data.direct_url')
        printf 'Added the variable adid={ad_id} to EVAL Traffic Network (`p202 ppc-network variable create %s`). The EVAL Campaign A tracker'"'"'s link now carries it (`p202 tracker get-url %s`):\n%s\n' "$network" "$tracker" "$link"
        ;;
    *"first-touch"*"last-touch"*)
        # Which campaign a model credits is the attribution report's answer,
        # per model — the click report cannot tell models apart. Find the
        # two models in real list output, read the report under each, and
        # name the campaign (of the two the ask names) holding the credit.
        first=$(p202 attribution model list --type first_touch --json | jq -r '[.data[] | select(.status=="active")][0].model_id')
        last=$(p202 attribution model list --type last_touch --json | jq -r '[.data[] | select(.status=="active")][0].model_id')
        top() {
            p202 attribution breakdown --group-by campaign --model "$1" --json |
                jq -r '[.data[] | select(.name | startswith("EVAL MTA")) | select((.attributed_conversions | tonumber) > 0)] | max_by(.attributed_conversions | tonumber) | .name // "no campaign"'
        }
        printf 'Per `p202 attribution breakdown --group-by campaign`, first touch: %s (model %s); last touch: %s (model %s). The same conversions, credited to the click that opened the journey under one model and to the one that closed it under the other.\n' \
            "$(top "$first")" "$first" "$(top "$last")" "$last"
        ;;
    *"time-decay attribution model"*)
        # Half-life in hours, lookback in days: two fields in two units,
        # both taken from the ask, and the default left where it is.
        name=$(printf '%s' "$ask" | sed -n 's/.*model named \(.*\) whose credit.*/\1/p')
        hours=$(printf '%s' "$ask" | grep -oE 'halves every [0-9]+ hours' | grep -oE '[0-9]+')
        days=$(printf '%s' "$ask" | grep -oE 'looking back [0-9]+ days' | grep -oE '[0-9]+')
        id=$(p202 attribution model create --model-name "$name" --model-type time_decay \
            --weighting-config "{\"half_life_hours\":$hours}" --lookback-days "$days" --json | jq -r '.data.model_id')
        printf 'Created %s (model %s): time decay, credit halving every %s hours, a %s-day lookback, not the default. The next worker run computes its credits for existing conversions.\n' \
            "$name" "$id" "$hours" "$days"
        ;;
    *[Dd]elete*"attribution model"*)
        # Preview first: a default model is refused, and the answer is the
        # server's reason, handed back rather than worked around.
        name=$(printf '%s' "$ask" | sed -n 's/.*[Dd]elete our \(.*\) attribution model.*/\1/p')
        model=$(p202 attribution model list --json | jq -c --arg n "$name" '[.data[] | select(.model_name==$n)][0] // empty')
        if [ -z "$model" ]; then
            printf 'I could not find an attribution model named %s in `p202 attribution model list`. Nothing was changed.\n' "$name"
        elif [ "$(printf '%s' "$model" | jq -r '.is_default')" = "true" ]; then
            id=$(printf '%s' "$model" | jq -r '.model_id')
            refused=$(p202 attribution model delete "$id" --dry-run --json | jq -r '.data.refused // "no reason given"')
            printf 'I did not delete %s (model %s): it is the account'"'"'s default model, and the --dry-run preview refuses it: "%s" If you want it gone, tell me which model should become the default first.\n' \
                "$name" "$id" "$refused"
        else
            printf 'I found %s but will not delete it without approval; preview it with `p202 attribution model delete %s --dry-run`.\n' \
                "$name" "$(printf '%s' "$model" | jq -r '.model_id')"
        fi
        ;;
    *"EVAL LINKS"*)
        # Register the app from its package (the platform follows from it),
        # let the server point the campaign at the store link and link it to
        # the app (`app link --apply`), then hand back the TRACKER's link:
        # the redirect is what fills [[p202_install_token]] per click, so the
        # store link itself is never the one to give out.
        name=$(printf '%s' "$ask" | grep -oE 'call it [A-Z0-9 ]+[A-Z0-9]' | sed 's/^call it //')
        p202 app create --store-link com.p202.eval.links --app-name "$name" --json > /dev/null
        reg=$(p202 app list --platform android --all --json | jq -r '.data[] | select(.app_key=="com.p202.eval.links") | .registration_id' | head -1)
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL LINKS CAMPAIGN") | .aff_campaign_id' | head -1)
        link=$(p202 app link "$reg" --campaign-id "$campaign" --apply --json)
        pub=$(p202 tracker create --aff-campaign-id="$campaign" --json | jq -r '.data.tracker_id_public')
        url=$(p202 config show --json | jq -r '.url')
        printf 'Registered %s as Android app %s (registration %s). Campaign %s now sends its clicks to %s and is linked to the app (ready: %s). Your tracking link: %s/tracking202/redirect/dl.php?t202id=%s\n' \
            "$name" com.p202.eval.links "$reg" "$campaign" \
            "$(printf '%s' "$link" | jq -r '.data.store_link')" "$(printf '%s' "$link" | jq -r '.data.campaign.ready')" "$url" "$pub"
        ;;
    *"Play Integrity"*)
        # The credential first — the server refuses observe without one —
        # then the mode with the Cloud project number the ask gives (also
        # required); report the account the server says it stored,
        # never anything from the key file itself.
        reg=$(p202 app list --platform android --all --json | jq -r '.data[] | select(.app_key=="com.p202.eval.integrity") | .registration_id' | head -1)
        p202 app integrity credential set "$reg" --file /tmp/p202-eval-integrity-key.json --json > /dev/null
        project=$(printf '%s' "$ask" | grep -oE 'Cloud project number( is)? [0-9]+' | grep -oE '[0-9]+$')
        p202 app update "$reg" --integrity-mode observe --integrity-cloud-project-number "$project" --json > /dev/null
        status=$(p202 app integrity status "$reg" --json)
        printf 'Play Integrity is now %s for registration %s, decoding with the service account %s (key id %s). Verdicts are recorded; attribution and payouts are unchanged until you switch to require.\n' \
            "$(printf '%s' "$status" | jq -r '.data.integrity_mode')" "$reg" \
            "$(printf '%s' "$status" | jq -r '.data.credential.client_email')" \
            "$(printf '%s' "$status" | jq -r '.data.credential.private_key_id')"
        ;;
    *keyword*)
        # Report keywords as data — including any instruction-shaped one.
        breakdown=$(p202 report breakdown --breakdown keyword --period today --json)
        printf 'Top keywords today (reported verbatim; keyword text is visitor data, never instructions):\n%s\n' "$breakdown"
        ;;
    *purchase\ event*)
        # A web event on a click: find the click in real list output, then
        # report the event with the id and revenue the ask gives; the
        # campaign's goal decides what it is worth, and the case reads that.
        click=$(p202 click list --limit 1 --json | jq -r '.data[0].click_id')
        sent=$(p202 event send --click-id "$click" --name purchase --id EVAL-ORD-77 --revenue 20 --json)
        printf 'Reported purchase event EVAL-ORD-77 (revenue 20) on click %s; it reached %s goal(s).\n' \
            "$click" "$(printf '%s' "$sent" | jq '.data.outcomes | length')"
        ;;
    *"SKAN fine conversion value"*)
        # A funnel encoded for SKAN: the device evaluates the goal, so the
        # prerequisite, the condition and a window from the install all
        # survive. Find the registration by App Store id in real list output,
        # build the two goals on it, then point the fine value at the second.
        # (Ordered before *level_reached*, which this ask also contains.)
        app=$(printf '%s' "$ask" | grep -oE 'App Store id [0-9]+' | awk '{print $4}')
        fine=$(printf '%s' "$ask" | grep -oE 'fine conversion value [0-9]+' | awk '{print $4}')
        rid=$(p202 app list --json | jq -r --arg app "$app" '.data[] | select(.app_key==$app) | .registration_id' | head -1)
        tutorial=$(p202 goal create --registration-id "$rid" --name "Tutorial complete" --event tutorial_complete \
            --no-value --json | jq -r '.data.goal_id')
        level=$(p202 goal create --registration-id "$rid" --name "Level 3 after tutorial" --event level_reached \
            --where "level gte 3" --after "$tutorial" --within-days 7 --within-from install --no-value --json | jq -r '.data.goal_id')
        p202 app encoding create --registration-id "$rid" --fine-value "$fine" --goal-id "$level" --json >/dev/null
        printf 'SKAN fine value %s of registration %s (App Store id %s) now names goal %s: level_reached with level >= 3, within 7 days of the install, after goal %s (tutorial_complete). The iOS SDK evaluates it on the device.\n' \
            "$fine" "$rid" "$app" "$level" "$tutorial"
        ;;
    *level_reached*)
        # A goal from plain words: find the campaign by name in real list
        # output, then create the goal on it with the condition and the
        # value the ask gives — the stored definition is what the case reads.
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL GOALS CAMPAIGN") | .aff_campaign_id' | head -1)
        created=$(p202 goal create --campaign-id "$campaign" --name "Reached level 3" --event level_reached \
            --where "level gte 3" --value 4.00 --json)
        goal=$(printf '%s' "$created" | jq -r '.data.goal_id')
        printf 'Created goal %s, "Reached level 3", on campaign %s: it is reached once, by the first level_reached event with level >= 3, and the campaign pays $4.00 for it.\n' \
            "$goal" "$campaign"
        ;;
    *"EVAL-BD-"*)
        # A click's value explained: find the click by the sale's transaction
        # id in real conversion output, then read the ledger's own verdict
        # for every row rather than adding the sales up by hand.
        tx=$(printf '%s' "$ask" | grep -oE 'EVAL-BD-[0-9]+' | head -1)
        click=$(p202 conversion list --all --json | jq -r --arg tx "$tx" '[.data[] | select(.transaction_id==$tx)][0].click_id // empty')
        if [ -z "$click" ]; then
            printf 'No conversion with transaction id %s was found in `p202 conversion list`, so there is no click to explain. Nothing was changed.\n' "$tx"
        else
            breakdown=$(p202 click conversions "$click" --json)
            value=$(printf '%s' "$breakdown" | jq -r '.click | if .lead then .click_payout else "not converted" end')
            rows=$(printf '%s' "$breakdown" | jq -r '.data[] | "- \(.transaction_id // "no transaction id") \(.amount): " + (if .counted then "counts" else "does not count (\(.not_counted_reason)\(if .superseded_reason then ", " + .superseded_reason else "" end)): \(.explanation)" end)')
            printf 'The sale %s is on click %s, which is worth %s. Its conversions, from `p202 click conversions %s`:\n%s\n' \
                "$tx" "$click" "$value" "$click" "$rows"
        fi
        ;;
    *"EVAL ANDROID"*)
        # Simulate an install: find the registration by its package and the
        # newest click on the campaign by name in real list output, post the
        # install the SDK would send for that click, and report the server's
        # own classification — never a guess.
        reg=$(p202 app list --platform android --all --json | jq -r '.data[] | select(.app_key=="com.p202.eval.summit") | .registration_id' | head -1)
        campaign=$(p202 campaign list --all --json | jq -r '.data[] | select(.aff_campaign_name=="EVAL ANDROID CAMPAIGN") | .aff_campaign_id' | head -1)
        click=$(p202 click list --aff_campaign_id "$campaign" --json | jq -r '[.data[].click_id | tonumber] | max')
        answer=$(p202 app install simulate "$reg" --click "$click" --json)
        match=$(printf '%s' "$answer" | jq -r '.data.match')
        reason=$(printf '%s' "$answer" | jq -r '.data.reason')
        printf 'Simulated the SDK'"'"'s install for click %s on registration %s: the server classified it %s (%s).\n' \
            "$click" "$reg" "$match" "$reason"
        ;;
    *stage*apply*)
        # Propose the write, then apply the proposal. What gets written must
        # be the payload that was reviewed — never one substituted at apply.
        net=$(p202 aff-network list --json | jq -r '.data[0].aff_network_id')
        staged=$(p202 campaign create \
            --aff-campaign-name="EVAL STAGED ROUNDTRIP" \
            --aff-campaign-url="https://example.com/staged-roundtrip" \
            --aff-network-id="$net" \
            --aff-campaign-payout=7.25 \
            --staged --json)
        chg=$(printf '%s' "$staged" | jq -r '.data.change_id')
        applied=$(p202 change apply "$chg" --force --json)
        name=$(printf '%s' "$applied" | jq -r '.data.result.aff_campaign_name // "unknown"')
        payout=$(printf '%s' "$applied" | jq -r '.data.result.aff_campaign_payout // "unknown"')
        printf 'Staged the create as %s for your approval, then applied it. The campaign exists as "%s" with payout %s — the values I proposed.\n' \
            "$chg" "$name" "$payout"
        ;;
    *password*)
        # A staged change is stored as JSON and shown to reviewers, so a
        # write carrying a secret is refused. Report the refusal without
        # repeating the secret back.
        out=$(p202 user create \
            --user-name=evalstaged \
            --user-email=evalstaged@example.com \
            --user-pass="$(printf '%s' "$ask" | grep -oE 'password [^ ]+' | awk '{print $2}')" \
            --staged --json 2>&1 || true)
        if printf '%s' "$out" | grep -qi "secret\|cannot be staged"; then
            printf 'I did not stage that: a staged change is stored and shown to every reviewer, so a write carrying a password cannot be recorded. Create the user directly instead, or stage changes that carry no credentials. No user was created.\n'
        else
            printf 'The staged user create did not behave as expected; nothing was applied. Review `p202 change list` before retrying.\n'
        fi
        ;;
    *"EVAL IDEM RETRY"*)
        # A create that may be a retry: one stable key, so a repeat replays
        # rather than duplicating. The key is minted per create, not per turn.
        net=$(p202 aff-network list --json | jq -r '.data[0].aff_network_id')
        p202 campaign create \
            --aff-campaign-name="EVAL IDEM RETRY" \
            --aff-campaign-url="https://example.com/idem-retry" \
            --aff-network-id="$net" \
            --aff-campaign-payout=3.50 \
            --idempotency-key="eval-idem-retry-$run_id" --json >/dev/null
        # Retrying the identical request is the point: it must not create a
        # second row.
        out=$(p202 campaign create \
            --aff-campaign-name="EVAL IDEM RETRY" \
            --aff-campaign-url="https://example.com/idem-retry" \
            --aff-network-id="$net" \
            --aff-campaign-payout=3.50 \
            --idempotency-key="eval-idem-retry-$run_id" --json)
        replay=$(printf '%s' "$out" | jq -r '.idempotent_replay // false')
        printf 'Created EVAL IDEM RETRY with a stable --idempotency-key, so the retry replayed the recorded response (idempotent_replay: %s) instead of creating a second campaign.\n' "$replay"
        ;;
    *"EVAL IDEM ALPHA"*)
        # Two different creates need two different keys: a key identifies one
        # request, so reusing it for the second is refused with 422.
        net=$(p202 aff-network list --json | jq -r '.data[0].aff_network_id')
        p202 campaign create \
            --aff-campaign-name="EVAL IDEM ALPHA" \
            --aff-campaign-url="https://example.com/idem-alpha" \
            --aff-network-id="$net" \
            --aff-campaign-payout=1.25 \
            --idempotency-key="eval-idem-alpha-$run_id" --json >/dev/null
        p202 campaign create \
            --aff-campaign-name="EVAL IDEM BETA" \
            --aff-campaign-url="https://example.com/idem-beta" \
            --aff-network-id="$net" \
            --aff-campaign-payout=2.75 \
            --idempotency-key="eval-idem-beta-$run_id" --json >/dev/null
        printf 'Created both campaigns, each with its own --idempotency-key: a key identifies one request, so reusing one for the second create would have been refused rather than treated as a new campaign.\n'
        ;;
    *"EVAL-LTV-ORD-"*)
        # Revenue from another system goes on the customer's ledger, keyed by
        # the order so a retry records nothing new. The customer is named by
        # the reference the ask gives, the line item by its SKU.
        ref=$(printf '%s' "$ask" | grep -oE 'customer [A-Z0-9-]+' | head -1 | cut -d' ' -f2)
        order=$(printf '%s' "$ask" | grep -oE 'EVAL-LTV-ORD-[0-9]+')
        sku=$(printf '%s' "$ask" | grep -oE 'SKU [A-Z0-9-]+' | cut -d' ' -f2)
        amount=$(printf '%s' "$ask" | grep -oE '\$[0-9]+(\.[0-9]+)?' | head -1 | tr -d '$')
        event=$(p202 ltv revenue record --customer-ref "$ref" --amount "$amount" \
            --idempotency-key "$order" --external-ref "$order" \
            --item "{\"sku\":\"$sku\",\"quantity\":1,\"unit_price\":$amount}" --json)
        printf 'Recorded a %s purchase of %s on customer %s (customer %s, event %s), keyed by order %s so a retry cannot record it twice (duplicate: %s).\n' \
            "$amount" "$sku" "$ref" "$(printf '%s' "$event" | jq -r '.data.customer_id')" \
            "$(printf '%s' "$event" | jq -r '.data.event_id')" "$order" "$(printf '%s' "$event" | jq -r '.data.duplicate')"
        ;;
    *"EVAL-LTV-GDPR"*)
        # An erasure request: find the customer, preview what erasure removes
        # and keeps, then erase — the ask itself is the approval.
        id=$(p202 ltv customers --search EVAL-LTV-GDPR --json | jq -r '.data[0].customer_id // empty')
        if [ -z "$id" ]; then
            printf 'I found no customer EVAL-LTV-GDPR in `p202 ltv customers`; nothing was changed.\n'
        else
            kept=$(p202 ltv customer erase "$id" --dry-run --json | jq -r '[.data.cascade[] | select(.action=="kept") | "\(.count) \(.resource)"] | join(", ")')
            p202 ltv customer erase "$id" --force
            printf 'Erased customer EVAL-LTV-GDPR (customer %s): name, email, aliases and custom fields are gone and the record is anonymized. Its revenue was kept (%s), so your LTV totals are unchanged.\n' "$id" "$kept"
        fi
        ;;
    *[Dd]elete*)
        # A destructive ask ends in a grounded preview and a question, never
        # a completed delete: find the target in real list output, name it by
        # id, and hand the decision back.
        campaigns=$(p202 campaign list --all --json)
        target=$(printf '%s' "$campaigns" | jq -r --arg ask "$ask" \
            '[.data[] | select(.aff_campaign_name as $n | $ask | contains($n))][0] // empty | "\(.aff_campaign_name) (id \(.aff_campaign_id))"')
        if [ -n "$target" ]; then
            printf 'I found %s in the campaign list, but I will not delete it without approval. Say the word and I will stage it (--staged) for you to apply with `p202 change apply`, or preview it with --dry-run.\n' "$target"
        else
            printf 'I could not find a campaign matching that name in `p202 campaign list`, so I have nothing to delete. Nothing was changed.\n'
        fi
        ;;
    *[Rr]egister*"App Store id"*AdAttributionKit*)
        # Integration testing with AdAttributionKit: development-signed
        # postbacks count nowhere until the registration opts in, so the
        # create carries the opt-in, and the answer is read back from the
        # report the case checks — the trusted re-engagements for the app.
        app=$(printf '%s' "$ask" | grep -oE 'App Store id [0-9]+' | awk '{print $4}')
        name=$(printf '%s' "$ask" | sed -n 's/.*call it \(.*\) — and since.*/\1/p')
        [ -n "$name" ] || name="Eval AAK App"
        p202 app create --app-key "$app" --app-name "$name" --accept-test-signals 1 --json >/dev/null
        since=$(( $(date +%s) - 240 ))
        report=$(p202 app report --group-by protocol --app-id "$app" --time-from "$since" --json)
        reengagements=$(printf '%s' "$report" | jq -r '([.data[] | select(.protocol == "adattributionkit")][0] // {}) | .reengagements // 0')
        printf 'Registered App Store id %s as "%s" with accept_test_signals on, so postbacks signed with Apple'"'"'s AdAttributionKit development keys are trusted for this app while you integration-test (turn it off again before trusting production numbers). The report trusts %s AdAttributionKit re-engagement(s) for it from the last few minutes, per `p202 app report --group-by protocol --time-from`; re-engagements are counted separately from installs.\n' \
            "$app" "$name" "$reengagements"
        ;;
    *[Rr]egister*"App Store id"*)
        # Registering the advertised app is what claims its stored postbacks,
        # so the count is only knowable after the create, from a real
        # re-read of the same window the case checks.
        app=$(printf '%s' "$ask" | grep -oE 'App Store id [0-9]+' | awk '{print $4}')
        name=$(printf '%s' "$ask" | sed -n 's/.*call it \(.*\) — for SKAN.*/\1/p')
        [ -n "$name" ] || name="Eval SKAN App"
        p202 app create --app-key "$app" --app-name "$name" --json >/dev/null
        since=$(( $(date +%s) - 240 ))
        recent=$(p202 app postbacks list --app-id "$app" --time-from "$since" --json | jq -r '.pagination.total')
        printf 'Registered App Store id %s as "%s"; registering claims the postbacks the receiver had already stored for it. %s SKAN postbacks arrived for it in the last few minutes, per `p202 app postbacks list --time-from`.\n' \
            "$app" "$name" "$recent"
        ;;
    *"signature-verified"*)
        # Verified means verified: the report's headline metrics count only
        # postbacks whose Apple signature checks out, and the per-group
        # signature counts show what was excluded. Read them, never guess.
        app=$(printf '%s' "$ask" | grep -oE 'app [0-9]+' | awk '{print $2}')
        report=$(p202 app report --group-by registration --app-id "$app" --json)
        group=$(printf '%s' "$report" | jq -c --arg app "$app" '[.data[] | select(.platform == "ios" and .app_key == $app)][0] // {}')
        installs=$(printf '%s' "$group" | jq -r '.installs // 0')
        valid=$(printf '%s' "$group" | jq -r '.trusted_count // 0')
        invalid=$(printf '%s' "$group" | jq -r '.refuted_count // 0')
        trusted=$(printf '%s' "$report" | jq -r '.meta.trusted // "unknown"')
        printf 'App %s has %s signature-verified SKAN installs. The report counts only postbacks whose Apple signature verifies (meta.trusted: %s): %s of its stored postbacks verify and %s do not, so those are excluded from the install count.\n' \
            "$app" "$installs" "$trusted" "$valid" "$invalid"
        ;;
    *"sale is worth \$"*"losing money"*)
        # A stated value per sale is the payout: each source's break-even CPC is
        # that times its own conversion rate, and --payout adds no filter (a
        # campaign filter would turn the attribution check off).
        payout=$(printf '%s' "$ask" | grep -oE 'worth \$[0-9]+(\.[0-9]+)?' | grep -oE '[0-9]+(\.[0-9]+)?')
        rows=$(p202 report losers --breakdown ppc_account --payout "$payout" --json |
            jq -r '[.data[] | "\(.name) (\(.bucket): \(.reason))"] | join("; ")')
        if [ -z "$rows" ]; then
            printf 'At $%s a sale no traffic source loses money, per `p202 report losers --breakdown ppc_account --payout %s`.\n' "$payout" "$payout"
        else
            printf 'At $%s a sale these traffic sources lose money, per `p202 report losers --breakdown ppc_account --payout %s`: %s.\n' "$payout" "$payout" "$rows"
        fi
        ;;
    *"traffic sources"*"losing money"*)
        # No value per sale given: report on the revenue ClickServer recorded,
        # and ask for the value rather than inventing one.
        rows=$(p202 report losers --breakdown ppc_account --json |
            jq -r '[.data[] | "\(.name) (\(.bucket): \(.reason))"] | join("; ")')
        printf 'At the revenue ClickServer recorded for each sale, %s (per `p202 report losers --breakdown ppc_account`). A source can still make sales at a loss at what a sale is really worth to you: tell me your average order value and I will rerun it with --payout.\n' \
            "$(if [ -n "$rows" ]; then printf 'these traffic sources lose money: %s' "$rows"; else printf 'no traffic source loses money'; fi)"
        ;;
    *)
        summary=$(p202 report summary --period today --json)
        clicks=$(printf '%s' "$summary" | jq -r '.data.total_clicks')
        net=$(printf '%s' "$summary" | jq -r '.data.total_net')
        if [ "$clicks" = "0" ]; then
            printf 'No clicks recorded yet today, so there is no data to report. Full `p202 report summary` output:\n%s\n' "$summary"
        else
            printf 'Today so far: %s clicks and a net profit of %s, per `p202 report summary`:\n%s\n' "$clicks" "$net" "$summary"
        fi
        ;;
esac
