# Prosper202 ClickServer API

Currently, if you are a developer, you can use the Prosper202 ClickServer API to run reports without a UI and integrate it into your own applications. Below is the documentation available for the API.

## Locating your API Keys

To generate or find your Prosper202 ClickServer API Key, simply log into Prosper, click on My Account > Personal Settings, and scroll down until you find the section labeled Prosper202 App API keys.

## Generating API Keys

You can create multiple API keys. It’s best to create a new App API key for every integration that needs to access the reporting API. This will give you fine grained control over disabling access to an app or integration you no longer need.

## API Authentication

The Prosper202 ClickServer uses a simple token based authentication system. The API keys that you generate are used to authenticate an app and allow the pulling of reports from your system. With that in mind it’s important to keep the tokens secure and limit token use to one per app or integration.

## API Endpoint

The API endpoint for Prosper202 will depend upon your tracking domain, however the general form is as follows: **http://[[your-Prosper202-domain]]/api/v1/**

## Attribution API (Preview)

Prosper202 1.9.56 introduces experimental attribution endpoints under `/api/v2/attribution`. These are RESTful and return JSON.

| Method | Endpoint | Description | Required Permission |
| ------ | -------- | ----------- | ------------------- |
| `GET` | `/api/v2/attribution/models` | List attribution models. Supports `type` query parameter (`last_touch`, `time_decay`, etc.). | `view_attribution_reports` (read) or `manage_attribution_models` (write). |
| `POST` | `/api/v2/attribution/models` | Create a new attribution model. Payload accepts `name`, `type`, optional `weighting_config`, `is_active`, `is_default`. | `manage_attribution_models`. |
| `GET` | `/api/v2/attribution/models/{modelId}/snapshots` | Retrieve aggregated hourly snapshots for a model. Accepts `scope`, `scope_id`, `start_hour`, `end_hour`, optional `limit` (default 500) and `offset` for pagination. | `view_attribution_reports`. |
| `PATCH` | `/api/v2/attribution/models/{modelId}` | Update model fields (name, slug, weighting config, status). | `manage_attribution_models`. |
| `DELETE` | `/api/v2/attribution/models/{modelId}` | Remove a model and associated snapshots/settings. | `manage_attribution_models`. |
| `GET` | `/api/v2/attribution/sandbox` | Run comparison across multiple models (`models` query parameter) within a defined time window and scope. | `manage_attribution_models`. |

All attribution endpoints use the same API key authentication as existing v1 reports. Responses include pagination metadata where applicable. Because the feature is still evolving, treat the endpoints as beta and validate payload structures against the current release.

> **Authentication**: Supply the API key as the `apikey` query parameter, or call the endpoint from an authenticated browser session. Requests without valid credentials return `401` (missing/invalid key) or `403` (insufficient permissions).

## Methods

As of now the only supported method is the reports method

Method Name: reports
Required: Yes

## Arguments

**Argument name:** type (Required) - Specifies the type of report you'd like to run.
The current valid values are as follows

- keywords ­- Keyword report
- ips ­- IP report
- text_ads -­ text ad report
- referers ­- Referrer report
- countries -­ Country report
- cities ­- Cities report
- carriers -­ Carrier and ISP report
- landing_pages -­ Landing page report

**Argument name:** apikey (Required) - API key generated in Prosper202 and used
for authentication.

**Argument name:** date_from (Optional) - Start date for the report you’d like to
run. If left blank the system will default to the start of the current day. A valid date
should be in the following format: mm/dd/yyyy

**Argument name:** date_to (Optional) - End date for the report you’d like to run.
If left blank the system will default to the end of the current day. A valid date should
be in the following format: mm/dd/yyyy

**Argument name:** cid (Optional) - Campaign id to filter your report by. If this is
blank, data from all campaigns will be returned.

**Argument name:** c1,c2,c3,c4 (Optional) - Filters by value stored in the
corresponding c1-4 variable. If this is blank, data from all campaigns will be
returned.

## Example of a validly formatted API call

**http://prosper202.com/api/v1/reports/?type=countries&apikey=6cvyz0ckgpylum2ira502jap6w6ou412&date_from=03/24/2014&date_to=04/27/2014&cid=1&c1=c1­var&c2=c2­var&c3=c3­var&c4=c4­var**
