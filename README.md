# Prosper202 v1.9.55 ClickServer

Prosper202 provides pay per click affiliate marketers with leading edge self hosted ppc software! The only effect this software will have on your bottom line is a positive one.

# Release Notes

<b>Version 1.9.55</b>

<ul>
<li>New: Support for Chrome Samesite=none and secure requirement </li>
<li>New: PayKickstart Integration for Bot202 Link Assistant and Affiliate IPN for conversion tracking</li>
<li>New: Deferred Pixel Now Supports PurLinks</li>
<li>New: API Access To All Setup Data (Steps 1-8)</li>
<li>Update: Ability to remove all pixels from ppc account</li>
<li>Update: PurLinks on Simple Landing pages can now be updated dynamically via JavaScript call</li>
<li>Update: New Useragent detection list </li>
<li>Update: New Traffic source icons for LinkedIn & Yahoo</li>
<li>Update: Landing page JavaScript uses cache buster to ensure better tracking</li>
<li>Update: Duplicate emails no longer allowed when adding a new user</li>
<li>Fixed: Postbacks and pixels with &currency were displaying wrong</li>
<li>Fixed: Url vars with a . were being replaced with _</li>
<li>Fixed: Manual Triggering Of Deferred Pixel Checker</li>
<li>Fixed: Conversion logs reports show manual and api conversion type</li>
<li>Fixed: Conversion logs filtering by subid or campaign works</li>
<li>Fixed: Conversion logs reports needed 2 clicks to run</li>
<li>Fixed: PHP warning when expected url variables were not used</li>
<li>Fixed: Currency Exchange Works with postbacks</li>
<li>Fixed: Google Ad GCLID export header csv fixed</li>
<li>Fixed: Google Ad GCLID uses conversion time instead of click time</li>
<li>Fixed: Bot202 Link Assistant wasn't working</li>
<li>Fixed: Bot202 Facebook Pixel Assistant wasn't fully installed</li>

</ul>

<b>Version 1.9.54</b>

<ul>
<li>New: Bot202 Facebook Pixel Assistant</li>
<li>Update: More accurate exchange rates</li> 
<li>Update: Deferred Pixel fires on background mobile tabs</li>
<li>Update: Sentinel T.Q.E has improved fraud detection tuning</li>
<li>Fixed: Cron Jobs Don't Overload Servers with lots of data</li>
<li>Fixed: Group overview show correct stats when doing breakdown with c1-c5 and utm variables</li>
<li>Fixed: Deferred Pixel works on external domains</li>
<li>Fixed:  Browsers details were not showing in spy/visitor view</li>
<li>Fixed: Initial Overview screen wasn't showing all data</li>
</ul>

<b>Version 1.9.53</b>

<ul>
<li>Fixed: IPQS api column was not created</li> 
<li>Update: Tracking links automatically use https if secure server detected</li>
<li>Update: Auto upgrade messsage wording</li>
</ul>

<b>Version 1.9.52</b>

<ul>
<li>New: Advanced Deferred Pixel Support</li> 
<li>New: FBCLID value is identified and saved</li>
<li>New: Sentinel T.Q.E Fraudulent Click Detection and Filtering </li>
<li>New: [[FBCLID]] token</li>
<li>New: Purlink support for advanced landing pages</li>
<li>New: Background automated cron jobs</li>
<li>Update: Up to 10x redirect speed boost for large databases</li>
<li>Update: Centralized Reporting System</li>
<li>Update: Ignore DNT header</li>
<li>Update: Sortable columns on all reports</li>
<li>Update: Currency exchange rate values update on each new conversion</li>
<li>Update: Upgrades that take a while will not time out</li>
<li>Update: Landing page sets the right domain and subdirectory</li>
<li>Update: Adblocker warning removed</li>
<li>Update: Output javascript header for landing page javascript</li>
<li>Update: Switch to utf8mb4_general_ci in reports</li>
<li>Update: MySQL strict mode disabled</li>
<li>Update: Ability to get the landing page url from a get variable instead of referrer</li>
<li>Update: Better strict mode disabling</li>
<li>Update: Improved alp/slp code management</li>
<li>Update: SSL based redirects for referer shield if server has ssl installed</li>
<li>Update: Group Overview shows IPv6 addresses better</li>
<li>Update: PurLink performance enhancements for faster and better tracking</li>
<li>Fixed: Automatic 202-config.php settings transfer was passing blank values</li>
<li>Fixed: Simple landing page code works if installed in a subdirectory</li>
<li>Fixed: Charts were not showing all data</li>
<li>Fixed: ipv6 didn't work on older database versions</li>
<li>Fixed: Visitor download report works</li>
<li>Fixed: Maxbounty uses s2 for Bot202 LinkAssist</li>
<li>Fixed: LP doesn't double count visits on Safari</li>
</ul>

<b>Version 1.9.51</b>

