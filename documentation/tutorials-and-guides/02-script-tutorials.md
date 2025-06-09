# Script Tutorials

**Please note that some of these are really old scripts but we're including them for legacy sake:**

Below you'll find three available scripts. **[iFrame](02-script-tutorials.md#section-iframe-script)**, **[Dynamic Keyword Insertion](02-script-tutorials.md#section-putting-dynamic-keywords-in-your-landing-pages)**, and **[Page Load Time Analysis](02-script-tutorials.md#section-landing-page-load-time-analysis)**.

## iFrame Script

Here is the correct code to use for iframing an offer with Tracking202. The Prosper202 self-hosted version will be slightly different, but its the exact same idea. Place the Javascript at the end, and have the iframe src set to the landing page outbound link, not the php redirect code.
[block:code]
{
  "codes": [
    {
      "code": "<html>\n     \t<head>\n          \t<title>Title Goes Here</title>\n     \t</head>\n     \t<body style=\"margin: 0px; padding: 0px;\" scroll=\"no\">\n          \t<iframe src=\"http://redirect.tracking202.com/lp/XXXXXX\" style=\"border: 0px; width: 100%; height:100%;\"></iframe>\n          \t<script src=\"http://static.tracking202.com/lp/XXXXXX/landing.js\" type=\"text/javascript\"></script>\n     \t</body>\n</html>",
      "language": "html"
    }
  ]
}
[/block]
Using an Iframe on the 2nd page, instead of redirecting through the affiliate link
Sometimes you may just want a regular landing page as the first page the visitor sees, but then instead of having a link that redirects out to the offer destination url, you instead want the 2nd page to just be an iframe of the offer. So in this aspect the visitor never actually leaves your domain. They land on the first page, which is a regular page that tries to make them click through and then instead when they click through to redirect them to the offer, you instead have a page that iframes the offer. This makes it look like the user is still on your page. Below I will show you how to do this.

The basic concept is acutally quite simple; instead of redirecting to the affiliate url, we are now just going to have an iframe and paste the affiliate url as the IFRAME SRC. You can do this by modifing the simple or advance landing page PHP REDIRECT code by using the example below. All that is happening is instead of using the previous header() command which redirects the user, we now echo (which prints to HTML) the url in the IFRAME SRC. See below:
[block:code]
{
  "codes": [
    {
      "code": "<?php\n  \n//$tracking202outbound is where the user is suppose to be redirected to\n  if (isset($_COOKIE['tracking202outbound'])) {\n    $tracking202outbound = $_COOKIE['tracking202outbound'];     \n  } else {\n    $tracking202outbound = 'http://redirect.tracking202.com/lp/xxxxx';   \n  }\n  \n?>",
      "language": "php"
    }
  ]
}
[/block]

[block:code]
{
  "codes": [
    {
      "code": "<html>\n   <head>\n       <title>Title Goes Here</title>\n    </head>\n    <body style=\"margin: 0px; padding: 0px;\" scroll=\"no\">\n          <iframe src=\"<? echo $tracking202outbound; ?>\" \n                 style=\"border: 0px; width: 100%; height:100%;\"></iframe>\n    </body>\n</html>",
      "language": "html"
    }
  ]
}
[/block]
## Putting Dynamic Keywords in your Landing Pages

This shows how to dynamically display the keyword the user was searching for on your landing page. This is a simple script that prints the dynamic keyword on the page for users with Tracking202 installed. Below is an example landing page .php file that shows how to place dynamic keywords on your landing page!

**Landing Page Code** 
[block:code]
{
  "codes": [
    {
      "code": "<?\n\n//grab t202 keyword\n$keyword = $_GET['t202kw'];\n\n//if a yahoo keyword exists, over-write the t202 keyword\n//for Yahoo OVKEY = the bidded keyword, OVRAW = actual keyword\n//you can change $_GET['OVRAW'] to $_GET['OVKEY'] if you would\n//like to display the bidded keyword, instead of the actual keyword.\nif ($_GET['OVKEY']) { $keyword = $_GET['OVKEY']; }  \n\n//now anywhere we call echo $keyword, it will display the dynamic kw!\n\n//extra goodie, uncomment the line below if you would like to capitalize \n//the first character in each word\n//$keyword = ucwords(strtolower($keyword)); \n\n?>",
      "language": "php"
    }
  ]
}
[/block]

[block:code]
{
  "codes": [
    {
      "code": "<html>\n    <head>\n        <!-- Display the Dynamic Keyword in the Title! -->\n        <title><? echo $keyword; ?></title>\n    </head>\n    <body>\n    \n        <!-- Display the Dynamic Keyword in the body's content! -->\n        This is the content on my landing page! You were searching for <? echo $keyword; ?>.\n    \n    </body>\n</html> ",
      "language": "html"
    }
  ]
}
[/block]
Anywhere you now call <? echo $keyword; ?> in your .php file, it will print out the dynamic keyword insertion!

## Landing Page Load Time Analysis

With quality scoring, taking into account how fast your landing page loads, is increasingly more important to build faster loading landing pages. And to analyze that we have a simple script, the same one we use on Prosper202.com (see below) that displays at the bottom of each page: how long it took to load. Below is the script that shows how to set this up.

**Landing Page Code** 
[block:code]
{
  "codes": [
    {
      "code": "<? $microtimer = microtime();  /*set the timer, this is to be placed at the top! */  ?>",
      "language": "php"
    }
  ]
}
[/block]

[block:code]
{
  "codes": [
    {
      "code": "<html>\n    <head>\n        <title>Title</title>\n    </head>\n    <body>\n    \n        Blah Blah Blah, this is my content!\n        \n        <? //print on the screen how long this page to took to load\n        $seconds =  microtime() - $microtimer;\n        echo 'This page took ' . round($seconds,3) . ' seconds to load.'; ?>\n    \n    </body>\n</html> ",
      "language": "html"
    }
  ]
}
[/block]
So now at the bottom of each page it will say: This page took xxxxx seconds to load.