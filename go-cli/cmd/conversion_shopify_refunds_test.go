package cmd

import (
	"strconv"
	"strings"
	"testing"
)

const shopifyRefundsJSON = `{"data":[{"refund_id":"9100001","order_id":"5550001234567","refunded_at":1793000000,
 "state":"waiting","stale":true,"reason":null,"amount":12.5,"currency":"USD","click_ids":[4821]}],
 "more":false,"transaction_webhook_seen":false}`

func TestShopifyRefundsListShowsEachRefundAndTheMissingWebhook(t *testing.T) {
	_, seen := goalServer(t, 200, shopifyRefundsJSON)
	out, _, err := executeCommand("--table", "--wide", "conversion", "shopify-refunds", "list")
	if err != nil {
		t.Fatalf("conversion shopify-refunds list: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/conversions/shopify-refunds") {
		t.Fatalf("requests = %+v, want one GET /conversions/shopify-refunds", *seen)
	}
	for _, want := range []string{"9100001", "5550001234567", "waiting", "12.5", "Transaction creation webhook has never arrived",
		"shopify-refunds record <refund-id> --amount"} {
		if !strings.Contains(out, want) {
			t.Errorf("output should show %q:\n%s", want, out)
		}
	}
}

func TestShopifyRefundsListSaysWhenNothingWaits(t *testing.T) {
	goalServer(t, 200, `{"data":[],"more":false,"transaction_webhook_seen":true}`)
	out, _, err := executeCommand("conversion", "shopify-refunds", "list")
	if err != nil {
		t.Fatalf("conversion shopify-refunds list: %v", err)
	}
	if !strings.Contains(out, "No Shopify refund is waiting") {
		t.Errorf("an empty list should say so, got:\n%s", out)
	}
}

func TestShopifyRefundsRecordSendsTheAmountAndClickExactly(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"refund_id":"9100001","outcome":"reversed","conversion_id":77,"message":"Reversed."}}`)
	out, _, err := executeCommand("--json", "conversion", "shopify-refunds", "record", "9100001", "--amount", "12.50", "--click-id", "4821")
	if err != nil {
		t.Fatalf("conversion shopify-refunds record: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/conversions/shopify-refunds/9100001/record") {
		t.Fatalf("requests = %+v, want one POST .../9100001/record", *seen)
	}
	body := (*seen)[0].Body
	if body["amount"] != 12.5 || body["click_id"] != float64(4821) || len(body) != 2 {
		t.Errorf("body = %+v, want amount 12.5 and click_id 4821 as numbers", body)
	}
	if !strings.Contains(out, "reversed") {
		t.Errorf("output should carry the outcome:\n%s", out)
	}
}

func TestShopifyRefundsRecordRefusesABadAmountOrIDBeforeSending(t *testing.T) {
	for _, args := range [][]string{
		{"9100001"},
		{"9100001", "--amount", "0"},
		{"9100001", "--amount", "12abc"},
		{"9100001", "--amount", "-5"},
		{"12abc", "--amount", "5"},
		{"9100001", "--amount", "5", "--click-id", "x"},
	} {
		_, seen := goalServer(t, 200, `{}`)
		_, _, err := executeCommand(append([]string{"conversion", "shopify-refunds", "record"}, args...)...)
		if err == nil {
			t.Fatalf("%v should be refused", args)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code = %d, want 1 (validation)", args, code)
		}
		if !strings.Contains(hintFor(err), "shopify-refunds list") {
			t.Errorf("%v: hint = %q, want one naming shopify-refunds list", args, hintFor(err))
		}
		if len(*seen) != 0 {
			t.Errorf("%v: a refused flag should send nothing, sent %+v", args, *seen)
		}
	}
}

func TestShopifyRefundsAnswersCarryTheNextStep(t *testing.T) {
	for status, want := range map[int]string{403: "Update section", 404: "shopify-refunds list", 409: "shopify-refunds close"} {
		goalServer(t, status, `{"error":true,"message":"refused","status":`+strconv.Itoa(status)+`}`)
		_, _, err := executeCommand("conversion", "shopify-refunds", "record", "9100001", "--amount", "5")
		if err == nil {
			t.Fatalf("a %d should be an error", status)
		}
		if !strings.Contains(hintFor(err), want) {
			t.Errorf("%d: hint = %q, want one naming %q", status, hintFor(err), want)
		}
	}
}

func TestShopifyRefundsCloseAsksFirstAndForceSkipsTheQuestion(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"refund_id":"9100001","closed":true}}`)
	// No terminal: the question cannot be answered, and nothing is sent.
	_, _, err := executeCommand("conversion", "shopify-refunds", "close", "9100001")
	if err == nil || len(*seen) != 0 {
		t.Fatalf("close without --force and without an answer should send nothing, err=%v sent=%+v", err, *seen)
	}
	if !strings.Contains(hintFor(err), "--force") {
		t.Errorf("hint = %q, want one naming --force", hintFor(err))
	}
	out, _, err := executeCommand("conversion", "shopify-refunds", "close", "9100001", "--force")
	if err != nil {
		t.Fatalf("close --force: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/conversions/shopify-refunds/9100001/close") {
		t.Fatalf("requests = %+v, want one POST .../9100001/close", *seen)
	}
	if !strings.Contains(out, "closed with nothing recorded") {
		t.Errorf("a close should say what it did, got:\n%s", out)
	}
}