<ul>
<li>New: GDPR Compliance Options To Mask IP and Cookieless Functionality</li>
<li>New: Prosper202 Postback can be used as Webhook url for Shopify conversion tracking</li>
<li>New: Ability To Remap Default Prosper202 Url Varables To Custom Varables</li>
<li>New: PCI value available on dynamic content segments and t202DataObj</li>
<li>New: Support for Bing MSCLKID for offline conversions</li>
<li>New: Dynamic content segments can now display dynamic dates on landing pages</li>
<li>New: Up To 10x faster Zero Redirect PurLink Redirects</li>
<li>New: [[CPA]] Token</li>
<li>New: Support for ipv6</li>
<li>New: Support for php 7.2</li>
<li>New: Smart Redirector can now id European traffic for GDPR and other uses</li>
<li>New: AdBlocker Detection to ensure Prosper202 dashboard works as expected</li>
<li>New: PurLinks now support pages that use forms for lead gen</li>
<li>Update: All api calls use https</li>
<li>Update: If a public click id is missing in the url, find it from the database</li>
<li>Update: Support for newer browsers such as Brave</li>
<li>Update: Improved the logout to be more secure</li>
<li>Update: Spy View will use the read only database if it exists</li>
<li>Update: 202-config-sample.php now includes read only database</li>
<li>Update: Maxbounty uses s1 for LinkAssist</li>
<li>Update: Direct links work even when database is offline with BlazerCache</li>
<li>Update: Group Overview reports more accurate</li>
<li>Update:Faster Bot Detection Code</li>
<li>Update: Switch to new GEOLite2 Database</li>
<li>Update: Cron jobs will run more frequently</li>
<li>Update: Big deletion jobs will run in batches</li>
<li>Fixed:  Tokens were not being pass correctly</li>
<li>Fixed: Device type report in group overview was not correct</li>
<li>Fixed: Users were not able to reupload subid conversion data if it had been deleted</li>
<li>Fixed: Cloaked Landing Pages didn't redirect correctly</li>
<li>Fixed: Cron jobs use more accurate times </li>
<li>Fixed: API Key wasn't being loaded correctly</li>
<li>Fixed: Conversion log was calculating wrong date difference between first click and conversion</li>
<li>Fixed: More secure database error page </li>
<li>Fixed: Some new clicks were not getting set with ip address</li>
</ul>

<b>Version 1.9.50</b>

<ul>
<li>New: Login page wallpaper</li>
<li>New: [[transactionid]] token</li>
<li>New: Mailchimp, Instagram, Pinterest, Snapchat, Quora icons for spy & visitor view</li>
<li>Update: Refresh Facebook, Twitter, Youtube icons for spy & visitor view</li>
<li>Update: Advanced Landing Page Javascript</li>
<li>Update: Pixel url validation</li>
<li>Fixed: Correct display of links in step 8</li>
<li>Fixed: Duplicate Conversion checker for the dedupe pixel/postback option</li>
<li>Fixed: Update Currency didn't save the new settings</li>
<li>Fixed: Only create publisher ids if random_bytes() function exists</li>
<li>Fixed: Memcache error in DataEngine reports</li>
</ul>
<b>Version 1.9.49</b>
<ul>
<li>Fixed: Smart Redirector wasn't passing values into tokens</li>
<li>Fixed: No chat widget for publishers</li>
<li>Fixed: Limit publisher ability to see ppc accounts, landing pages and campaign lists</li>
</ul>

<b>Version 1.9.48</b>

<ul>
<li>New: Create Internal Affiliate Program with new Publishers feature</li>
<li>New: View Transaction Id Report in Group Overview</li>
<li>New: View Publisher/User Report in Group Overview</li>
<li>New: Support for downgrading from newer version down to 1.9.48</li>
<li>New: [[t202pubid]] Token for dynamically passing public publisher id to other urls and postbacks</li>
<li>Fixed: Link To Text Ads</li>
<li>Fixed: Prosper202 API won't list deleted landing pages</li>
<li>Fixed: Iframe pixel code fixed</li>
<li>Fixed: CLickbank postback/ips url works in PHP7 </li>
<li>Fixed: Fix for users installing in subdirectory</li>
<li>Fixed: Upgrade Check Typo Bug</li>
</ul>

<b>Version 1.9.47</b>

<ul>
<li>New: Bot202 LinkAssist Support for additional networks</li>
<li>New: Dynamic Content Segments Supports IP address display</li>
<li>Fixed: 1-Click Auto Updates work</li>
</ul>

<b>Version 1.9.46</b>

<ul>
<li>New: Bot202 Link Assist Automatically Detects and auto format affiliate links with [[subid]] token</li>
<li>New: Auto check landing pages to make sure the lp javascript is placed</li>
<li>New: Async Landing page Javascript snippet with automatic support for https</li>
<li>Update: Improved less confusing installation flow</li>
<li>Fixed: Bug with geoip when php geoip module is installed on server</li>
<li>Fixed: Bug with upgrade from versions older than 1.9.3</li>
<li>Fixed: Workaround for when SERVER_NAME is _</li>
<li>Fixed: Database changes during upgrades and new installs</li>
</ul>

<b>Version 1.9.45</b>

<ul>
<li>New: Zero Redirect PurLink Technology For Landing Pages</li>
<li>New: Advanced Landing Pages now support Leave Behind links for extra revenue</li>
<li>New: Ability to get secure LP javascript</li>
<li>Update: The Smart Redirector allows for substring matching in the referrer url rule</li>
<li>Update: Landing page Javascript loads faster for even better tracking</li>
<li>Fixed: Redirect issues on some Smart Redirector links</li>
<li>Fixed: Day parting report sorts hourly by default</li>
<li>Fixed: Hourly Overview reports sorts data hourly by default</li>
<li>Fixed: Landing Page Tracking Error</li>
<li>Fixed: Login issue during setup for some users</li>
<li>Fixed: Fixed width layout</li>
</ul>

<b>Version 1.9.44</b>

