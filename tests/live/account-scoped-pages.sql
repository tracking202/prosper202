-- Account A is user 1 (the installer's), account B is user 2. B's records
-- are named "ZZB ..." with zzb.example URLs; A's records that name one of
-- B's by id are what a legacy write left in installs (the API refuses that
-- write since 229df10). Idempotent: every row has a fixed id.
SET @t = UNIX_TIMESTAMP();

INSERT IGNORE INTO 202_users SET user_id = 2, user_name = 'acctB', user_email = 'b@example.test', user_dash_email = '',
  user_pass = 'x', user_timezone = 'America/New_York', user_time_register = @t, install_hash = '', user_hash = '';
INSERT IGNORE INTO 202_users_pref SET user_id = 2;
UPDATE 202_users_pref SET user_pref_time_predefined = 'today', user_pref_show = 'all', user_pref_limit = 50 WHERE user_id IN (1, 2);

-- B's records.
REPLACE INTO 202_aff_networks SET aff_network_id = 7002, user_id = 2, aff_network_name = 'ZZB Category', aff_network_time = @t;
REPLACE INTO 202_aff_campaigns SET aff_campaign_id = 7002, aff_campaign_id_public = 70020, user_id = 2, aff_network_id = 7002,
  aff_campaign_name = 'ZZB Offer', aff_campaign_url = 'https://zzb.example/offer', aff_campaign_payout = 99, aff_campaign_foreign_payout = 0, aff_campaign_time = @t;
REPLACE INTO 202_ppc_networks SET ppc_network_id = 7002, user_id = 2, ppc_network_name = 'ZZB Source', ppc_network_time = @t;
REPLACE INTO 202_ppc_accounts SET ppc_account_id = 7002, user_id = 2, ppc_network_id = 7002, ppc_account_name = 'ZZB Account', ppc_account_time = @t;
REPLACE INTO 202_landing_pages SET landing_page_id = 7002, landing_page_id_public = 70020, user_id = 2, aff_campaign_id = 7002,
  landing_page_nickname = 'ZZB Page', landing_page_url = 'https://zzb.example/page', landing_page_time = @t;
REPLACE INTO 202_text_ads SET text_ad_id = 7002, user_id = 2, aff_campaign_id = 7002, landing_page_id = 0, text_ad_name = 'ZZB Ad',
  text_ad_headline = 'ZZB headline', text_ad_description = 'ZZB description', text_ad_display_url = 'zzb.example', text_ad_time = @t;
REPLACE INTO 202_rotators SET id = 7002, public_id = 70020, user_id = 2, name = 'ZZB Rotator';
REPLACE INTO 202_rotator_rules SET id = 7002, rotator_id = 7002, rule_name = 'ZZB Rule', status = 1;
REPLACE INTO 202_rotator_rules_redirects SET id = 7002, rule_id = 7002, redirect_url = 'https://zzb.example/redirect', name = 'ZZB Redirect';
REPLACE INTO 202_ppc_network_variables SET ppc_variable_id = 7002, ppc_network_id = 7002, name = 'ZZB Var', parameter = 'zzbv', placeholder = '{zzbv}';
REPLACE INTO 202_custom_variables SET custom_variable_id = 7002, ppc_variable_id = 7002, variable = 'zzb-value';
REPLACE INTO 202_variable_sets2 SET variable_set_id = 7002, variables = '7002';

-- A's own records.
REPLACE INTO 202_aff_networks SET aff_network_id = 7001, user_id = 1, aff_network_name = 'A Category', aff_network_time = @t;
REPLACE INTO 202_aff_campaigns SET aff_campaign_id = 7001, aff_campaign_id_public = 70010, user_id = 1, aff_network_id = 7001,
  aff_campaign_name = 'A Offer', aff_campaign_url = 'https://a.example/offer', aff_campaign_payout = 5, aff_campaign_foreign_payout = 0, aff_campaign_time = @t;
REPLACE INTO 202_ppc_networks SET ppc_network_id = 7001, user_id = 1, ppc_network_name = 'A Source', ppc_network_time = @t;
REPLACE INTO 202_ppc_accounts SET ppc_account_id = 7001, user_id = 1, ppc_network_id = 7001, ppc_account_name = 'A Account', ppc_account_time = @t;
REPLACE INTO 202_landing_pages SET landing_page_id = 7001, landing_page_id_public = 70010, user_id = 1, aff_campaign_id = 7001,
  landing_page_nickname = 'A Page', landing_page_url = 'https://a.example/page', landing_page_time = @t;
REPLACE INTO 202_text_ads SET text_ad_id = 7001, user_id = 1, aff_campaign_id = 7001, landing_page_id = 0, text_ad_name = 'A Ad',
  text_ad_headline = 'A headline', text_ad_description = 'A description', text_ad_display_url = 'a.example', text_ad_time = @t;

-- A's records that name one of B's.
REPLACE INTO 202_aff_campaigns SET aff_campaign_id = 7003, aff_campaign_id_public = 70030, user_id = 1, aff_network_id = 7002,
  aff_campaign_name = 'A Stray Offer', aff_campaign_url = 'https://a.example/stray', aff_campaign_payout = 5, aff_campaign_foreign_payout = 0, aff_campaign_time = @t;
REPLACE INTO 202_ppc_accounts SET ppc_account_id = 7003, user_id = 1, ppc_network_id = 7002, ppc_account_name = 'A Stray Account', ppc_account_time = @t;
REPLACE INTO 202_landing_pages SET landing_page_id = 7003, landing_page_id_public = 70030, user_id = 1, aff_campaign_id = 7002,
  landing_page_nickname = 'A Stray Page', landing_page_url = 'https://a.example/stray-page', landing_page_time = @t;
