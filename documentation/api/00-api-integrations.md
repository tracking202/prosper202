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