<ul>
<li>Update: User Interface Design Improvments </li>
<li>Update: Min PHP Requirement Dropped to 5.4</li>
<li>Update: Min MySQL Requirement Dropped to 5.5</li>
<li>Fixed: Direct Link Tracking Error</li>
<li>Fixed: Landing Page Tracking Error</li>
<li>Fixed: Cloaked link redirect error</li>
<li>Fixed: Subid upload error</li>
</ul>

<b>Version 1.9.43</b>

<ul>
<li>New: TV202 For Training Videos </li>
<li>Update: Removed AppStore</li>
<li>Update: Removed Rapid Ad Builder</li>
<li>Update: Requirements Support MariaDB</li>
<li>Update: Subid uploads are faster</li>
<li>Fixed: Subid Upload Error</li>
</ul>

<b>Version 1.9.42</b>

<ul>
<li>Update: Faster pixel firing and redirects</li>
<li>Fixed: Query error for custom variables fixed</li>
<li>Fixed: Clickbank verification url fixed</li>
<li>Fixed: 3rd Party piggy back postback firing correctly</li>
<li>Fixed: Secondary users can now see reports</li>
<li>Fixed: Error with updating CPC when installed in subdirectory</li>
</ul>

<b>Version 1.9.41</b>

<ul>
<li>New: You can create custom variables that save data into c1-c4 and t202kw variables</li>
<li>New: New option for setting default traffic source. Allows for better organic SEO tracking</li>
<li>Fixed: Don’t show deleted variables in the custom variable report</li>
<li>Fixed: Cleaned up confusing ui on landing page setup</li>
</ul>

<b>Version 1.9.40</b>

<ul>
<li>New: Leave behind functionality to increase landing page revenue</li>
<li>New: Landing pages use secure links if secure Javascript snippet is used</li> 
<li>New: Support for read only database for performance boost</li>
<li>Update: Default to sorting reports by leads</li>
<li>Update: Optimized all images</li>
<li>Update: No meetup, system or version update checks</li>
<li>Update: Expanded the layout width to 80% instead of fixed 1028px</li>
<li>Update: Deleted unneeded footer links and text</li>
<li>Fixed: 3rd party piggyback postbacks were not firing</li>
<li>Fixed: Tokens in the redirect URL were not being fired if not set in tracking url</li>
</ul>

<b>Version 1.9.39</b>

<ul>
<li>New: Ability to hide ads</li>
<li>New: Support for read only database for performance boost</li>
<li>Update: Redirect links with work in any directory. Phase 1 of being able to remove 202 finger prints from url</li>
<li>Update: Optimized smart redirector by not saving to spy view</li>
<li>Update: Turn on Maxmind ISP database for everyone</li>
<li>Update: Faster DataEngine Querys for Reporting</li>
<li>Update: Improved support for both memcache and memcached</li>
<li>Update: Improved error message for database fails</li>
<li>Update: Updated GeoIp File</li>
<li>Fixed: Deleted unneeded ajax calls on home page</li> 
<li>Fixed: Rapid Ad Builder was adding the words click to edit into links</li>
<li>Fixed: Big reports don't show memory limit errors</li>
<li>Fixed: General memcache related bugs</li>
<li>Fixed: Pagenation related error fixed</li>
</ul>

<b>Version 1.9.38</b>

<ul>
<li>New: We detect prefetch links from Facebook and other bots so stats are not thrown off</li>
<li>Update: Conversion Logs now track manual uploads</li>
<li>Update: New GeoIp Databases</li>
<li>Update: You will now get even more enhanced details about your smart rotators when looking at Spy view & visitor view</li>
<li> Update: Your smart rotator now supports tracking of custom traffic source variables</li>
<li>Fixed: Smart Rotators didn't always show due to our filtering widget</li>
<li>Fixed: Smart Rotators can now be filtered when creating links on step 8</li>
</ul>

<b>Version 1.9.37</b>

<ul>
<li>New: Conversion pixels are now able to completely ignore duplicate conversions by setting adding &t202dedupe=1</li>
<li>Update: Spy view & visitor view shows more complete information on your Smart Rotators. This makes it easier to see which offers users saw and clicked on.</li>
<li> Update: We've improved the way we link and report stats for rotators that send clicks to landing pages. You will see less double counting in your stats.</li>
<li>Update: The landing page Javascript loads the code faster to ensure better tracking of visitor.</li>
</ul>

<b>Version 1.9.36</b>

<ul>
<li>New: Ability To Upload Directly To Facebook Ads via Rapid Builder</li>
<li> New: Updated all API endpoints to https for enhanced security</li>
<li>Update: New Geo IP Location Detection Database</li>
<li>Update: New User Agent Browser Detection Database</li>
</ul>

<b>Version 1.9.35</b>

<ul>
<li>New: Added RevContent support for RapidBuilder.</li>
<li>Fixed: Various Bug fixes.</li>
</ul>

<b>Version 1.9.34</b>

<ul>
<li>New: Added Ability to edit tokens in your RapidBuilder url.</li>
<li>New: Tokens pre-populate from RapidBuilder tracking url.</li>
<li>Fixed: RapidBuilder UI tweaks.</li>
<li>Fixed: Various Bug fixes.</li>
</ul>

<b>Version 1.9.33</b>