REPLACE INTO 202_text_ads SET text_ad_id = 7003, user_id = 1, aff_campaign_id = 7002, landing_page_id = 7002, text_ad_name = 'A Stray Ad',
  text_ad_headline = 'A stray headline', text_ad_description = 'A stray description', text_ad_display_url = 'a.example', text_ad_time = @t;
-- An edit (generate_tracking_link.php) replaces a link with a new row under
-- the same public id: start from the seeded two only.
DELETE FROM 202_trackers WHERE user_id = 1 AND tracker_id_public IN (970039, 970049);
REPLACE INTO 202_trackers SET tracker_id = 7003, user_id = 1, tracker_id_public = 970039, aff_campaign_id = 7002, text_ad_id = 7002,
  ppc_account_id = 7002, landing_page_id = 7002, rotator_id = 0, click_cpc = 0.1, click_cloaking = 0, tracker_time = @t;
REPLACE INTO 202_trackers SET tracker_id = 7004, user_id = 1, tracker_id_public = 970049, aff_campaign_id = 7001, text_ad_id = 0,
  ppc_account_id = 7001, landing_page_id = 0, rotator_id = 7002, click_cpc = 0.1, click_cloaking = 0, tracker_time = @t;
REPLACE INTO 202_rotators SET id = 7003, public_id = 70030, user_id = 1, name = 'A Rotator', default_campaign = 7002;
REPLACE INTO 202_rotator_rules SET id = 7003, rotator_id = 7003, rule_name = 'A Rule', status = 1;
REPLACE INTO 202_rotator_rules_redirects SET id = 7003, rule_id = 7003, redirect_campaign = 7002, name = 'Campaign: A redirect', weight = '50';
REPLACE INTO 202_rotator_rules_redirects SET id = 7004, rule_id = 7003, redirect_lp = 7002, name = 'Landing page: A redirect', weight = '50';
UPDATE 202_aff_networks SET dni_network_id = 7002 WHERE aff_network_id = 7001;
REPLACE INTO 202_dni_networks SET id = 7002, user_id = 2, networkId = 'zzb', shortDescription = 'ZZB DNI', favIcon = 'https://zzb.example/dni.ico',
  apiKey = 'zzb-key', name = 'ZZB DNI', type = 'x', time = @t, processed = 1;

-- A's clicks, now, as the old rollup wrote them: the ids each click named.
DELETE FROM 202_clicks WHERE click_id BETWEEN 700101 AND 700104;
DELETE FROM 202_clicks_advance WHERE click_id BETWEEN 700101 AND 700104;
DELETE FROM 202_clicks_record WHERE click_id BETWEEN 700101 AND 700104;
DELETE FROM 202_clicks_rotator WHERE click_id BETWEEN 700101 AND 700104;
DELETE FROM 202_dataengine WHERE click_id BETWEEN 700101 AND 700104;
INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, landing_page_id, click_cpc, click_payout, click_time, rotator_id) VALUES
  (700101, 1, 7001, 7001, 0, 0.10, 5, @t, 0),
  (700102, 1, 7002, 7002, 7002, 0.10, 99, @t, 7002),
  (700103, 1, 7003, 7003, 0, 0.10, 5, @t, 0),
  (700104, 1, 7001, 7001, 0, 0.10, 5, @t, 7003);
INSERT INTO 202_clicks_advance (click_id, text_ad_id, ip_id, country_id, region_id, city_id, platform_id, browser_id, device_id) VALUES
  (700101, 7001, 0, 0, 0, 0, 0, 0, 0), (700102, 7002, 0, 0, 0, 0, 0, 0, 0), (700103, 7003, 0, 0, 0, 0, 0, 0, 0), (700104, 0, 0, 0, 0, 0, 0, 0, 0);
INSERT INTO 202_clicks_record (click_id, click_id_public, click_out) VALUES (700101, 7001011, 1), (700102, 7001021, 1), (700103, 7001031, 1), (700104, 7001041, 1);
-- B's own click today, on its own campaign: no page of A's reads it, and the
-- daily email, addressed to user 1, read it too.
DELETE FROM 202_clicks WHERE click_id = 700201;
DELETE FROM 202_clicks_record WHERE click_id = 700201;
INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, landing_page_id, click_cpc, click_payout, click_time, rotator_id) VALUES
  (700201, 2, 7002, 7002, 0, 0.10, 99, @t, 0);
INSERT INTO 202_clicks_record (click_id, click_id_public, click_out) VALUES (700201, 7002011, 1);
INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id) VALUES (700102, 7002, 7002, 7002), (700104, 7003, 7003, 7003);
INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_network_id, ppc_account_id, aff_network_id, aff_campaign_id, landing_page_id,
  text_ad_id, variable_set_id, rotator_id, rule_id, rule_redirect_id, clicks, click_out, leads, payout, income, cost) VALUES
  (1, 700101, @t, 7001, 7001, 7001, 7001, 0, 7001, '0', 0, 0, 0, 1, 1, 0, 5, 0, 0.10),
  (1, 700102, @t, 7002, 7002, 7002, 7002, 7002, 7002, '7002', 7002, 7002, 7002, 1, 1, 0, 99, 0, 0.10),
  (1, 700103, @t, 7002, 7003, 7002, 7003, 0, 7003, '0', 0, 0, 0, 1, 1, 0, 5, 0, 0.10),
  (1, 700104, @t, 7001, 7001, 7001, 7001, 0, 0, '0', 7003, 7003, 7003, 1, 1, 0, 5, 0, 0.10);
