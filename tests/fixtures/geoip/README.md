# GeoIP refresh fixtures

- `GeoLite2-City-Test.mmdb` is MaxMind's test database, copied unchanged from
  `test-data/` of [maxmind/MaxMind-DB](https://github.com/maxmind/MaxMind-DB)
  at commit 255b9a8b8a692d70dcaef31dc2ca44fa7ce50ece. That repository is
  licensed under the MIT License or the Apache License 2.0, at your option
  (Copyright (c) 2013 - 2026 MaxMind, Inc.). It holds made-up records for a
  few test networks (`81.2.69.160` is London, GB), not GeoLite data, and its
  `database_type` is `GeoLite2-City`.
- `GeoLite2-City-Test.tar.gz` is that file packed by GNU tar 1.35 the way a
  GeoLite2 download is laid out (`GeoLite2-City_YYYYMMDD/GeoLite2-City.mmdb`),
  so the extractor is tested on a tar it did not write.
- `fake_maxmind.py` stands in for MaxMind's download endpoint over TLS on
  127.0.0.1 (the integration test and `tests/live/geoip-refresh.sh` start it).

Neither file ships in a release (`build/release-manifest.php` keeps only
`tests/fixtures/agent-eval` and `tests/fixtures/app-sdk-contract`).