<ul>
<li> New: Added Ability to edit feeds you have already generated</li>
<li> New: You can now preview all the ads and removed unwanted combinations before generating the feed</li>
<li> New: Simple way to add your Prosper202 Customer API key to your account with 1-click</li>
<li> Fixed: Uploaded images had the wrong path</li>
<li> Fixed: Conversion logs show the right pixel type</li>
<li> Fixed: AdvancedAdvanced landing pages dropdown ui fixed to allow filtering</li>
<li> Fixed:Header added to Adwords offline conversions file</li>
<li> Fixed: [[source_id]] and other tokens work for landing pages</li>
<li> Fixed: Show currency for  CNY,INR and RUB in campaign setup page</li>
<li> Fixed: Manual upload timestamp accepts human readable values</li>
</ul>

<b>Version 1.9.32</b>

<ul>
<li> New: Prosper202 Native AdBots Beta 1</li>
<li> Fixed: Various Bug fixes</li>
</ul>

<b>Version 1.9.31</b>

<ul>
<li>New: Multi Currency Support. Prosper202 automatically converts payouts into your local currency.</li>
<li>New: Support for windows servers with php installed</li>
<li>New: Support transaction ids that allow tracking of multi-step offers</li>
<li>New: Subid upload page now support transaction ids</li>
<li>New: Adwords Offline Conversions Export</li>
<li>New: Clickbank support for multiple conversions, upsells and refunds</li>
<li>New: Ability To Redirect Filtered Visitors in Smart Redirector</li>
<li>New: Ability To Redirect by C1-C4 value Smart Redirector</li>
<li>New: Ability To Redirect by t202kw value in Smart Redirector</li>
<li>New: Ability To Redirect by utm variables value in Smart Redirector</li>
<li>New: Ability To Redirect by referer value in Smart Redirector</li>
<li>New: Optimized redirect speeds for Smart Redirector</li>
<li>New: Mobile App Deeplinks support for campaign urls</li>
<li>New: Pixel url validation for Universal Smart Pixel</li>
<li>New: Smart Redirector support for ip ranges</li>
<li>New: Auto Database Optimization - Keeps your database size optimized automatically</li>
<li>New: Custom Variables report runs multiple times faster</li>
<li>New: Support for transaction ids in pixels, postbacks and manual conversion uploads</li>
<li>New: Brand new design for step 9 pixels and postback page.</li>
<li>New: Prosper202 Customer API key to unlock extra Premium functionality</li> 
<li>New: Group overview report now includes pagination for reports with multiple pages</li>
<li>New: Support for Memcached in addition to Memcache</li>
<li>New: Support for MySQL Strict Mode</li>
<li>Fixed: Fixed Bug where smart rotators and advanced landing pages were not showing in step 8</li>
<li>Fixed: APC Bug where cache wasn't being cleared on upgrade</li>
<li>Fixed: Smart Rotators modal loads correctly</li>
<li>Fixed: No errors show when DNI server is offline</li>
<li>Fixed: Improved click deletion functions</li>
<li>Fixed: Error in spy/visitor view display when location was unknown</li>
<li>Fixed: Dynamic Bid for Simple Landing Pages is recognized</li>
<li>Fixed: For some users setup tab was missing after an upgrade</li>
<li>Fixed: Fixed support for all tag in Smart Redirector so it's case insensitive</li>
<li>Fixed: Fix for auto increment sometimes being set to 0 in the clicks counter</li>
<li>Fixed: Advanced Landing Page Smart Redirector works better for split tests</li>
<li>Fixed: Date formatted in US format in account overview</li>
<li>Fixed: Password reset emails were not getting sent</li>
<li>Fixed: In visitor/spy view, No PPC Network selection filters correctly</li>
<li>Fixed: Ability to disable mysql strict mode</li>
<li>Fixed: Improved installation script to reduct errors</li>
<li>Fixed: Improved pagination for reports with multiple pages</li>
<li>Fixed: Conversion logs no longer shows errors when you choose a custom time range</li>
<li>Update: Removed report caching feature</li>
<li>Update: Conversion Logs moved into main reports section</li>
<li>Update: Optimized Analyze Variables Report for speed</li>
<li>Update: Visitors download report now includes revenue column</li>
</ul>

<b>Version 1.9.30</b>

<ul>
	<li>New: Prosper202 Customer API key to unlock extra Premium functionality</li>
	<li>Fixed: Various bug fixes for stability and performance.</li>
</ul>

<b>Version 1.9.29</b>

<ul>
	<li> New: Performance Optimizations for Direct Links</li>
	<li> New: Support for PHP 7</li>
	<li> New: Quick Activation of DNI Networks</li>
	<li> New: Global Postback url accepts POST data</li>
	<li> New: [[sourceid]] token to pass ppc account id for better source tracking and segmentation by the network</li>
	<li> New: Filter by subid</li>
	<li> New: Inline Help documentation links</li>
	<li> New: Easy link to premium MaxMind database purchase</li>
	<li> New: API Endpoint For ClickServer Version</li>
	<li> Fixed: Advanced Landing pages on step 4 listed alphabetically</li>
	<li> Fixed: Timezone for GMT 0 + works correctly</li>
	<li> Fixed: Document Roots that are symlinks are correctly detected</li>
	<li> Fixed: Chart data displays correctly</li>
	<li> Fixed: Chart customization modal closes correctly</li>
	<li> Fixed: List of landing pages displayed correctly</li>
	<li> Fixed: Autocron was not registering correctly</li>
</ul>
<b>Version 1.9.28</b>
<ul>
	<li> Fixed: Group overview reporting on all ppc networks even when only one network was selected</li>
	<li> Fixed: New landing pages were not being saved</li>
	<li> Fixed: Overview report shows direct link and simple landing page stats</li>
