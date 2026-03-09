-- Forecast Events Table Migration
-- Creates the 202_forecast_events table for storing holidays, promotions,
-- anomalies, and other calendar events that affect forecasting accuracy.

CREATE TABLE IF NOT EXISTS `202_forecast_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `recurrence` enum('none','monthly','yearly','custom') NOT NULL DEFAULT 'none',
  `impact_type` enum('boost','suppress','neutral') NOT NULL DEFAULT 'neutral',
  `expected_impact_pct` decimal(8,2) DEFAULT NULL,
  `lead_days` int(11) NOT NULL DEFAULT 0,
  `lag_days` int(11) NOT NULL DEFAULT 0,
  `tags` varchar(500) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `user_date` (`user_id`, `event_date`),
  KEY `user_name` (`user_id`, `event_name`),
  KEY `user_recurrence` (`user_id`, `recurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Calendar events for forecast adjustment (holidays, promos, anomalies)';

-- Seed US federal holidays for 2024-2027 as default events.
-- Users can delete these or add their own. Recurring yearly holidays use
-- recurrence='yearly'. Variable-date holidays (Thanksgiving, MLK Day, etc.)
-- use recurrence='custom' with each occurrence stored as its own row.

-- Fixed-date yearly holidays (one row each, recurrence='yearly')
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'New Year''s Day', '2025-01-01', NULL, 'yearly', 'suppress', 1, 1, 'us-holidays', 'US federal holiday', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Independence Day', '2025-07-04', NULL, 'yearly', 'suppress', 1, 1, 'us-holidays', 'US federal holiday', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Veterans Day', '2025-11-11', NULL, 'yearly', 'suppress', 0, 0, 'us-holidays', 'US federal holiday', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Christmas Day', '2025-12-25', NULL, 'yearly', 'suppress', 7, 2, 'us-holidays,retail', 'US federal holiday — significant e-commerce impact', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Juneteenth', '2025-06-19', NULL, 'yearly', 'neutral', 0, 0, 'us-holidays', 'US federal holiday', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Variable-date holidays: each year's occurrence stored individually (recurrence='custom').
-- Users add future years as dates are known.

-- Martin Luther King Jr. Day (3rd Monday of January)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Martin Luther King Jr. Day', '2025-01-20', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of January', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Martin Luther King Jr. Day', '2026-01-19', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of January', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Martin Luther King Jr. Day', '2027-01-18', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of January', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Presidents' Day (3rd Monday of February)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Presidents'' Day', '2025-02-17', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of February', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Presidents'' Day', '2026-02-16', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of February', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Presidents'' Day', '2027-02-15', NULL, 'custom', 'suppress', 0, 0, 'us-holidays', '3rd Monday of February', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Memorial Day (last Monday of May)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Memorial Day', '2025-05-26', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', 'Last Monday of May', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Memorial Day', '2026-05-25', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', 'Last Monday of May', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Memorial Day', '2027-05-31', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', 'Last Monday of May', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Labor Day (1st Monday of September)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Labor Day', '2025-09-01', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', '1st Monday of September', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Labor Day', '2026-09-07', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', '1st Monday of September', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Labor Day', '2027-09-06', NULL, 'custom', 'suppress', 1, 0, 'us-holidays', '1st Monday of September', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Columbus Day (2nd Monday of October)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Columbus Day', '2025-10-13', NULL, 'custom', 'neutral', 0, 0, 'us-holidays', '2nd Monday of October', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Columbus Day', '2026-10-12', NULL, 'custom', 'neutral', 0, 0, 'us-holidays', '2nd Monday of October', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Columbus Day', '2027-10-11', NULL, 'custom', 'neutral', 0, 0, 'us-holidays', '2nd Monday of October', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Thanksgiving (4th Thursday of November)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Thanksgiving', '2025-11-27', NULL, 'custom', 'suppress', 3, 0, 'us-holidays,retail', '4th Thursday of November', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Thanksgiving', '2026-11-26', NULL, 'custom', 'suppress', 3, 0, 'us-holidays,retail', '4th Thursday of November', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Thanksgiving', '2027-11-25', NULL, 'custom', 'suppress', 3, 0, 'us-holidays,retail', '4th Thursday of November', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Black Friday (day after Thanksgiving)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Black Friday', '2025-11-28', NULL, 'custom', 'boost', 200.00, 14, 3, 'us-holidays,retail', 'Major e-commerce event — day after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Black Friday', '2026-11-27', NULL, 'custom', 'boost', 200.00, 14, 3, 'us-holidays,retail', 'Major e-commerce event — day after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Black Friday', '2027-11-26', NULL, 'custom', 'boost', 200.00, 14, 3, 'us-holidays,retail', 'Major e-commerce event — day after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Cyber Monday (Monday after Thanksgiving)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Cyber Monday', '2025-12-01', NULL, 'custom', 'boost', 150.00, 0, 2, 'us-holidays,retail', 'Monday after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Cyber Monday', '2026-11-30', NULL, 'custom', 'boost', 150.00, 0, 2, 'us-holidays,retail', 'Monday after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `expected_impact_pct`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Cyber Monday', '2027-11-29', NULL, 'custom', 'boost', 150.00, 0, 2, 'us-holidays,retail', 'Monday after Thanksgiving', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;

-- Valentine's Day (fixed date, retail-relevant)
INSERT INTO `202_forecast_events`
  (`user_id`, `event_name`, `event_date`, `end_date`, `recurrence`, `impact_type`, `lead_days`, `lag_days`, `tags`, `notes`, `created_at`, `updated_at`)
SELECT `user_id`, 'Valentine''s Day', '2025-02-14', NULL, 'yearly', 'boost', 7, 0, 'retail', 'Significant for dating and gift verticals', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `202_users` WHERE `user_id` > 0;
