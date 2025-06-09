# Frequently Asked Questions (FAQ)

## What is Prosper202?

Prosper202 is analytics tracking software that enables users to track profit and loss, conversion metrics, do split testing, and much more. We can track almost any traffic source and pretty much any affiliate offer/network/advertiser. We are not an affiliate network software nor are we a media server. Prosper202 was specifically designed to help people track their marketing campaigns and how well it's doing. Whether you are an affiliate, a small business owner, an agency, a product owner, etc.. we can help. Unsure if Prosper202 is right for you, feel free to contact us and ask.

 ## What is the difference between Tracking202 and Prosper202?

Tracking202 was our hosted solution for our flagship analytics tracking software and Prosper202 is the self-hosted solution. They are essentially the same thing.

 ## Can I use shared hosting to install Prosper202?

As a general rule of thumb, we do not recommend using shared hosting to run your Prosper202. Most shared hosting services do not meet the requirements of installing Prosper202 and even if you could get it to work, we highly recommend against it for performance reasons.

There are many affordable VPS or better hosting solutions, and we always strongly recommend using those instead.

 ## Will Prosper202 Work With My Web Host?

There is no way for us to tell specifically as there are too many web hosts out there for us to know of or even check. In general, most web hosts can be configured or install software to make it work, but by default, it is difficult to tell. If you want to be certain, please check our list of recommended web hosting companies who already support current Prosper202 users: [Web Hosting Services](../partnering-with-us/01-web-hosting-services.md)

 ## I am having issues with installing or upgrading Prosper202 or some other technical issue

Please refer to our setup and upgrade guides on this site respectively. If you require additional assistance, you must be a paid support subscriber.

In general, installs and upgrades work fine. If there is an issue, it might warrant a closer look but we do not offer free support so if you require assistance, we ask that you be a paid subscriber first.

## Can I install Prosper on a subdomain?

In general it is best to install Prosper on its own standalone domain but people have installed Prosper on subdomains and made it work.

 ## Is Memcache required?

No, but strongly recommended.

 ## The number of clicks shown in Prosper is different than what is shown in my network/traffic source.

This could be due to a number of reasons. Have you checked to see if Prosper is showing all clicks, filtered clicks, or real clicks? For an understanding of what these are, see the next question. Next, any tests the network/advertiser and/or traffic source does may not reflect on your click count with them but will be recorded by Prosper. Because filtering is also different between each source, the count can differ. Finally, be aware that any incomplete redirects or traffic that pings certain sources can throw counts off as well, such as users hitting your landing page triggering logs but not through your ad link. If you require additional assistance, we can take a look if you are a paid subscriber.

 ## What's the difference between a filtered click and a real click?

By default Prosper tries to detect whether or not a click is real (green) or fake but either way it records all clicks for the record. A filtered click (red), while recorded, does not count towards cost measurements in your Prosper analytics. An example of a filtered click could be bots crawling your ads. Another example might be a repeat IP that reloads your page but did not click on your ad again. 

## I tried to analyze my campaign but the data is not showing, why?

Please go to Overview > Group Overview and see if that's what you're looking for. Please also note that there are additional filter options most people miss. Please explore all parts of Prosper before contacting us about this. If you've tried to look everywhere and your data is still missing, we can help troubleshoot but you'll need to be on a paid support plan.

 ## Are there any limitations with the free Prosper202?

No. There are no click limits or any other limits we set. This is the full blown Pro version of the software with all the bells and whistles. It can handle insane amounts of traffic. We're giving it away because it stopped making sense to build two different versions of the software (a Lite version and a Pro version) and we felt that rather than charge for Pro, giving it away was a huge value add for the community. In return, we decided to no longer offer free support. We felt it was fair that if you needed assistance, you can pay for it considering the software is free and that we made great efforts to provide free documentation and video tutorials such as the one found on this site.

 ## I can't find my login or its not working

Your Tracking202 login is different from your Prosper202 login. When you installed Prosper202, you should have been asked to set up a separate login. Please refer to our setup tutorial for assistance to see if you did this correctly. If you are still having trouble after reviewing the video tutorial, please contact us then. Please note we do not offer free support so you'll need to be on a paid support plan for us to help. If you lost your login, there should be a link for you to reset your password. If all else fails, you may need to reinstall Prosper as we have no access to the Prosper install on your own server.

 ## My keywords are not showing up, what's wrong?

Some traffic sources such as Google and Bing have specific tokens required for keyword tracking to show up. For Google its {keyword} and for Bing its {QueryString}. Other sources may have different requirements. Whatever the requirement is, please check with your traffic source accordingly. Simply add the token to the end of your t202kw= parameter created by your generated tracking link if it hasn't already done so, and it should work. If you've already done this and your keywords are still not showing up, you may need further assistance. Please subscribe to one of our support plan for further help.

 ## My tracking link works but I'm not seeing any conversions

Please make sure your conversion tracking is set up properly. There are multiple ways Prosper can track conversions, but without knowing the specifics, we can't answer this question easily. You can pay for support and reach us if you are unable to figure this out.

 ## How do I rotate offers or split test LPs?

Please refer to our video tutorial section found here: [Video Tutorials](01-video-tutorials.md)

 ## I have multiple offers, can Prosper track this?

Yes. With our Advanced LP setup. Please refer to our tutorials.

 ## Where do I go to report bugs

Please contact us through the live chat to submit any bug reports. It is greatly appreciated.

 ## My conversions are not showing up, what do I do?

Without knowing what you did or how you set up your campaign, we can not answer this question easily. You can review over our tutorial videos to see if you set everything up correctly or sign up for a support plan and contact us for further assistance.

 ## Does Prosper202 offer cloaking?

At the time of this writing, we do not. 

 ## Does your analytics software work with email marketing or actual mobile apps?

We've had users use our software for email marketing, and while our software works with promoting mobile install offers, it does not yet support event tracking inside of mobile apps. Please note that Prosper202 was not designed for mobile app install tracking.

 ## Can Prosper202 filter out IP ranges?

Not at this time. You can add individual IP ranges or select individual targeting parameters but we currently don't support ranges.

 ## What's the difference between Clicks and CTR?

Clicks are tracked when someone clicks on your ad. CTR is tracked when someone clicks from your LP to the offer.

 ## Does Prosper202 offer URL shortening

We do not.

 ## I am new to all of this, will you train me?

If you are one of our paid support subscribers, we will help you learn how to use our software. If you want us to train you on how to do affiliate marketing, we have a separate course coming out soon you can pay for that will address that. Please contact us for any updates.

 ## Can I reset test clicks or block my IP from testing?

If you know how to do this manually through your DB, you can do it but the option is not built into Prosper202 yet.

 ## Can we modify Prosper and release software using your code?

Please contact us for a licensing deal.