</ul>

<b>Version 1.9.27</b>

<ul>
	<li> New: Instant Deep Link Offer Setup via DNI (Direct Network Integration)</li>
	<li> New: Table for tracking subids is cleared daily for performance purposes</li>
	<li> New: Performance tweaks for referrer tracking table (New installations only)</li>
	<li> New: Prosper202 Pro installs and Runs On Shared Hosting Plans</li>
	<li> Fix: Error with drop downs not working with DNI has been fixed</li>
</ul>
<b>Version 1.9.26</b>
<ul>
	<li> Fixed: Group overview filtering for PPC Networks</li>
	<li> Fixed: Alignment of dropdown values</li>
</ul>
<b>Version 1.9.25</b>
<ul>
	<li> New: Allow users on servers with no partition support to still install Prosper202 Pro</li>
	<li> New: Ability to run reports on traffic that come from no ppc networks (For example organic traffic)</li>
	<li> New: Added info on how to use Dynamic Content Segments on landing pages</li>
	<li> New: Ability to specify which url variable to use as the t202kw value</li>
	<li> New: Quickly type and filter any of the data in Prosper202 drop downs</li>
	<li> New: Optimize tables with partitions for new installs of Prosper202 Pro</li>
	<li> New: Optimized code for C1-C4 custom variables</li>
	<li> New: Skip option for VIP Perks modal</li>
	<li> Fixed: Reports pages will not show errors when there is no data</li>
	<li> Fixed: Only live landing pages show in dropdown</li>
	<li> Fixed: Fixed various issues for users who have Prosper202 installed in subdirectories</li>
	<li> Update: Optimized custom variables reports page</li>
</ul>
<b>Version 1.9.24</b>
<ul>
	<li> New: Added filter options for sidebar lists (campaigns, networks, etc)</li>
	<li> New: Support For Dynamic Cost for Redirector</li>
	<li> New: Support For Dynamic Cost for Simple/Advanced Landing pages</li>
	<li> New: Easy token entry for t202kw (dynamic keyword), t202ref (Dynamic referer) and t202b (Dynamic Cost)</li>
	<li> Fixed: Filter by landing page on group overview</li>
	<li> Fixed: Charting Display Bug</li>
	<li> Fixed: Bot Detection bug</li>
	<li> Fixed: Empty Array reporting bug</li>
	<li> Fixed: Hourly breakdown display fixed</li>
	<li> Fixed: MYSQl api related bug</li>
	<li> Fixed: Cleaned up some unused code</li>
	<li> Update: Removed warning about text ads when generating tracking link</li>
	<li> Update: New user agent detector to detect more browsers etc</li>
	<li> Update: New Geo Ip database for improved location detection</li>
	<li> Update: URL rotator removed from step 3</li>
	<li> Update: 1-click upgrade alerts user if they are missing settings to make it work</li>
</ul>
<b>Version 1.9.23</b>
<ul>
	<li> Fixed: Bug in dynamic bid amount code for direct links</li>
</ul>
<b>Version 1.9.22</b>
<ul>
	<li> New: Separate tables on Account Overview for Campaigns and Landing pages</li>
	<li> New: Support for dynamic bid amount via t202b= variable</li>
	<li> New: Progress bar for DNI integrations so you see how much time is left for caching offers </li>
	<li> Fixed: Filtering and results count on visitor tab works better</li>
	<li> Fixed: Users on strict mode for mysql had errors with setup and using the software</li>
	<li> Fixed: Resized text ad preview</li>
	<li> Fixed: User gets redirected to correct page when logged out of mobile view</li>
	<li> Fixed: CTR is correct on mobile view</li>
	<li> Fixed: Long GCLID values were being cut short</li>
	<li> Fixed: Filtering in overview and charts</li>
	<li> Fixed: Undefined index error for cloaked links</li>
	<li> Fixed: Deleted custom variables were still showing on step 8</li>
	<li> Fixed: Page title on step 8 wasn't showing correctly in the browser </li>
	<li> Update: New indexes for c1-c4 tables when doing a new install. This will make everything faster</li>
	<li> Update: New indexes for custom variables table</li>
	<li> Update: New favicon next to DNI networks in step 3</li>
</ul>
<b>Version 1.9.21</b>
<ul>
	<li> Fixed: Bug fixes after upgrading and DNI ping back</li>
	<li> Fixed: Typo on the account page</li>
	<li> Fixed: Broken link on the admin page</li>
	<li> Fixed: Sorting error in the reports</li>
</ul>
<b>Version 1.9.20</b>
<ul>
	<li> New: Direct Network Integration listing have description and icons</li>
</ul>
<b>Version 1.9.19</b>
<ul>
	<li> New: Direct Network Integration</li>
	<li> New: Remember me feature</li>
	<li> New: [[timestamp]] token</li>
	<li> New: Check for Mcrypt on setup of Prosper202</li>
	<li> New: Check for Mcrypt before user can setup Clickbank Integration</li>
	<li> Fixed: Checks for writable folder uses exact directory needed</li>
	<li> Fixed: Undefined constant error fixed in rotator</li>
	<li> Fixed: Default Landing page in rotator</li>
	<li> Fixed: Ability to show ISP data with comma in name</li>
</ul>
<b>Version 1.9.18</b>
<ul>
	<li> New: Two-way communications with WP Plugin</li>
	<li> Fixed: Auto upgrade fixes</li>
	<li> Fixed: Keyword and Referer Filter work now</li>
	<li> Fixed: Rotator shows all landing pages</li>
