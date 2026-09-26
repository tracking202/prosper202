#!/bin/bash
# Seed a spread of postbacks wide enough that every grouping, every signature
# class and the pagination have something real to show.
# --- environment -------------------------------------------------------
# Writes rows and nothing else: no HTTP, no login, so it takes the database
# variables only. analyze-mobile-apps.sh exports those before running this as
# a child, and the browser pass runs it on its own. The names match
# tests/browser (see its README): one instance can serve every pass.
#
# P202_DB must be a SCRATCH database — this TRUNCATEs the app tables,
# and the guard below refuses a name that does not read as disposable, which
# is the protection tests/browser/lib/db.js applies.
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}

# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
# -----------------------------------------------------------------------

set -e
NOW=$(date -u +%s)
DAY=86400
TODAY=$(( NOW / DAY * DAY ))

mysql_q "$DB" -e "TRUNCATE 202_app_postbacks; TRUNCATE 202_app_registrations; TRUNCATE 202_app_skan_encodings;"

mysql_q "$DB" <<SQL
INSERT INTO 202_app_registrations (user_id, platform, app_key, app_name, notes, accept_test_signals, app_token, created_at, updated_at) VALUES
 (1, 'ios', '990077001', 'Acme Puzzle', '', 1, REPEAT('a1', 32), $NOW, $NOW),
 (1, 'ios', '990077002', 'Acme Racer',  '', 0, REPEAT('b2', 32), $NOW, $NOW),
 (1, 'ios', '990077003', 'Acme, Notes "Pro"', '', 0, REPEAT('c3', 32), $NOW, $NOW);

INSERT INTO 202_app_skan_encodings (user_id, registration_id, fine_value, coarse_value, event_name, revenue, created_at, updated_at)
 SELECT 1, r.registration_id, v.fine_value, v.coarse_value, v.event_name, v.revenue, $NOW, $NOW
   FROM (SELECT '990077001' AS app_key, 3 AS fine_value, NULL AS coarse_value, 'purchase' AS event_name, 4.99000 AS revenue
         UNION ALL SELECT '990077001', 5, NULL, 'subscribe', 9.99000
         UNION ALL SELECT '990077002', NULL, 'high', 'big_spender', 25.00000) v
   JOIN 202_app_registrations r ON r.platform = 'ios' AND r.app_key = v.app_key;
SQL

# day, protocol, version, network, app, source, campaign, fine, coarse, seq, type, redownload, win, country, sigstate, trusted
rows=(
 "0 skadnetwork 4.0 acme.skadnetwork 990077001 12 . 3 . 0 download 0 1 US valid 1"
 "0 skadnetwork 4.0 acme.skadnetwork 990077001 12 . 5 . 0 download 0 1 US valid 1"
 "0 skadnetwork 4.0 beta.skadnetwork  990077002 44 . . high 0 download 0 1 GB valid 1"
 "0 adattributionkit 1.0 acme.skadnetwork 990077001 12 . 3 . 0 re-engagement 0 1 US valid 1"
 "1 skadnetwork 4.0 acme.skadnetwork 990077001 12 . . . 0 download 0 0 CA valid 1"
 "1 skadnetwork 4.0 beta.skadnetwork  990077002 44 . . low 0 redownload 1 1 GB invalid 0"
 "1 adattributionkit 1.0 gamma.skadnetwork 990077003 7 . . . 0 download 0 1 DE unverifiable ."
 "2 skadnetwork 3.0 acme.skadnetwork 990077001 12 . 5 . 1 download 0 1 US development 1"
 "2 skadnetwork 4.0 gamma.skadnetwork 990077003 . . . medium 0 download 0 1 . valid 1"
 "3 adattributionkit 1.0 beta.skadnetwork 990077002 44 . . high 0 re-engagement 0 1 GB valid 1"
 "4 skadnetwork 4.0 acme.skadnetwork 990077001 12 . 3 . 0 redownload 1 1 US valid 1"
)
i=0
for r in "${rows[@]}"; do
  set -- $r
  d=$1; proto=$2; ver=$3; net=$4; app=$5; src=$6; camp=$7; fine=$8; coarse=$9; seq=${10}
  type=${11}; redl=${12}; win=${13}; cc=${14}; state=${15}; valid=${16}
  n() { [ "$1" = "." ] && echo NULL || echo "$1"; }
  q() { [ "$1" = "." ] && echo NULL || echo "'$1'"; }
  ts=$(( TODAY - d * DAY + 3600 + i * 97 ))
  i=$((i+1))
  mysql_q "$DB" -e "INSERT INTO 202_app_postbacks
    (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id,
     source_identifier, campaign_id, conversion_value, coarse_conversion_value,
     postback_sequence_index, conversion_type, redownload, did_win, country_code,
     attribution_signature, signature_state, trusted, dedupe_hash, raw_payload,
     remote_ip, created_at)
    VALUES (1, (SELECT registration_id FROM 202_app_registrations WHERE platform = 'ios' AND app_key = '$app'), $ts, '$proto', '$ver', '$net', 'txn-$i', $app,
     $(q $src), $(n $camp), $(n $fine), $(q $coarse),
     $seq, '$type', $redl, $win, $(q $cc),
     'sig-$i', '$state', $(n $valid), SHA1('row-$i'), '{\"seeded\":$i}',
     '198.51.100.$i', $ts);"
done

mysql_q -N "$DB" -e "SELECT CONCAT('seeded postbacks: ', COUNT(*), ', linked to a registration: ', SUM(registration_id IS NOT NULL)) FROM 202_app_postbacks;
  SELECT CONCAT('  ', signature_state, ' = ', COUNT(*), ' (trusted ', IFNULL(trusted, 'NULL'), ')')
    FROM 202_app_postbacks GROUP BY signature_state, trusted;"