</ul>
<b>Version 1.9.17</b>
<ul>
	<li> New: Two-way communications with WP Plugin</li>
	<li> Fixed: Get Advanced Landing page code bug fixed</li>
	<li> Fixed: Redirector wasn't showing all landing pages</li>
	<li> Fixed: Auto upgrade doesn't show an error</li>
</ul>
<b>Version 1.9.16</b>
<ul>
	<li> New: After login you will be redirected to the page you were trying to look at</li>
	<li> Fixed: Landing page bug tracking fixed</li>
</ul>
<b>Version 1.9.15</b>
<ul>
	<li> New: Support for installing Prosper202 in a subdirectory</li>
	<li> New: Support for Official Prosper202 Wordpress Plugin</li>
	<li> New: ISP And Carrier information available in download report</li>
	<li> New: [[referer]] & [[referrer]] token support for all locations that accept tokens</li>
	<li> New: Landing page setup page now includes easy token insertion buttons</li>
	<li> Update: Spy view loads multiple times faster</li>
	<li> Update: Visitor loads multiple times faster</li>
	<li> Fixed: Pagination improvements</li>
	<li> Fixed: Bug in modal window display</li>
	<li> Fixed: For split-testing you can use both ALL or all as values</li>
	<li> Fixed: Bug with utm_source and utm_medium being stored in wrong location</li>
	<li> Fixed: Bug in referer report that made it return too many results</li>
	<li> Fixed: Bug where pixels were not deleting correctly on step 1</li>
</ul>
<b>Version 1.9.14</b>
<ul>
	<li> Fixed: Universal Smart Pixel setup was overwriting pixel urls</li>
	<li> Fixed: ISP and Carrier Detection for the redirector tracks better</li>
</ul>
<b>Version 1.9.13</b>
<ul>
	<li> New: Universal Smart Pixel Can Fire Multiple Types of Pixels </li>
	<li> Fixed: Results count on spy view is more accurate</li>
	<li> Fixed: Tooltips display better</li>
	<li> Fixed: Filters in spy view don't show sql errors</li>
	<li> Fixed: IP Address Detection is improved</li>
</ul>
<b>Version 1.9.12</b>
<ul>
	<li> New: Visitor View and Spy View Reports run faster</li>
	<li> New: Ability To Split-Test offers on landing pages</li>
	<li> New: Ability to detect correct ip address when using firewall or loadbalancer</li>
	<li> New: Raw pixel option in Universal Smart Pixel now allows tokens to be passed in</li>
	<li> Fixed: Deleted campaigns do now show landing pages in redirector</li>
	<li> Fixed: Daily email reports showed wrong numbers </li>
	<li> Fixed: Link to Overview fixed</li>
</ul>
<b>Version 1.9.11</b>
<ul>
	<li> Fixed: Reporting API did check for correct encoding</li>
	<li> Fixed: Added Missing Include File </li>
	<li> Fixed: Mobile site shows correct data</li>
	<li> Fixed: Fixed problem with filtering by keyword</li>
	<li> Fixed: Rotator caused issues with saving updates when modifying existing rules</li>
	<li> Fixed: Improved method of checking for partitions support</li>
	<li> Fixed: Missing link to login page if Prosper202 is already installed</li>
	<li> Fixed: Graph data displays better when set to show by hours</li>
</ul>
<b>Version 1.9.10</b>
<ul>
	<li> New: Autocomplete for Traffic Sources</li>
	<li> New: Autocomplete for Categories and affiliate networks </li>
	<li> New: Check to see if server meets requirements before new installation</li>
	<li> New: Export to excel for in custom variable report</li>
	<li> New: Improved upgrade speed for older users</li>
	<li> New: Improved DataEngine imports for upgrades</li>
	<li> New: Applebot detection</li>
	<li> New: VIP Perks survey shows only new questions</li>
	<li> New: On new installs, 202_site_urls table is more efficient</li>
	<li> Fixed: Missing header added to auto upgrade file</li>
	<li> Fixed: Display long keywords better in visitor and spy view </li>
	<li> Fixed: Mobile site shows correct data</li>
	<li> Fixed: Simple landing pages pickup t202kw as the keyword</li>
	<li> Fixed: Deleted clicks get deleted from DataEngine as well</li>
	<li> Fixed: Custom variables get picked up on the landing page</li>
	<li> Fixed: Custom variables get added when generating links for landing pages</li>
</ul>
<b>Version 1.9.9</b>
<ul>
	<li> New: Prosper202 Pro Logo</li>
	<li> Updated:Smart redirector supports 'all' tag</li>
	<li> Updated: Smart Rotator renamed to Smart Redirector</li>
	<li> Fixed: Daily emails not sent if no data</li>
	<li> Fixed: Smart Redirector for split-testing</li>
	<li> Fixed: Clickbank ISN reporting tracks sales</li>
	<li> Fixed: More efficient click deletion code</li>
</ul>
<b>Version 1.9.8</b>
<ul>
	<li> New: Bot Filter Database</li>
	<li> Fixed: Rotator redirections</li>
	<li> Fixed: Removed Auto Monetizer Place Holder</li>
	<li> Fixed: Landing pages passes url variables</li>
	<li> Fixed: Removed API keys section</li>
	<li> Fixed: Daily email can now be set to never without error</li>
</ul>
<b>Version 1.9.7</b>
<ul>
	<li> New: Daily Email Reports</li>
	<li> New: Loading indicator for data</li>
	<li> Improved: Faster account overview loading</li>
	<li> Fixed: Bing Devex Keyword bug fix</li>
	<li> Fixed: Referer Search bug</li>
	<li> Fixed: IP search bug</li>
	<li> Fixed: Column sort bug</li>
</ul>
<b>Version 1.9.6</b>
<ul>
	<li> New: Split-testing functionality</li>
	<li> New: Automatic cron jobs without needing to manually set it up</li>
	<li> Improved: Bootstrap latest version</li>
	<li> Improved: Jquery latest version</li>
	<li> Improved: Data engine performance, reduction in cpu loads</li>
	<li> Improved: Clickbank ISN support v6 of the api</li>
	<li> Fixed: utm_source was being assigned to the keyword </li>
</ul>
<b>Version 1.9.5</b>
<ul>
	<li> New: Full user role implementation. with restrictions on what can be done in your p202</li>
	<li> New: You can clone an existing landing page</li>
	<li> New: Ability to dynamically displays ISP, Device, Postal code on landing page</li>
	<li> New: Ability to to set cloaking option. You can either blank the referrer or show you Prosper202 Pro domain only</li>
	<li> New: Auto upgrade Prosper202 Pro for any updates that don't modify the database in any way.</li>
	<li> New: Slack Integration Phase 2 all actions performed in your P202 will be sent to a slack channel named Prosper202</li>
	<li> New: Before you could only display one Dynamic Content Segment on the page, now you can have as many as you want.</li>
	<li> New: Unlimited custom variables and tokens</li>
	<li> New: Campaign overview shows overview like what we had in non Pro version of Prosper202</li>
	<li> New: Status page in admin section for cron jobs. This will let you know when the last cron was run and help to debug when the job isn't setup correctly</li>
	<li> New: GCLID and utm variables will show in downloaded report in the visitors tab</li>
	<li> Improved: Parallel processing of conversion old data into new faster format</li>
	<li> Improved: Improved more efficient way to update data engine that uses less server resources</li>
	<li> Improved: For new installations the maximum payout amount for a campaign is now $100,000 instead of $999</li>
	<li> Fixed: Deleted or deactivated users can't login</li>
	<li> Fixed:Geo location for when location can't be found works better</li>
	<li> Fixed: Mobile filtering works better</li>
	<li> Fixed: Illegal offset error when creating tracking links</li>
	<li> Fixed: PPC Network didn't auto select when editing a existing link</li>
	<li> Fixed: Slack notifications when editing tracking link</li>
	<li> Fixed: Better support for when user doesn't have ISP database from MaxMind</li>
	<li> Fixed: Manual download link for Pro links to correct page</li>
	<li> Fixed: Campaign overview shows multiple advanced landing pages better</li>
	<li> Fixed: Bug showing php code at the bottom of visitors page</li>
	<li> Fixed: When downloading reports the entire report will download instead of just what you see on screen</li>
	<li> Fixed: Updating CPC works better</li>
	<li> Fixed: Bug that prevent keywords from filtering correctly</li>
</ul>
<b>Version 1.9.4</b>
<ul>
	<li>New: Easy Display of Geo(Country, Region, City, Country Code), Keyword, C1-C4, All utm variable, Browser type, OS on your landing page</li>
	<li>New: [[country]], [[country_code]], [[region]], [[city]] tokens for step 3, and postbacks</li>
	<li>New: HTML5 Charts for account overview with ability to chart multiple data points on a campaign basis.</li>
	<li>New: Ability to add users to your Prosper202 account. Each person get's their own un/pw.</li>
	<li>New: Slack Integration Phase 1: Soon All important changes and notifications will be posted to the slack channel of your choice</li>
	<li>Updated: On new installations keywords can be up to 150 characters instead of 50</li>
	<li>Fixed: Display and Formatting bug for Firefox</li>
	<li>Fixed: Bug on landing page campaigns was showing wrong referer information</li>
	<li>Fixed: EPC on account overview was calculated incorrectly</li>
	<li>Fixed: Custom date ranges increase in hourly increments</li>
</ul>
<b>Version 1.9.3</b>
<ul>
	<li>New: Group by device type in group overview</li>
	<li>New: Track cost by CPA</li>
	<li>New: Group overview is much faster and powered by the new DataEngine</li>
	<li>New: Conversion logs to see more details conversion pixels and postback fires</li>
	<li>New: Updated GeoIp Database for more accurate geo location</li>
	<li>New: Updated UI Library</li>
	<li>New: Updated UserAgent Parser to detect more browsers and bots</li>
	<li>New: Ability to edit your tracking links after they have been created</li>
	<li>New: Prosper202 will now automatically import your old clicks into the new DataEngine Format</li>
	<li>New: Filter by referer</li>
	<li>Fixed: Bug in how leads were counted in campaign overview is fixed</li>
	<li>Fixed: Excel Report download feature re-added </li>
	<li>Fixed: Device type filtering bug in group overview fixed</li>
	<li>Fixed: IP address filtering bug in reports fixed</li>
	<li>Improved: Landing page names display better on the reports</li>
</ul>
<b>Version 1.9.2</b>
<ul>
	<li>Improved: Copied Campaigns have (Copy) Appended to the campaigns name so you can tell you are in the process of copying the campaign</li>
</ul>
<b>Version 1.9.1</b>
<ul>
	<li>New: Ability to copy an existing campaign to create a new one.</li>
	<li>New: UTM variables are available in group overview.</li>
	<li>New: Device type is available in group overview.</li>
	<li>Updated: Text field for landing page url has be change to text field.</li>
	<li>Updated: Rotator ui updated and new monetizer placeholder added.</li>
	<li>Fixed: You can now sort reports by clicking the header.</li>
	<li>Fixed: DataEngine now updates for actions done on the update tab.</li>
</ul>
<b>Version 1.9.0</b>
<ul>
	<li>New: DataEngine fast new reporting engine for Prosper202 Pro.</li>
	<li>New: Meta Referer set to origin for Cloaking.</li>
	<li>New: Tokens for utm variables.</li>
	<li>New: Support for google gclid and utm variables.</li>
	<li>New: Prosper202 App Store.</li>
	<li>New: Ability to set your own referer via t202ref=.</li>
	<li>Improved: C1-4 variables can now store up to 350 characters.</li>
	<li>Improved: Better more accurate firing of conversion pixels.</li>
	<li>Improved: Ability to pass in subid for image and iframe pixels.</li>
</ul>
<b>Version 1.8.11</b>
<ul>
	<li>Fixed Bug: Filtering via keyword, ip, or referer doesn't cause an error</li>
	<li>Fixed Bug: 1-Click Update correctly finds new updates.</li>
	<li>Update: Landing pages track traffic better via finger printing</li>
	<li>Update: Improved browser detection</li>
	<li>Update: Improved GEO Detection</li>
	<li>Update: ClickBank IPN support for version 6</li>
</ul>
<b>Version 1.8.10</b>
<ul>
	<li>Fixed Bug: Advanced landing pages showed a query error when using built in outbound link.</li>
	<li>Update: Templates now use UTF 8.</li>
</ul>
<b>Version 1.8.9</b>
<ul>
	<li>Fixed Bug: On Admin Screen if you had a lot of clicks you would see an out of memory error</li>
	<li>Fixed Bug: Debug code removed from code</li>
	<li>Improved: Better query for url look ups</li>
	<li>Improved: Device finger printing to redirect users when cookies are removed</li>
</ul>
<b>Version 1.8.8</b>
<ul>
	<li>Fixed bug: Outbound clicks on ALP were not being recorded in database.</li>
	<li>Fixed bug: Campaign not showing for rotators.</li>
	<li>Fixed typo: On Advanced Landing Page setup.</li>
	<li>Fixed typo: Text Ads setup.</li>
</ul>
<b>Version 1.8.7</b>
<ul>
	<li>Fixed bug: Cookie error for landing pages. Where subids were not being passed.</li>
	<li>Fixed bug: Prosper202 didn't notice an auto updated system initially.</li>
	<li>Fixed bug: Versions were not being compared correctly for change logs.</li>
</ul>
<b>Version 1.8.6</b>
<ul>
	<li>Fixed bug: Error messages where set_time_limit is not allowed</li>
	<li>Fixed typo: Extra < on landing page screen</li>
</ul>
<b>Version 1.8.5</b>
<ul>
	<li>New: Prosper202 will pass extra variables from your tracking links on to the campaign link. This makes it easier to pre-pop offers.</li>
	<li>Improved support for new traffic sources.</li>
	<li>Minor speed improvement for users with large databases.</li>
	<li>Added Missing Robots.txt.</li>
	<li>Fixed Javascript bug on the pixel page.</li>
	<li>Fixed formula for payout.</li>
	<li>Fixed formatting for the iframe pixel.</li>
</ul>
<b>Version 1.8.4</b>
<ul>
	<li>Internet Explorer cookie javascript issue fixed.</li>
	<li>New redesigned Rotator.</li>
	<li>Old tables migrated to InnoDB.</li>
	 Version 1.8.3.3
	<li>Group Overview MySQL error</li>
	<li>Fixed memory problem</li>
	<li>Internet Explorer Detector</li>
	<li>Simple Landing page javascript code conflict with jQuery</li>
	 Version 1.8.3.2
	<li>SSL bug fixed</li>
	<li>Global Post Back bug fixed</li>
	<li>Setup wizard tweaked</li>
	 Version 1.8.3.1
	<li>Check if geoIP model exist</li>
	<li>Some code tweaks</li>
	 Version 1.8.3
	<li>Brand New Look & Feel</li>
	<li>VIP Perks System</li>
	<li>Enhancements for Mobile</li>
	<li>Database enhancements</li>
	<li>New postback response codes</li>
	<li>Enhanced security</li>
	<li>Expanded user agent detection</li>
	<li>Bot, search engine crawler detection and filtering</li>
	<li>New reports for GEO, User Agent and Platforms</li>
	<li>New group overview segments</li>
	<li>New report filters</li>
	<li>New Reporting API</li>
	<li>Landing Page redirect url</li>
	<li>Improved support for organic traffic</li>
	<li>Smart post click redirection rules</li>
	<li>Raw universal smart pixel</li>
	<li>New tokens for universal smart pixels</li>
	<li>Clickbank API integration</li>
	<li>1-Click auto upgrade</li>
	<li>New async javascript for lp javascript</li>
	<li>Expanded timezones</li>
	<li>Reports caching</li>
	<li>Ability to delete clicks prior to a certain date</li>
	<li>BlazerCache redirects</li>
	<li>BlazerCache downtime protection</li>
	<li>Smart Rotator</li>
</ul>
