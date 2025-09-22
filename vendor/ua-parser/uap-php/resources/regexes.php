<?php
declare(strict_types=1);
return  [
  'user_agent_parsers' =>
   [
    0 =>
     [
      'regex' => '^(Luminary)[Stage]+/(\\d+) CFNetwork',
    ],
    1 =>
     [
      'regex' => '(ESPN)[%20| ]+Radio/(\\d+)\\.(\\d+)\\.(\\d+) CFNetwork',
    ],
    2 =>
     [
      'regex' => '(Antenna)/(\\d+) CFNetwork',
      'family_replacement' => 'AntennaPod',
    ],
    3 =>
     [
      'regex' => '(TopPodcasts)Pro/(\\d+) CFNetwork',
    ],
    4 =>
     [
      'regex' => '(MusicDownloader)Lite/(\\d+)\\.(\\d+)\\.(\\d+) CFNetwork',
    ],
    5 =>
     [
      'regex' => '^(.*)-iPad\\/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)(?:\\.(\\d+)|) CFNetwork',
    ],
    6 =>
     [
      'regex' => '^(.*)-iPhone/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)(?:\\.(\\d+)|) CFNetwork',
    ],
    7 =>
     [
      'regex' => '^(.*)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)(?:\\.(\\d+)|) CFNetwork',
    ],
    8 =>
     [
      'regex' => '^(Luminary)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    9 =>
     [
      'regex' => '(espn\\.go)',
      'family_replacement' => 'ESPN',
    ],
    10 =>
     [
      'regex' => '(espnradio\\.com)',
      'family_replacement' => 'ESPN',
    ],
    11 =>
     [
      'regex' => 'ESPN APP$',
      'family_replacement' => 'ESPN',
    ],
    12 =>
     [
      'regex' => '(audioboom\\.com)',
      'family_replacement' => 'AudioBoom',
    ],
    13 =>
     [
      'regex' => ' (Rivo) RHYTHM',
    ],
    14 =>
     [
      'regex' => '(CFNetwork)(?:/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)|)',
      'family_replacement' => 'CFNetwork',
    ],
    15 =>
     [
      'regex' => '(Pingdom\\.com_bot_version_)(\\d+)\\.(\\d+)',
      'family_replacement' => 'PingdomBot',
    ],
    16 =>
     [
      'regex' => '(PingdomTMS)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'PingdomBot',
    ],
    17 =>
     [
      'regex' => ' (PTST)/(\\d+)(?:\\.(\\d+)|)$',
      'family_replacement' => 'WebPageTest.org bot',
    ],
    18 =>
     [
      'regex' => 'X11; (Datanyze); Linux',
    ],
    19 =>
     [
      'regex' => '(NewRelicPinger)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'NewRelicPingerBot',
    ],
    20 =>
     [
      'regex' => '(Tableau)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Tableau',
    ],
    21 =>
     [
      'regex' => 'AppleWebKit/\\d+\\.\\d+.* Safari.* (CreativeCloud)/(\\d+)\\.(\\d+).(\\d+)',
      'family_replacement' => 'Adobe CreativeCloud',
    ],
    22 =>
     [
      'regex' => '(Salesforce)(?:.)\\/(\\d+)\\.(\\d?)',
    ],
    23 =>
     [
      'regex' => '(\\(StatusCake\\))',
      'family_replacement' => 'StatusCakeBot',
    ],
    24 =>
     [
      'regex' => '(facebookexternalhit)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'FacebookBot',
    ],
    25 =>
     [
      'regex' => 'Google.*/\\+/web/snippet',
      'family_replacement' => 'GooglePlusBot',
    ],
    26 =>
     [
      'regex' => 'via ggpht\\.com GoogleImageProxy',
      'family_replacement' => 'GmailImageProxy',
    ],
    27 =>
     [
      'regex' => 'YahooMailProxy; https://help\\.yahoo\\.com/kb/yahoo-mail-proxy-SLN28749\\.html',
      'family_replacement' => 'YahooMailProxy',
    ],
    28 =>
     [
      'regex' => '(Twitterbot)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Twitterbot',
    ],
    29 =>
     [
      'regex' => '/((?:Ant-|)Nutch|[A-z]+[Bb]ot|[A-z]+[Ss]pider|Axtaris|fetchurl|Isara|ShopSalad|Tailsweep)[ \\-](\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    30 =>
     [
      'regex' => '\\b(008|Altresium|Argus|BaiduMobaider|BoardReader|DNSGroup|DataparkSearch|EDI|Goodzer|Grub|INGRID|Infohelfer|LinkedInBot|LOOQ|Nutch|OgScrper|PathDefender|Peew|PostPost|Steeler|Twitterbot|VSE|WebCrunch|WebZIP|Y!J-BR[A-Z]|YahooSeeker|envolk|sproose|wminer)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    31 =>
     [
      'regex' => '(MSIE) (\\d+)\\.(\\d+)([a-z]\\d|[a-z]|);.* MSIECrawler',
      'family_replacement' => 'MSIECrawler',
    ],
    32 =>
     [
      'regex' => '(DAVdroid)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    33 =>
     [
      'regex' => '(Google-HTTP-Java-Client|Apache-HttpClient|Go-http-client|scalaj-http|http%20client|Python-urllib|HttpMonitor|TLSProber|WinHTTP|JNLP|okhttp|aihttp|reqwest|axios|unirest-(?:java|python|ruby|nodejs|php|net))(?:[ /](\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
    ],
    34 =>
     [
      'regex' => '(Pinterest(?:bot|))/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)[;\\s(]+\\+https://www.pinterest.com/bot.html',
      'family_replacement' => 'Pinterestbot',
    ],
    35 =>
     [
      'regex' => '(CSimpleSpider|Cityreview Robot|CrawlDaddy|CrawlFire|Finderbots|Index crawler|Job Roboter|KiwiStatus Spider|Lijit Crawler|QuerySeekerSpider|ScollSpider|Trends Crawler|USyd-NLP-Spider|SiteCat Webbot|BotName\\/\\$BotVersion|123metaspider-Bot|1470\\.net crawler|50\\.nu|8bo Crawler Bot|Aboundex|Accoona-[A-z]{1,30}-Agent|AdsBot-Google(?:-[a-z]{1,30}|)|altavista|AppEngine-Google|archive.{0,30}\\.org_bot|archiver|Ask Jeeves|[Bb]ai[Dd]u[Ss]pider(?:-[A-Za-z]{1,30})(?:-[A-Za-z]{1,30}|)|bingbot|BingPreview|blitzbot|BlogBridge|Bloglovin|BoardReader Blog Indexer|BoardReader Favicon Fetcher|boitho.com-dc|BotSeer|BUbiNG|\\b\\w{0,30}favicon\\w{0,30}\\b|\\bYeti(?:-[a-z]{1,30}|)|Catchpoint(?: bot|)|[Cc]harlotte|Checklinks|clumboot|Comodo HTTP\\(S\\) Crawler|Comodo-Webinspector-Crawler|ConveraCrawler|CRAWL-E|CrawlConvera|Daumoa(?:-feedfetcher|)|Feed Seeker Bot|Feedbin|findlinks|Flamingo_SearchEngine|FollowSite Bot|furlbot|Genieo|gigabot|GomezAgent|gonzo1|(?:[a-zA-Z]{1,30}-|)Googlebot(?:-[a-zA-Z]{1,30}|)|Google SketchUp|grub-client|gsa-crawler|heritrix|HiddenMarket|holmes|HooWWWer|htdig|ia_archiver|ICC-Crawler|Icarus6j|ichiro(?:/mobile|)|IconSurf|IlTrovatore(?:-Setaccio|)|InfuzApp|Innovazion Crawler|InternetArchive|IP2[a-z]{1,30}Bot|jbot\\b|KaloogaBot|Kraken|Kurzor|larbin|LEIA|LesnikBot|Linguee Bot|LinkAider|LinkedInBot|Lite Bot|Llaut|lycos|Mail\\.RU_Bot|masscan|masidani_bot|Mediapartners-Google|Microsoft .{0,30} Bot|mogimogi|mozDex|MJ12bot|msnbot(?:-media {0,2}|)|msrbot|Mtps Feed Aggregation System|netresearch|Netvibes|NewsGator[^/]{0,30}|^NING|Nutch[^/]{0,30}|Nymesis|ObjectsSearch|OgScrper|Orbiter|OOZBOT|PagePeeker|PagesInventory|PaxleFramework|Peeplo Screenshot Bot|PlantyNet_WebRobot|Pompos|Qwantify|Read%20Later|Reaper|RedCarpet|Retreiver|Riddler|Rival IQ|scooter|Scrapy|Scrubby|searchsight|seekbot|semanticdiscovery|SemrushBot|Simpy|SimplePie|SEOstats|SimpleRSS|SiteCon|Slackbot-LinkExpanding|Slack-ImgProxy|Slurp|snappy|Speedy Spider|Squrl Java|Stringer|TheUsefulbot|ThumbShotsBot|Thumbshots\\.ru|Tiny Tiny RSS|Twitterbot|WhatsApp|URL2PNG|Vagabondo|VoilaBot|^vortex|Votay bot|^voyager|WASALive.Bot|Web-sniffer|WebThumb|WeSEE:[A-z]{1,30}|WhatWeb|WIRE|WordPress|Wotbox|www\\.almaden\\.ibm\\.com|Xenu(?:.s|) Link Sleuth|Xerka [A-z]{1,30}Bot|yacy(?:bot|)|YahooSeeker|Yahoo! Slurp|Yandex\\w{1,30}|YodaoBot(?:-[A-z]{1,30}|)|YottaaMonitor|Yowedo|^Zao|^Zao-Crawler|ZeBot_www\\.ze\\.bz|ZooShot|ZyBorg)(?:[ /]v?(\\d+)(?:\\.(\\d+)(?:\\.(\\d+)|)|)|)',
    ],
    36 =>
     [
      'regex' => '\\b(Boto3?|JetS3t|aws-(?:cli|sdk-(?:cpp|go|java|nodejs|ruby2?|dotnet-(?:\\d{1,2}|core)))|s3fs)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    37 =>
     [
      'regex' => '\\[(FBAN/MessengerForiOS|FB_IAB/MESSENGER);FBAV/(\\d+)(?:\\.(\\d+)(?:\\.(\\d+)|)|)',
      'family_replacement' => 'Facebook Messenger',
    ],
    38 =>
     [
      'regex' => '\\[FB.*;(FBAV)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
      'family_replacement' => 'Facebook',
    ],
    39 =>
     [
      'regex' => '\\[FB.*;',
      'family_replacement' => 'Facebook',
    ],
    40 =>
     [
      'regex' => '(?:\\/[A-Za-z0-9\\.]+|) {0,5}([A-Za-z0-9 \\-_\\!\\[\\]:]{0,50}(?:[Aa]rchiver|[Ii]ndexer|[Ss]craper|[Bb]ot|[Ss]pider|[Cc]rawl[a-z]{0,50}))[/ ](\\d+)(?:\\.(\\d+)(?:\\.(\\d+)|)|)',
    ],
    41 =>
     [
      'regex' => '((?:[A-Za-z][A-Za-z0-9 -]{0,50}|)[^C][^Uu][Bb]ot)\\b(?:(?:[ /]| v)(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
    ],
    42 =>
     [
      'regex' => '((?:[A-z0-9]{1,50}|[A-z\\-]{1,50} ?|)(?: the |)(?:[Ss][Pp][Ii][Dd][Ee][Rr]|[Ss]crape|[Cc][Rr][Aa][Ww][Ll])[A-z0-9]{0,50})(?:(?:[ /]| v)(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
    ],
    43 =>
     [
      'regex' => '(HbbTV)/(\\d+)\\.(\\d+)\\.(\\d+) \\(',
    ],
    44 =>
     [
      'regex' => '(Chimera|SeaMonkey|Camino|Waterfox)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+[a-z]*|)',
    ],
    45 =>
     [
      'regex' => '(SailfishBrowser)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Sailfish Browser',
    ],
    46 =>
     [
      'regex' => '\\[(Pinterest)/[^\\]]+\\]',
    ],
    47 =>
     [
      'regex' => '(Pinterest)(?: for Android(?: Tablet|)|)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    48 =>
     [
      'regex' => 'Mozilla.*Mobile.*(Instagram).(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    49 =>
     [
      'regex' => 'Mozilla.*Mobile.*(Flipboard).(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    50 =>
     [
      'regex' => 'Mozilla.*Mobile.*(Flipboard-Briefing).(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    51 =>
     [
      'regex' => 'Mozilla.*Mobile.*(Onefootball)\\/Android.(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    52 =>
     [
      'regex' => '(Snapchat)\\/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    53 =>
     [
      'regex' => '(Twitter for (?:iPhone|iPad)|TwitterAndroid)(?:\\/(\\d+)\\.(\\d+)|)',
      'family_replacement' => 'Twitter',
    ],
    54 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+) Basilisk/(\\d+)',
      'family_replacement' => 'Basilisk',
    ],
    55 =>
     [
      'regex' => '(PaleMoon)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Pale Moon',
    ],
    56 =>
     [
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+[a-z]*)',
      'family_replacement' => 'Firefox Mobile',
    ],
    57 =>
     [
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)(pre)',
      'family_replacement' => 'Firefox Mobile',
    ],
    58 =>
     [
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Firefox Mobile',
    ],
    59 =>
     [
      'regex' => '(?:Mobile|Tablet);.*(Firefox)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Firefox Mobile',
    ],
    60 =>
     [
      'regex' => '(Namoroka|Shiretoko|Minefield)/(\\d+)\\.(\\d+)\\.(\\d+(?:pre|))',
      'family_replacement' => 'Firefox ($1)',
    ],
    61 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(a\\d+[a-z]*)',
      'family_replacement' => 'Firefox Alpha',
    ],
    62 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(b\\d+[a-z]*)',
      'family_replacement' => 'Firefox Beta',
    ],
    63 =>
     [
      'regex' => '(Firefox)-(?:\\d+\\.\\d+|)/(\\d+)\\.(\\d+)(a\\d+[a-z]*)',
      'family_replacement' => 'Firefox Alpha',
    ],
    64 =>
     [
      'regex' => '(Firefox)-(?:\\d+\\.\\d+|)/(\\d+)\\.(\\d+)(b\\d+[a-z]*)',
      'family_replacement' => 'Firefox Beta',
    ],
    65 =>
     [
      'regex' => '(Namoroka|Shiretoko|Minefield)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*|)',
      'family_replacement' => 'Firefox ($1)',
    ],
    66 =>
     [
      'regex' => '(Firefox).*Tablet browser (\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'MicroB',
    ],
    67 =>
     [
      'regex' => '(MozillaDeveloperPreview)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*|)',
    ],
    68 =>
     [
      'regex' => '(FxiOS)/(\\d+)\\.(\\d+)(\\.(\\d+)|)(\\.(\\d+)|)',
      'family_replacement' => 'Firefox iOS',
    ],
    69 =>
     [
      'regex' => '(Flock)/(\\d+)\\.(\\d+)(b\\d+?)',
    ],
    70 =>
     [
      'regex' => '(RockMelt)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    71 =>
     [
      'regex' => '(Navigator)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Netscape',
    ],
    72 =>
     [
      'regex' => '(Navigator)/(\\d+)\\.(\\d+)([ab]\\d+)',
      'family_replacement' => 'Netscape',
    ],
    73 =>
     [
      'regex' => '(Netscape6)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+|)',
      'family_replacement' => 'Netscape',
    ],
    74 =>
     [
      'regex' => '(MyIBrow)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'My Internet Browser',
    ],
    75 =>
     [
      'regex' => '(UC? ?Browser|UCWEB|U3)[ /]?(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'UC Browser',
    ],
    76 =>
     [
      'regex' => '(Opera Tablet).*Version/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    77 =>
     [
      'regex' => '(Opera Mini)(?:/att|)/?(\\d+|)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    78 =>
     [
      'regex' => '(Opera)/.+Opera Mobi.+Version/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ],
    79 =>
     [
      'regex' => '(Opera)/(\\d+)\\.(\\d+).+Opera Mobi',
      'family_replacement' => 'Opera Mobile',
    ],
    80 =>
     [
      'regex' => 'Opera Mobi.+(Opera)(?:/|\\s+)(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ],
    81 =>
     [
      'regex' => 'Opera Mobi',
      'family_replacement' => 'Opera Mobile',
    ],
    82 =>
     [
      'regex' => '(Opera)/9.80.*Version/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    83 =>
     [
      'regex' => '(?:Mobile Safari).*(OPR)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ],
    84 =>
     [
      'regex' => '(?:Chrome).*(OPR)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera',
    ],
    85 =>
     [
      'regex' => '(Coast)/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'Opera Coast',
    ],
    86 =>
     [
      'regex' => '(OPiOS)/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'Opera Mini',
    ],
    87 =>
     [
      'regex' => 'Chrome/.+( MMS)/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'Opera Neon',
    ],
    88 =>
     [
      'regex' => '(hpw|web)OS/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'webOS Browser',
    ],
    89 =>
     [
      'regex' => '(luakit)',
      'family_replacement' => 'LuaKit',
    ],
    90 =>
     [
      'regex' => '(Snowshoe)/(\\d+)\\.(\\d+).(\\d+)',
    ],
    91 =>
     [
      'regex' => 'Gecko/\\d+ (Lightning)/(\\d+)\\.(\\d+)\\.?((?:[ab]?\\d+[a-z]*)|(?:\\d*))',
    ],
    92 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)\\.(\\d+(?:pre|)) \\(Swiftfox\\)',
      'family_replacement' => 'Swiftfox',
    ],
    93 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*|) \\(Swiftfox\\)',
      'family_replacement' => 'Swiftfox',
    ],
    94 =>
     [
      'regex' => '(rekonq)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|) Safari',
      'family_replacement' => 'Rekonq',
    ],
    95 =>
     [
      'regex' => 'rekonq',
      'family_replacement' => 'Rekonq',
    ],
    96 =>
     [
      'regex' => '(conkeror|Conkeror)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Conkeror',
    ],
    97 =>
     [
      'regex' => '(konqueror)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Konqueror',
    ],
    98 =>
     [
      'regex' => '(WeTab)-Browser',
    ],
    99 =>
     [
      'regex' => '(Comodo_Dragon)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Comodo Dragon',
    ],
    100 =>
     [
      'regex' => '(Symphony) (\\d+).(\\d+)',
    ],
    101 =>
     [
      'regex' => 'PLAYSTATION 3.+WebKit',
      'family_replacement' => 'NetFront NX',
    ],
    102 =>
     [
      'regex' => 'PLAYSTATION 3',
      'family_replacement' => 'NetFront',
    ],
    103 =>
     [
      'regex' => '(PlayStation Portable)',
      'family_replacement' => 'NetFront',
    ],
    104 =>
     [
      'regex' => '(PlayStation Vita)',
      'family_replacement' => 'NetFront NX',
    ],
    105 =>
     [
      'regex' => 'AppleWebKit.+ (NX)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'NetFront NX',
    ],
    106 =>
     [
      'regex' => '(Nintendo 3DS)',
      'family_replacement' => 'NetFront NX',
    ],
    107 =>
     [
      'regex' => '(Silk)/(\\d+)\\.(\\d+)(?:\\.([0-9\\-]+)|)',
      'family_replacement' => 'Amazon Silk',
    ],
    108 =>
     [
      'regex' => '(Puffin)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    109 =>
     [
      'regex' => 'Windows Phone .*(Edge)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Edge Mobile',
    ],
    110 =>
     [
      'regex' => '(EdgA)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Edge Mobile',
    ],
    111 =>
     [
      'regex' => '(EdgiOS)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Edge Mobile',
    ],
    112 =>
     [
      'regex' => '(SamsungBrowser)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Samsung Internet',
    ],
    113 =>
     [
      'regex' => '(SznProhlizec)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Seznam prohlížeč',
    ],
    114 =>
     [
      'regex' => '(coc_coc_browser)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Coc Coc',
    ],
    115 =>
     [
      'regex' => '(baidubrowser)[/\\s](\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
      'family_replacement' => 'Baidu Browser',
    ],
    116 =>
     [
      'regex' => '(FlyFlow)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Baidu Explorer',
    ],
    117 =>
     [
      'regex' => '(MxBrowser)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Maxthon',
    ],
    118 =>
     [
      'regex' => '(Crosswalk)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    119 =>
     [
      'regex' => '(Line)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'LINE',
    ],
    120 =>
     [
      'regex' => '(MiuiBrowser)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'MiuiBrowser',
    ],
    121 =>
     [
      'regex' => '(Mint Browser)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mint Browser',
    ],
    122 =>
     [
      'regex' => '(TopBuzz)/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'TopBuzz',
    ],
    123 =>
     [
      'regex' => 'Mozilla.+Android.+(GSA)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Google',
    ],
    124 =>
     [
      'regex' => '(MQQBrowser/Mini)(?:(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
      'family_replacement' => 'QQ Browser Mini',
    ],
    125 =>
     [
      'regex' => '(MQQBrowser)(?:/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
      'family_replacement' => 'QQ Browser Mobile',
    ],
    126 =>
     [
      'regex' => '(QQBrowser)(?:/(\\d+)(?:\\.(\\d+)\\.(\\d+)(?:\\.(\\d+)|)|)|)',
      'family_replacement' => 'QQ Browser',
    ],
    127 =>
     [
      'regex' => 'Version/.+(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile WebView',
    ],
    128 =>
     [
      'regex' => '; wv\\).+(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile WebView',
    ],
    129 =>
     [
      'regex' => '(CrMo)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile',
    ],
    130 =>
     [
      'regex' => '(CriOS)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile iOS',
    ],
    131 =>
     [
      'regex' => '(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+) Mobile(?:[ /]|$)',
      'family_replacement' => 'Chrome Mobile',
    ],
    132 =>
     [
      'regex' => ' Mobile .*(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile',
    ],
    133 =>
     [
      'regex' => '(chromeframe)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Frame',
    ],
    134 =>
     [
      'regex' => '(SLP Browser)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Tizen Browser',
    ],
    135 =>
     [
      'regex' => '(SE 2\\.X) MetaSr (\\d+)\\.(\\d+)',
      'family_replacement' => 'Sogou Explorer',
    ],
    136 =>
     [
      'regex' => '(Rackspace Monitoring)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'RackspaceBot',
    ],
    137 =>
     [
      'regex' => '(PyAMF)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    138 =>
     [
      'regex' => '(YaBrowser)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Yandex Browser',
    ],
    139 =>
     [
      'regex' => '(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+).* MRCHROME',
      'family_replacement' => 'Mail.ru Chromium Browser',
    ],
    140 =>
     [
      'regex' => '(AOL) (\\d+)\\.(\\d+); AOLBuild (\\d+)',
    ],
    141 =>
     [
      'regex' => '(PodCruncher|Downcast)[ /]?(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    142 =>
     [
      'regex' => ' (BoxNotes)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    143 =>
     [
      'regex' => '(Whale)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+) Mobile(?:[ /]|$)',
      'family_replacement' => 'Whale',
    ],
    144 =>
     [
      'regex' => '(Whale)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Whale',
    ],
    145 =>
     [
      'regex' => '(1Password)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    146 =>
     [
      'regex' => '(Ghost)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    147 =>
     [
      'regex' => '(Slack_SSB)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Slack Desktop Client',
    ],
    148 =>
     [
      'regex' => '(HipChat)/?(\\d+|)',
      'family_replacement' => 'HipChat Desktop Client',
    ],
    149 =>
     [
      'regex' => '\\b(MobileIron|FireWeb|Jasmine|ANTGalio|Midori|Fresco|Lobo|PaleMoon|Maxthon|Lynx|OmniWeb|Dillo|Camino|Demeter|Fluid|Fennec|Epiphany|Shiira|Sunrise|Spotify|Flock|Netscape|Lunascape|WebPilot|NetFront|Netfront|Konqueror|SeaMonkey|Kazehakase|Vienna|Iceape|Iceweasel|IceWeasel|Iron|K-Meleon|Sleipnir|Galeon|GranParadiso|Opera Mini|iCab|NetNewsWire|ThunderBrowse|Iris|UP\\.Browser|Bunjalloo|Google Earth|Raven for Mac|Openwave|MacOutlook|Electron|OktaMobile)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    150 =>
     [
      'regex' => 'Microsoft Office Outlook 12\\.\\d+\\.\\d+|MSOffice 12',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2007',
    ],
    151 =>
     [
      'regex' => 'Microsoft Outlook 14\\.\\d+\\.\\d+|MSOffice 14',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2010',
    ],
    152 =>
     [
      'regex' => 'Microsoft Outlook 15\\.\\d+\\.\\d+',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2013',
    ],
    153 =>
     [
      'regex' => 'Microsoft Outlook (?:Mail )?16\\.\\d+\\.\\d+|MSOffice 16',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2016',
    ],
    154 =>
     [
      'regex' => 'Microsoft Office (Word) 2014',
    ],
    155 =>
     [
      'regex' => 'Outlook-Express\\/7\\.0.*',
      'family_replacement' => 'Windows Live Mail',
    ],
    156 =>
     [
      'regex' => '(Airmail) (\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    157 =>
     [
      'regex' => '(Thunderbird)/(\\d+)\\.(\\d+)(?:\\.(\\d+(?:pre|))|)',
      'family_replacement' => 'Thunderbird',
    ],
    158 =>
     [
      'regex' => '(Postbox)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Postbox',
    ],
    159 =>
     [
      'regex' => '(Barca(?:Pro)?)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Barca',
    ],
    160 =>
     [
      'regex' => '(Lotus-Notes)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Lotus Notes',
    ],
    161 =>
     [
      'regex' => 'Superhuman',
      'family_replacement' => 'Superhuman',
    ],
    162 =>
     [
      'regex' => '(Vivaldi)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    163 =>
     [
      'regex' => '(Edge?)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
      'family_replacement' => 'Edge',
    ],
    164 =>
     [
      'regex' => '(brave)/(\\d+)\\.(\\d+)\\.(\\d+) Chrome',
      'family_replacement' => 'Brave',
    ],
    165 =>
     [
      'regex' => '(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)[\\d.]* Iron[^/]',
      'family_replacement' => 'Iron',
    ],
    166 =>
     [
      'regex' => '\\b(Dolphin)(?: |HDCN/|/INT\\-)(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    167 =>
     [
      'regex' => '(HeadlessChrome)(?:/(\\d+)\\.(\\d+)\\.(\\d+)|)',
    ],
    168 =>
     [
      'regex' => '(Evolution)/(\\d+)\\.(\\d+)\\.(\\d+\\.\\d+)',
    ],
    169 =>
     [
      'regex' => '(RCM CardDAV plugin)/(\\d+)\\.(\\d+)\\.(\\d+(?:-dev|))',
    ],
    170 =>
     [
      'regex' => '(bingbot|Bolt|AdobeAIR|Jasmine|IceCat|Skyfire|Midori|Maxthon|Lynx|Arora|IBrowse|Dillo|Camino|Shiira|Fennec|Phoenix|Flock|Netscape|Lunascape|Epiphany|WebPilot|Opera Mini|Opera|NetFront|Netfront|Konqueror|Googlebot|SeaMonkey|Kazehakase|Vienna|Iceape|Iceweasel|IceWeasel|Iron|K-Meleon|Sleipnir|Galeon|GranParadiso|iCab|iTunes|MacAppStore|NetNewsWire|Space Bison|Stainless|Orca|Dolfin|BOLT|Minimo|Tizen Browser|Polaris|Abrowser|Planetweb|ICE Browser|mDolphin|qutebrowser|Otter|QupZilla|MailBar|kmail2|YahooMobileMail|ExchangeWebServices|ExchangeServicesClient|Dragon|Outlook-iOS-Android)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    171 =>
     [
      'regex' => '(Chromium|Chrome)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    172 =>
     [
      'regex' => '(IEMobile)[ /](\\d+)\\.(\\d+)',
      'family_replacement' => 'IE Mobile',
    ],
    173 =>
     [
      'regex' => '(BacaBerita App)\\/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    174 =>
     [
      'regex' => '^(bPod|Pocket Casts|Player FM)$',
    ],
    175 =>
     [
      'regex' => '^(AlexaMediaPlayer|VLC)/(\\d+)\\.(\\d+)\\.([^.\\s]+)',
    ],
    176 =>
     [
      'regex' => '^(AntennaPod|WMPlayer|Zune|Podkicker|Radio|ExoPlayerDemo|Overcast|PocketTunes|NSPlayer|okhttp|DoggCatcher|QuickNews|QuickTime|Peapod|Podcasts|GoldenPod|VLC|Spotify|Miro|MediaGo|Juice|iPodder|gPodder|Banshee)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    177 =>
     [
      'regex' => '^(Peapod|Liferea)/([^.\\s]+)\\.([^.\\s]+|)\\.?([^.\\s]+|)',
    ],
    178 =>
     [
      'regex' => '^(bPod|Player FM) BMID/(\\S+)',
    ],
    179 =>
     [
      'regex' => '^(Podcast ?Addict)/v(\\d+) ',
    ],
    180 =>
     [
      'regex' => '^(Podcast ?Addict) ',
      'family_replacement' => 'PodcastAddict',
    ],
    181 =>
     [
      'regex' => '(Replay) AV',
    ],
    182 =>
     [
      'regex' => '(VOX) Music Player',
    ],
    183 =>
     [
      'regex' => '(CITA) RSS Aggregator/(\\d+)\\.(\\d+)',
    ],
    184 =>
     [
      'regex' => '(Pocket Casts)$',
    ],
    185 =>
     [
      'regex' => '(Player FM)$',
    ],
    186 =>
     [
      'regex' => '(LG Player|Doppler|FancyMusic|MediaMonkey|Clementine) (\\d+)\\.(\\d+)\\.?([^.\\s]+|)\\.?([^.\\s]+|)',
    ],
    187 =>
     [
      'regex' => '(philpodder)/(\\d+)\\.(\\d+)\\.?([^.\\s]+|)\\.?([^.\\s]+|)',
    ],
    188 =>
     [
      'regex' => '(Player FM|Pocket Casts|DoggCatcher|Spotify|MediaMonkey|MediaGo|BashPodder)',
    ],
    189 =>
     [
      'regex' => '(QuickTime)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    190 =>
     [
      'regex' => '(Kinoma)(\\d+)',
    ],
    191 =>
     [
      'regex' => '(Fancy) Cloud Music (\\d+)\\.(\\d+)',
      'family_replacement' => 'FancyMusic',
    ],
    192 =>
     [
      'regex' => 'EspnDownloadManager',
      'family_replacement' => 'ESPN',
    ],
    193 =>
     [
      'regex' => '(ESPN) Radio (\\d+)\\.(\\d+)(?:\\.(\\d+)|) ?(?:rv:(\\d+)|) ',
    ],
    194 =>
     [
      'regex' => '(podracer|jPodder) v ?(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    195 =>
     [
      'regex' => '(ZDM)/(\\d+)\\.(\\d+)[; ]?',
    ],
    196 =>
     [
      'regex' => '(Zune|BeyondPod) (\\d+)(?:\\.(\\d+)|)[\\);]',
    ],
    197 =>
     [
      'regex' => '(WMPlayer)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    198 =>
     [
      'regex' => '^(Lavf)',
      'family_replacement' => 'WMPlayer',
    ],
    199 =>
     [
      'regex' => '^(RSSRadio)[ /]?(\\d+|)',
    ],
    200 =>
     [
      'regex' => '(RSS_Radio) (\\d+)\\.(\\d+)',
      'family_replacement' => 'RSSRadio',
    ],
    201 =>
     [
      'regex' => '(Podkicker) \\S+/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Podkicker',
    ],
    202 =>
     [
      'regex' => '^(HTC) Streaming Player \\S+ / \\S+ / \\S+ / (\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    203 =>
     [
      'regex' => '^(Stitcher)/iOS',
    ],
    204 =>
     [
      'regex' => '^(Stitcher)/Android',
    ],
    205 =>
     [
      'regex' => '^(VLC) .*version (\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    206 =>
     [
      'regex' => ' (VLC) for',
    ],
    207 =>
     [
      'regex' => '(vlc)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'VLC',
    ],
    208 =>
     [
      'regex' => '^(foobar)\\S+/([^.\\s]+)\\.([^.\\s]+|)\\.?([^.\\s]+|)',
    ],
    209 =>
     [
      'regex' => '^(Clementine)\\S+ ([^.\\s]+)\\.([^.\\s]+|)\\.?([^.\\s]+|)',
    ],
    210 =>
     [
      'regex' => '(amarok)/([^.\\s]+)\\.([^.\\s]+|)\\.?([^.\\s]+|)',
      'family_replacement' => 'Amarok',
    ],
    211 =>
     [
      'regex' => '(Custom)-Feed Reader',
    ],
    212 =>
     [
      'regex' => '(iRider|Crazy Browser|SkipStone|iCab|Lunascape|Sleipnir|Maemo Browser) (\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    213 =>
     [
      'regex' => '(iCab|Lunascape|Opera|Android|Jasmine|Polaris|Microsoft SkyDriveSync|The Bat!) (\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    214 =>
     [
      'regex' => '(Kindle)/(\\d+)\\.(\\d+)',
    ],
    215 =>
     [
      'regex' => '(Android) Donut',
      'v1_replacement' => '1',
      'v2_replacement' => '2',
    ],
    216 =>
     [
      'regex' => '(Android) Eclair',
      'v1_replacement' => '2',
      'v2_replacement' => '1',
    ],
    217 =>
     [
      'regex' => '(Android) Froyo',
      'v1_replacement' => '2',
      'v2_replacement' => '2',
    ],
    218 =>
     [
      'regex' => '(Android) Gingerbread',
      'v1_replacement' => '2',
      'v2_replacement' => '3',
    ],
    219 =>
     [
      'regex' => '(Android) Honeycomb',
      'v1_replacement' => '3',
    ],
    220 =>
     [
      'regex' => '(MSIE) (\\d+)\\.(\\d+).*XBLWP7',
      'family_replacement' => 'IE Large Screen',
    ],
    221 =>
     [
      'regex' => '(Nextcloud)',
    ],
    222 =>
     [
      'regex' => '(mirall)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    223 =>
     [
      'regex' => '(ownCloud-android)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Owncloud',
    ],
    224 =>
     [
      'regex' => '(OC)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+) \\(Skype for Business\\)',
      'family_replacement' => 'Skype',
    ],
    225 =>
     [
      'regex' => '(Obigo)InternetBrowser',
    ],
    226 =>
     [
      'regex' => '(Obigo)\\-Browser',
    ],
    227 =>
     [
      'regex' => '(Obigo|OBIGO)[^\\d]*(\\d+)(?:.(\\d+)|)',
      'family_replacement' => 'Obigo',
    ],
    228 =>
     [
      'regex' => '(MAXTHON|Maxthon) (\\d+)\\.(\\d+)',
      'family_replacement' => 'Maxthon',
    ],
    229 =>
     [
      'regex' => '(Maxthon|MyIE2|Uzbl|Shiira)',
      'v1_replacement' => '0',
    ],
    230 =>
     [
      'regex' => '(BrowseX) \\((\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    231 =>
     [
      'regex' => '(NCSA_Mosaic)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'NCSA Mosaic',
    ],
    232 =>
     [
      'regex' => '(POLARIS)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Polaris',
    ],
    233 =>
     [
      'regex' => '(Embider)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Polaris',
    ],
    234 =>
     [
      'regex' => '(BonEcho)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+|)',
      'family_replacement' => 'Bon Echo',
    ],
    235 =>
     [
      'regex' => '(TopBuzz) com.alex.NewsMaster/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'TopBuzz',
    ],
    236 =>
     [
      'regex' => '(TopBuzz) com.mobilesrepublic.newsrepublic/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'TopBuzz',
    ],
    237 =>
     [
      'regex' => '(TopBuzz) com.topbuzz.videoen/(\\d+).(\\d+).(\\d+)',
      'family_replacement' => 'TopBuzz',
    ],
    238 =>
     [
      'regex' => '(iPod|iPhone|iPad).+GSA/(\\d+)\\.(\\d+)\\.(\\d+) Mobile',
      'family_replacement' => 'Google',
    ],
    239 =>
     [
      'regex' => '(iPod|iPhone|iPad).+Version/(\\d+)\\.(\\d+)(?:\\.(\\d+)|).*[ +]Safari',
      'family_replacement' => 'Mobile Safari',
    ],
    240 =>
     [
      'regex' => '(iPod|iPod touch|iPhone|iPad);.*CPU.*OS[ +](\\d+)_(\\d+)(?:_(\\d+)|).* AppleNews\\/\\d+\\.\\d+\\.\\d+?',
      'family_replacement' => 'Mobile Safari UI/WKWebView',
    ],
    241 =>
     [
      'regex' => '(iPod|iPhone|iPad).+Version/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'family_replacement' => 'Mobile Safari UI/WKWebView',
    ],
    242 =>
     [
      'regex' => '(iPod|iPod touch|iPhone|iPad).* Safari',
      'family_replacement' => 'Mobile Safari',
    ],
    243 =>
     [
      'regex' => '(iPod|iPod touch|iPhone|iPad)',
      'family_replacement' => 'Mobile Safari UI/WKWebView',
    ],
    244 =>
     [
      'regex' => '(Watch)(\\d+),(\\d+)',
      'family_replacement' => 'Apple $1 App',
    ],
    245 =>
     [
      'regex' => '(Outlook-iOS)/\\d+\\.\\d+\\.prod\\.iphone \\((\\d+)\\.(\\d+)\\.(\\d+)\\)',
    ],
    246 =>
     [
      'regex' => '(AvantGo) (\\d+).(\\d+)',
    ],
    247 =>
     [
      'regex' => '(OneBrowser)/(\\d+).(\\d+)',
      'family_replacement' => 'ONE Browser',
    ],
    248 =>
     [
      'regex' => '(Avant)',
      'v1_replacement' => '1',
    ],
    249 =>
     [
      'regex' => '(QtCarBrowser)',
      'v1_replacement' => '1',
    ],
    250 =>
     [
      'regex' => '^(iBrowser/Mini)(\\d+).(\\d+)',
      'family_replacement' => 'iBrowser Mini',
    ],
    251 =>
     [
      'regex' => '^(iBrowser|iRAPP)/(\\d+).(\\d+)',
    ],
    252 =>
     [
      'regex' => '^(Nokia)',
      'family_replacement' => 'Nokia Services (WAP) Browser',
    ],
    253 =>
     [
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+).(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ],
    254 =>
     [
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+).(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ],
    255 =>
     [
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ],
    256 =>
     [
      'regex' => '(BrowserNG)/(\\d+)\\.(\\d+).(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ],
    257 =>
     [
      'regex' => '(Series60)/5\\.0',
      'family_replacement' => 'Nokia Browser',
      'v1_replacement' => '7',
      'v2_replacement' => '0',
    ],
    258 =>
     [
      'regex' => '(Series60)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia OSS Browser',
    ],
    259 =>
     [
      'regex' => '(S40OviBrowser)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Ovi Browser',
    ],
    260 =>
     [
      'regex' => '(Nokia)[EN]?(\\d+)',
    ],
    261 =>
     [
      'regex' => '(PlayBook).+RIM Tablet OS (\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'BlackBerry WebKit',
    ],
    262 =>
     [
      'regex' => '(Black[bB]erry|BB10).+Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'BlackBerry WebKit',
    ],
    263 =>
     [
      'regex' => '(Black[bB]erry)\\s?(\\d+)',
      'family_replacement' => 'BlackBerry',
    ],
    264 =>
     [
      'regex' => '(OmniWeb)/v(\\d+)\\.(\\d+)',
    ],
    265 =>
     [
      'regex' => '(Blazer)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Palm Blazer',
    ],
    266 =>
     [
      'regex' => '(Pre)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Palm Pre',
    ],
    267 =>
     [
      'regex' => '(ELinks)/(\\d+)\\.(\\d+)',
    ],
    268 =>
     [
      'regex' => '(ELinks) \\((\\d+)\\.(\\d+)',
    ],
    269 =>
     [
      'regex' => '(Links) \\((\\d+)\\.(\\d+)',
    ],
    270 =>
     [
      'regex' => '(QtWeb) Internet Browser/(\\d+)\\.(\\d+)',
    ],
    271 =>
     [
      'regex' => '(PhantomJS)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    272 =>
     [
      'regex' => '(AppleWebKit)/(\\d+)(?:\\.(\\d+)|)\\+ .* Safari',
      'family_replacement' => 'WebKit Nightly',
    ],
    273 =>
     [
      'regex' => '(Version)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|).*Safari/',
      'family_replacement' => 'Safari',
    ],
    274 =>
     [
      'regex' => '(Safari)/\\d+',
    ],
    275 =>
     [
      'regex' => '(OLPC)/Update(\\d+)\\.(\\d+)',
    ],
    276 =>
     [
      'regex' => '(OLPC)/Update()\\.(\\d+)',
      'v1_replacement' => '0',
    ],
    277 =>
     [
      'regex' => '(SEMC\\-Browser)/(\\d+)\\.(\\d+)',
    ],
    278 =>
     [
      'regex' => '(Teleca)',
      'family_replacement' => 'Teleca Browser',
    ],
    279 =>
     [
      'regex' => '(Phantom)/V(\\d+)\\.(\\d+)',
      'family_replacement' => 'Phantom Browser',
    ],
    280 =>
     [
      'regex' => '(Trident)/(7|8)\\.(0)',
      'family_replacement' => 'IE',
      'v1_replacement' => '11',
    ],
    281 =>
     [
      'regex' => '(Trident)/(6)\\.(0)',
      'family_replacement' => 'IE',
      'v1_replacement' => '10',
    ],
    282 =>
     [
      'regex' => '(Trident)/(5)\\.(0)',
      'family_replacement' => 'IE',
      'v1_replacement' => '9',
    ],
    283 =>
     [
      'regex' => '(Trident)/(4)\\.(0)',
      'family_replacement' => 'IE',
      'v1_replacement' => '8',
    ],
    284 =>
     [
      'regex' => '(Espial)/(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    285 =>
     [
      'regex' => '(AppleWebKit)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Apple Mail',
    ],
    286 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    287 =>
     [
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(pre|[ab]\\d+[a-z]*|)',
    ],
    288 =>
     [
      'regex' => '([MS]?IE) (\\d+)\\.(\\d+)',
      'family_replacement' => 'IE',
    ],
    289 =>
     [
      'regex' => '(python-requests)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Python Requests',
    ],
    290 =>
     [
      'regex' => '\\b(Windows-Update-Agent|Microsoft-CryptoAPI|SophosUpdateManager|SophosAgent|Debian APT-HTTP|Ubuntu APT-HTTP|libcurl-agent|libwww-perl|urlgrabber|curl|PycURL|Wget|aria2|Axel|OpenBSD ftp|lftp|jupdate|insomnia|fetch libfetch|akka-http|got)(?:[ /](\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)|)',
    ],
    291 =>
     [
      'regex' => '(Python/3\\.\\d{1,3} aiohttp)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    292 =>
     [
      'regex' => '(Python/3\\.\\d{1,3} aiohttp)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    293 =>
     [
      'regex' => '(Java)[/ ]{0,1}\\d+\\.(\\d+)\\.(\\d+)[_-]*([a-zA-Z0-9]+|)',
    ],
    294 =>
     [
      'regex' => '^(Cyberduck)/(\\d+)\\.(\\d+)\\.(\\d+)(?:\\.\\d+|)',
    ],
    295 =>
     [
      'regex' => '^(S3 Browser) (\\d+)-(\\d+)-(\\d+)(?:\\s*http://s3browser\\.com|)',
    ],
    296 =>
     [
      'regex' => '(S3Gof3r)',
    ],
    297 =>
     [
      'regex' => '\\b(ibm-cos-sdk-(?:core|java|js|python))/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
    ],
    298 =>
     [
      'regex' => '^(rusoto)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    299 =>
     [
      'regex' => '^(rclone)/v(\\d+)\\.(\\d+)',
    ],
    300 =>
     [
      'regex' => '^(Roku)/DVP-(\\d+)\\.(\\d+)',
    ],
    301 =>
     [
      'regex' => '(Kurio)\\/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Kurio App',
    ],
    302 =>
     [
      'regex' => '^(Box(?: Sync)?)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    303 =>
     [
      'regex' => '^(ViaFree|Viafree)-(?:tvOS-)?[A-Z]{2}/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'ViaFree',
    ],
  ],
  'os_parsers' =>
   [
    0 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\( ;(LG)E ;NetCast 4.0',
      'os_v1_replacement' => '2013',
    ],
    1 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\( ;(LG)E ;NetCast 3.0',
      'os_v1_replacement' => '2012',
    ],
    2 =>
     [
      'regex' => 'HbbTV/1.1.1 \\(;;;;;\\) Maple_2011',
      'os_replacement' => 'Samsung',
      'os_v1_replacement' => '2011',
    ],
    3 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(;(Samsung);SmartTV([0-9]{4});.*FXPDEUC',
      'os_v2_replacement' => 'UE40F7000',
    ],
    4 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(;(Samsung);SmartTV([0-9]{4});.*MST12DEUC',
      'os_v2_replacement' => 'UE32F4500',
    ],
    5 =>
     [
      'regex' => 'HbbTV/1\\.1\\.1 \\(; (Philips);.*NETTV/4',
      'os_v1_replacement' => '2013',
    ],
    6 =>
     [
      'regex' => 'HbbTV/1\\.1\\.1 \\(; (Philips);.*NETTV/3',
      'os_v1_replacement' => '2012',
    ],
    7 =>
     [
      'regex' => 'HbbTV/1\\.1\\.1 \\(; (Philips);.*NETTV/2',
      'os_v1_replacement' => '2011',
    ],
    8 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+.*(firetv)-firefox-plugin (\\d+).(\\d+).(\\d+)',
      'os_replacement' => 'FireHbbTV',
    ],
    9 =>
     [
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(.*; ?([a-zA-Z]+) ?;.*(201[1-9]).*\\)',
    ],
    10 =>
     [
      'regex' => '(Windows Phone) (?:OS[ /])?(\\d+)\\.(\\d+)',
    ],
    11 =>
     [
      'regex' => '(CPU[ +]OS|iPhone[ +]OS|CPU[ +]iPhone)[ +]+(\\d+)[_\\.](\\d+)(?:[_\\.](\\d+)|).*Outlook-iOS-Android',
      'os_replacement' => 'iOS',
    ],
    12 =>
     [
      'regex' => '(Android)[ \\-/](\\d+)(?:\\.(\\d+)|)(?:[.\\-]([a-z0-9]+)|)',
    ],
    13 =>
     [
      'regex' => '(Android) Donut',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '2',
    ],
    14 =>
     [
      'regex' => '(Android) Eclair',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '1',
    ],
    15 =>
     [
      'regex' => '(Android) Froyo',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '2',
    ],
    16 =>
     [
      'regex' => '(Android) Gingerbread',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '3',
    ],
    17 =>
     [
      'regex' => '(Android) Honeycomb',
      'os_v1_replacement' => '3',
    ],
    18 =>
     [
      'regex' => '(Android) (\\d+);',
    ],
    19 =>
     [
      'regex' => '^UCWEB.*; (Adr) (\\d+)\\.(\\d+)(?:[.\\-]([a-z0-9]+)|);',
      'os_replacement' => 'Android',
    ],
    20 =>
     [
      'regex' => '^UCWEB.*; (iPad|iPh|iPd) OS (\\d+)_(\\d+)(?:_(\\d+)|);',
      'os_replacement' => 'iOS',
    ],
    21 =>
     [
      'regex' => '^UCWEB.*; (wds) (\\d+)\\.(\\d+)(?:\\.(\\d+)|);',
      'os_replacement' => 'Windows Phone',
    ],
    22 =>
     [
      'regex' => '^(JUC).*; ?U; ?(?:Android|)(\\d+)\\.(\\d+)(?:[\\.\\-]([a-z0-9]+)|)',
      'os_replacement' => 'Android',
    ],
    23 =>
     [
      'regex' => '(android)\\s(?:mobile\\/)(\\d+)(?:\\.(\\d+)(?:\\.(\\d+)|)|)',
      'os_replacement' => 'Android',
    ],
    24 =>
     [
      'regex' => '(Silk-Accelerated=[a-z]{4,5})',
      'os_replacement' => 'Android',
    ],
    25 =>
     [
      'regex' => '(x86_64|aarch64)\\ (\\d+)\\.(\\d+)\\.(\\d+).*Chrome.*(?:CitrixChromeApp)$',
      'os_replacement' => 'Chrome OS',
    ],
    26 =>
     [
      'regex' => '(XBLWP7)',
      'os_replacement' => 'Windows Phone',
    ],
    27 =>
     [
      'regex' => '(Windows ?Mobile)',
      'os_replacement' => 'Windows Mobile',
    ],
    28 =>
     [
      'regex' => '(Windows 10)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '10',
    ],
    29 =>
     [
      'regex' => '(Windows (?:NT 5\\.2|NT 5\\.1))',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'XP',
    ],
    30 =>
     [
      'regex' => '(Windows NT 6\\.1)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '7',
    ],
    31 =>
     [
      'regex' => '(Windows NT 6\\.0)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'Vista',
    ],
    32 =>
     [
      'regex' => '(Win 9x 4\\.90)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'ME',
    ],
    33 =>
     [
      'regex' => '(Windows NT 6\\.2; ARM;)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'RT',
    ],
    34 =>
     [
      'regex' => '(Windows NT 6\\.2)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '8',
    ],
    35 =>
     [
      'regex' => '(Windows NT 6\\.3; ARM;)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'RT 8',
      'os_v2_replacement' => '1',
    ],
    36 =>
     [
      'regex' => '(Windows NT 6\\.3)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '8',
      'os_v2_replacement' => '1',
    ],
    37 =>
     [
      'regex' => '(Windows NT 6\\.4)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '10',
    ],
    38 =>
     [
      'regex' => '(Windows NT 10\\.0)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '10',
    ],
    39 =>
     [
      'regex' => '(Windows NT 5\\.0)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '2000',
    ],
    40 =>
     [
      'regex' => '(WinNT4.0)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'NT 4.0',
    ],
    41 =>
     [
      'regex' => '(Windows ?CE)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => 'CE',
    ],
    42 =>
     [
      'regex' => 'Win(?:dows)? ?(95|98|3.1|NT|ME|2000|XP|Vista|7|CE)',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '$1',
    ],
    43 =>
     [
      'regex' => 'Win16',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '3.1',
    ],
    44 =>
     [
      'regex' => 'Win32',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '95',
    ],
    45 =>
     [
      'regex' => '^Box.*Windows/([\\d.]+);',
      'os_replacement' => 'Windows',
      'os_v1_replacement' => '$1',
    ],
    46 =>
     [
      'regex' => '(Tizen)[/ ](\\d+)\\.(\\d+)',
    ],
    47 =>
     [
      'regex' => '((?:Mac[ +]?|; )OS[ +]X)[\\s+/](?:(\\d+)[_.](\\d+)(?:[_.](\\d+)|)|Mach-O)',
      'os_replacement' => 'Mac OS X',
    ],
    48 =>
     [
      'regex' => '\\w+\\s+Mac OS X\\s+\\w+\\s+(\\d+).(\\d+).(\\d+).*',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '$1',
      'os_v2_replacement' => '$2',
      'os_v3_replacement' => '$3',
    ],
    49 =>
     [
      'regex' => ' (Dar)(win)/(9).(\\d+).*\\((?:i386|x86_64|Power Macintosh)\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '5',
    ],
    50 =>
     [
      'regex' => ' (Dar)(win)/(10).(\\d+).*\\((?:i386|x86_64)\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '6',
    ],
    51 =>
     [
      'regex' => ' (Dar)(win)/(11).(\\d+).*\\((?:i386|x86_64)\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '7',
    ],
    52 =>
     [
      'regex' => ' (Dar)(win)/(12).(\\d+).*\\((?:i386|x86_64)\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '8',
    ],
    53 =>
     [
      'regex' => ' (Dar)(win)/(13).(\\d+).*\\((?:i386|x86_64)\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '9',
    ],
    54 =>
     [
      'regex' => 'Mac_PowerPC',
      'os_replacement' => 'Mac OS',
    ],
    55 =>
     [
      'regex' => '(?:PPC|Intel) (Mac OS X)',
    ],
    56 =>
     [
      'regex' => '^Box.*;(Darwin)/(10)\\.(1\\d)(?:\\.(\\d+)|)',
      'os_replacement' => 'Mac OS X',
    ],
    57 =>
     [
      'regex' => '(Apple\\s?TV)(?:/(\\d+)\\.(\\d+)|)',
      'os_replacement' => 'ATV OS X',
    ],
    58 =>
     [
      'regex' => '(CPU[ +]OS|iPhone[ +]OS|CPU[ +]iPhone|CPU IPhone OS)[ +]+(\\d+)[_\\.](\\d+)(?:[_\\.](\\d+)|)',
      'os_replacement' => 'iOS',
    ],
    59 =>
     [
      'regex' => '(iPhone|iPad|iPod); Opera',
      'os_replacement' => 'iOS',
    ],
    60 =>
     [
      'regex' => '(iPhone|iPad|iPod).*Mac OS X.*Version/(\\d+)\\.(\\d+)',
      'os_replacement' => 'iOS',
    ],
    61 =>
     [
      'regex' => '(CFNetwork)/(5)48\\.0\\.3.* Darwin/11\\.0\\.0',
      'os_replacement' => 'iOS',
    ],
    62 =>
     [
      'regex' => '(CFNetwork)/(5)48\\.(0)\\.4.* Darwin/(1)1\\.0\\.0',
      'os_replacement' => 'iOS',
    ],
    63 =>
     [
      'regex' => '(CFNetwork)/(5)48\\.(1)\\.4',
      'os_replacement' => 'iOS',
    ],
    64 =>
     [
      'regex' => '(CFNetwork)/(4)85\\.1(3)\\.9',
      'os_replacement' => 'iOS',
    ],
    65 =>
     [
      'regex' => '(CFNetwork)/(6)09\\.(1)\\.4',
      'os_replacement' => 'iOS',
    ],
    66 =>
     [
      'regex' => '(CFNetwork)/(6)(0)9',
      'os_replacement' => 'iOS',
    ],
    67 =>
     [
      'regex' => '(CFNetwork)/6(7)2\\.(1)\\.13',
      'os_replacement' => 'iOS',
    ],
    68 =>
     [
      'regex' => '(CFNetwork)/6(7)2\\.(1)\\.(1)4',
      'os_replacement' => 'iOS',
    ],
    69 =>
     [
      'regex' => '(CF)(Network)/6(7)(2)\\.1\\.15',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '7',
      'os_v2_replacement' => '1',
    ],
    70 =>
     [
      'regex' => '(CFNetwork)/6(7)2\\.(0)\\.(?:2|8)',
      'os_replacement' => 'iOS',
    ],
    71 =>
     [
      'regex' => '(CFNetwork)/709\\.1',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '8',
      'os_v2_replacement' => '0.b5',
    ],
    72 =>
     [
      'regex' => '(CF)(Network)/711\\.(\\d)',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '8',
    ],
    73 =>
     [
      'regex' => '(CF)(Network)/(720)\\.(\\d)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '10',
    ],
    74 =>
     [
      'regex' => '(CF)(Network)/(760)\\.(\\d)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '11',
    ],
    75 =>
     [
      'regex' => 'CFNetwork/7.* Darwin/15\\.4\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '9',
      'os_v2_replacement' => '3',
      'os_v3_replacement' => '1',
    ],
    76 =>
     [
      'regex' => 'CFNetwork/7.* Darwin/15\\.5\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '9',
      'os_v2_replacement' => '3',
      'os_v3_replacement' => '2',
    ],
    77 =>
     [
      'regex' => 'CFNetwork/7.* Darwin/15\\.6\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '9',
      'os_v2_replacement' => '3',
      'os_v3_replacement' => '5',
    ],
    78 =>
     [
      'regex' => '(CF)(Network)/758\\.(\\d)',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '9',
    ],
    79 =>
     [
      'regex' => 'CFNetwork/808\\.3 Darwin/16\\.3\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '2',
      'os_v3_replacement' => '1',
    ],
    80 =>
     [
      'regex' => '(CF)(Network)/808\\.(\\d)',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
    ],
    81 =>
     [
      'regex' => 'CFNetwork/.* Darwin/17\\.\\d+.*\\(x86_64\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '13',
    ],
    82 =>
     [
      'regex' => 'CFNetwork/.* Darwin/16\\.\\d+.*\\(x86_64\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '12',
    ],
    83 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/15\\.\\d+.*\\(x86_64\\)',
      'os_replacement' => 'Mac OS X',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '11',
    ],
    84 =>
     [
      'regex' => 'CFNetwork/.* Darwin/(9)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '1',
    ],
    85 =>
     [
      'regex' => 'CFNetwork/.* Darwin/(10)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '4',
    ],
    86 =>
     [
      'regex' => 'CFNetwork/.* Darwin/(11)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '5',
    ],
    87 =>
     [
      'regex' => 'CFNetwork/.* Darwin/(13)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '6',
    ],
    88 =>
     [
      'regex' => 'CFNetwork/6.* Darwin/(14)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '7',
    ],
    89 =>
     [
      'regex' => 'CFNetwork/7.* Darwin/(14)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '8',
      'os_v2_replacement' => '0',
    ],
    90 =>
     [
      'regex' => 'CFNetwork/7.* Darwin/(15)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '9',
      'os_v2_replacement' => '0',
    ],
    91 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/16\\.5\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '3',
    ],
    92 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/16\\.6\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '3',
      'os_v3_replacement' => '2',
    ],
    93 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/16\\.7\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
      'os_v2_replacement' => '3',
      'os_v3_replacement' => '3',
    ],
    94 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/(16)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '10',
    ],
    95 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/17\\.0\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '0',
    ],
    96 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/17\\.2\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '1',
    ],
    97 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/17\\.3\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '2',
    ],
    98 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/17\\.4\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '2',
      'os_v3_replacement' => '6',
    ],
    99 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/17\\.5\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '3',
    ],
    100 =>
     [
      'regex' => 'CFNetwork/9.* Darwin/17\\.6\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '4',
    ],
    101 =>
     [
      'regex' => 'CFNetwork/9.* Darwin/17\\.7\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
      'os_v2_replacement' => '4',
      'os_v3_replacement' => '1',
    ],
    102 =>
     [
      'regex' => 'CFNetwork/8.* Darwin/(17)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '11',
    ],
    103 =>
     [
      'regex' => 'CFNetwork/9.* Darwin/18\\.0\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '12',
      'os_v2_replacement' => '0',
    ],
    104 =>
     [
      'regex' => 'CFNetwork/9.* Darwin/(18)\\.\\d+',
      'os_replacement' => 'iOS',
      'os_v1_replacement' => '12',
    ],
    105 =>
     [
      'regex' => 'CFNetwork/.* Darwin/',
      'os_replacement' => 'iOS',
    ],
    106 =>
     [
      'regex' => '\\b(iOS[ /]|iOS; |iPhone(?:/| v|[ _]OS[/,]|; | OS : |\\d,\\d/|\\d,\\d; )|iPad/)(\\d{1,2})[_\\.](\\d{1,2})(?:[_\\.](\\d+)|)',
      'os_replacement' => 'iOS',
    ],
    107 =>
     [
      'regex' => '\\((iOS);',
    ],
    108 =>
     [
      'regex' => '(watchOS)/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'WatchOS',
    ],
    109 =>
     [
      'regex' => 'Outlook-(iOS)/\\d+\\.\\d+\\.prod\\.iphone',
    ],
    110 =>
     [
      'regex' => '(iPod|iPhone|iPad)',
      'os_replacement' => 'iOS',
    ],
    111 =>
     [
      'regex' => '(tvOS)[/ ](\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'tvOS',
    ],
    112 =>
     [
      'regex' => '(CrOS) [a-z0-9_]+ (\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'Chrome OS',
    ],
    113 =>
     [
      'regex' => '([Dd]ebian)',
      'os_replacement' => 'Debian',
    ],
    114 =>
     [
      'regex' => '(Linux Mint)(?:/(\\d+)|)',
    ],
    115 =>
     [
      'regex' => '(Mandriva)(?: Linux|)/(?:[\\d.-]+m[a-z]{2}(\\d+).(\\d)|)',
    ],
    116 =>
     [
      'regex' => '(Symbian[Oo][Ss])[/ ](\\d+)\\.(\\d+)',
      'os_replacement' => 'Symbian OS',
    ],
    117 =>
     [
      'regex' => '(Symbian/3).+NokiaBrowser/7\\.3',
      'os_replacement' => 'Symbian^3 Anna',
    ],
    118 =>
     [
      'regex' => '(Symbian/3).+NokiaBrowser/7\\.4',
      'os_replacement' => 'Symbian^3 Belle',
    ],
    119 =>
     [
      'regex' => '(Symbian/3)',
      'os_replacement' => 'Symbian^3',
    ],
    120 =>
     [
      'regex' => '\\b(Series 60|SymbOS|S60Version|S60V\\d|S60\\b)',
      'os_replacement' => 'Symbian OS',
    ],
    121 =>
     [
      'regex' => '(MeeGo)',
    ],
    122 =>
     [
      'regex' => 'Symbian [Oo][Ss]',
      'os_replacement' => 'Symbian OS',
    ],
    123 =>
     [
      'regex' => 'Series40;',
      'os_replacement' => 'Nokia Series 40',
    ],
    124 =>
     [
      'regex' => 'Series30Plus;',
      'os_replacement' => 'Nokia Series 30 Plus',
    ],
    125 =>
     [
      'regex' => '(BB10);.+Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'BlackBerry OS',
    ],
    126 =>
     [
      'regex' => '(Black[Bb]erry)[0-9a-z]+/(\\d+)\\.(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'BlackBerry OS',
    ],
    127 =>
     [
      'regex' => '(Black[Bb]erry).+Version/(\\d+)\\.(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'BlackBerry OS',
    ],
    128 =>
     [
      'regex' => '(RIM Tablet OS) (\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'BlackBerry Tablet OS',
    ],
    129 =>
     [
      'regex' => '(Play[Bb]ook)',
      'os_replacement' => 'BlackBerry Tablet OS',
    ],
    130 =>
     [
      'regex' => '(Black[Bb]erry)',
      'os_replacement' => 'BlackBerry OS',
    ],
    131 =>
     [
      'regex' => '(K[Aa][Ii]OS)\\/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'KaiOS',
    ],
    132 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/18.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '0',
      'os_v3_replacement' => '1',
    ],
    133 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/18.1 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '1',
    ],
    134 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/26.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '2',
    ],
    135 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/28.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '3',
    ],
    136 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/30.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '4',
    ],
    137 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/32.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '0',
    ],
    138 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Gecko/34.0 Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '1',
    ],
    139 =>
     [
      'regex' => '\\((?:Mobile|Tablet);.+Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
    ],
    140 =>
     [
      'regex' => '(BREW)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
    ],
    141 =>
     [
      'regex' => '(BREW);',
    ],
    142 =>
     [
      'regex' => '(Brew MP|BMP)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'Brew MP',
    ],
    143 =>
     [
      'regex' => 'BMP;',
      'os_replacement' => 'Brew MP',
    ],
    144 =>
     [
      'regex' => '(GoogleTV)(?: (\\d+)\\.(\\d+)(?:\\.(\\d+)|)|/[\\da-z]+)',
    ],
    145 =>
     [
      'regex' => '(WebTV)/(\\d+).(\\d+)',
    ],
    146 =>
     [
      'regex' => '(CrKey)(?:[/](\\d+)\\.(\\d+)(?:\\.(\\d+)|)|)',
      'os_replacement' => 'Chromecast',
    ],
    147 =>
     [
      'regex' => '(hpw|web)OS/(\\d+)\\.(\\d+)(?:\\.(\\d+)|)',
      'os_replacement' => 'webOS',
    ],
    148 =>
     [
      'regex' => '(VRE);',
    ],
    149 =>
     [
      'regex' => '(Fedora|Red Hat|PCLinuxOS|Puppy|Ubuntu|Kindle|Bada|Sailfish|Lubuntu|BackTrack|Slackware|(?:Free|Open|Net|\\b)BSD)[/ ](\\d+)\\.(\\d+)(?:\\.(\\d+)|)(?:\\.(\\d+)|)',
    ],
    150 =>
     [
      'regex' => '(Linux)[ /](\\d+)\\.(\\d+)(?:\\.(\\d+)|).*gentoo',
      'os_replacement' => 'Gentoo',
    ],
    151 =>
     [
      'regex' => '\\((Bada);',
    ],
    152 =>
     [
      'regex' => '(Windows|Android|WeTab|Maemo|Web0S)',
    ],
    153 =>
     [
      'regex' => '(Ubuntu|Kubuntu|Arch Linux|CentOS|Slackware|Gentoo|openSUSE|SUSE|Red Hat|Fedora|PCLinuxOS|Mageia|(?:Free|Open|Net|\\b)BSD)',
    ],
    154 =>
     [
      'regex' => '(Linux)(?:[ /](\\d+)\\.(\\d+)(?:\\.(\\d+)|)|)',
    ],
    155 =>
     [
      'regex' => 'SunOS',
      'os_replacement' => 'Solaris',
    ],
    156 =>
     [
      'regex' => '\\(linux-gnu\\)',
      'os_replacement' => 'Linux',
    ],
    157 =>
     [
      'regex' => '\\(x86_64-redhat-linux-gnu\\)',
      'os_replacement' => 'Red Hat',
    ],
    158 =>
     [
      'regex' => '\\((freebsd)(\\d+)\\.(\\d+)\\)',
      'os_replacement' => 'FreeBSD',
    ],
    159 =>
     [
      'regex' => 'linux',
      'os_replacement' => 'Linux',
    ],
    160 =>
     [
      'regex' => '^(Roku)/DVP-(\\d+)\\.(\\d+)',
    ],
  ],
  'device_parsers' =>
   [
    0 =>
     [
      'regex' => '(?:(?:iPhone|Windows CE|Windows Phone|Android).*(?:(?:Bot|Yeti)-Mobile|YRSpider|BingPreview|bots?/\\d|(?:bot|spider)\\.html)|AdsBot-Google-Mobile.*iPhone)',
      'regex_flag' => 'i',
      'device_replacement' => 'Spider',
      'brand_replacement' => 'Spider',
      'model_replacement' => 'Smartphone',
    ],
    1 =>
     [
      'regex' => '(?:DoCoMo|\\bMOT\\b|\\bLG\\b|Nokia|Samsung|SonyEricsson).*(?:(?:Bot|Yeti)-Mobile|bots?/\\d|(?:bot|crawler)\\.html|(?:jump|google|Wukong)bot|ichiro/mobile|/spider|YahooSeeker)',
      'regex_flag' => 'i',
      'device_replacement' => 'Spider',
      'brand_replacement' => 'Spider',
      'model_replacement' => 'Feature Phone',
    ],
    2 =>
     [
      'regex' => ' PTST/\\d+(?:\\.)?\\d+$',
      'device_replacement' => 'Spider',
      'brand_replacement' => 'Spider',
    ],
    3 =>
     [
      'regex' => 'X11; Datanyze; Linux',
      'device_replacement' => 'Spider',
      'brand_replacement' => 'Spider',
    ],
    4 =>
     [
      'regex' => '\\bSmartWatch *\\( *([^;]+) *; *([^;]+) *;',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    5 =>
     [
      'regex' => 'Android Application[^\\-]+ - (Sony) ?(Ericsson|) (.+) \\w+ - ',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1$2',
      'model_replacement' => '$3',
    ],
    6 =>
     [
      'regex' => 'Android Application[^\\-]+ - (?:HTC|HUAWEI|LGE|LENOVO|MEDION|TCT) (HTC|HUAWEI|LG|LENOVO|MEDION|ALCATEL)[ _\\-](.+) \\w+ - ',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    7 =>
     [
      'regex' => 'Android Application[^\\-]+ - ([^ ]+) (.+) \\w+ - ',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    8 =>
     [
      'regex' => '; *([BLRQ]C\\d{4}[A-Z]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '3Q $1',
      'brand_replacement' => '3Q',
      'model_replacement' => '$1',
    ],
    9 =>
     [
      'regex' => '; *(?:3Q_)([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '3Q $1',
      'brand_replacement' => '3Q',
      'model_replacement' => '$1',
    ],
    10 =>
     [
      'regex' => 'Android [34].*; *(A100|A101|A110|A200|A210|A211|A500|A501|A510|A511|A700(?: Lite| 3G|)|A701|B1-A71|A1-\\d{3}|B1-\\d{3}|V360|V370|W500|W500P|W501|W501P|W510|W511|W700|Slider SL101|DA22[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Acer',
      'model_replacement' => '$1',
    ],
    11 =>
     [
      'regex' => '; *Acer Iconia Tab ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Acer',
      'model_replacement' => '$1',
    ],
    12 =>
     [
      'regex' => '; *(Z1[1235]0|E320[^/]*|S500|S510|Liquid[^;/]*|Iconia A\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Acer',
      'model_replacement' => '$1',
    ],
    13 =>
     [
      'regex' => '; *(Acer |ACER )([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Acer',
      'model_replacement' => '$2',
    ],
    14 =>
     [
      'regex' => '; *(Advent |)(Vega(?:Bean|Comb|)).*?(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Advent',
      'model_replacement' => '$2',
    ],
    15 =>
     [
      'regex' => '; *(Ainol |)((?:NOVO|[Nn]ovo)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Ainol',
      'model_replacement' => '$2',
    ],
    16 =>
     [
      'regex' => '; *AIRIS[ _\\-]?([^/;\\)]+) *(?:;|\\)|Build)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Airis',
      'model_replacement' => '$1',
    ],
    17 =>
     [
      'regex' => '; *(OnePAD[^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Airis',
      'model_replacement' => '$1',
    ],
    18 =>
     [
      'regex' => '; *Airpad[ \\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Airpad $1',
      'brand_replacement' => 'Airpad',
      'model_replacement' => '$1',
    ],
    19 =>
     [
      'regex' => '; *(one ?touch) (EVO7|T10|T20)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Alcatel One Touch $2',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => 'One Touch $2',
    ],
    20 =>
     [
      'regex' => '; *(?:alcatel[ _]|)(?:(?:one[ _]?touch[ _])|ot[ \\-])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Alcatel One Touch $1',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => 'One Touch $1',
    ],
    21 =>
     [
      'regex' => '; *(TCL)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    22 =>
     [
      'regex' => '; *(Vodafone Smart II|Optimus_Madrid)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Alcatel $1',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => '$1',
    ],
    23 =>
     [
      'regex' => '; *BASE_Lutea_3(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Alcatel One Touch 998',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => 'One Touch 998',
    ],
    24 =>
     [
      'regex' => '; *BASE_Varia(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Alcatel One Touch 918D',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => 'One Touch 918D',
    ],
    25 =>
     [
      'regex' => '; *((?:FINE|Fine)\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Allfine',
      'model_replacement' => '$1',
    ],
    26 =>
     [
      'regex' => '; *(ALLVIEW[ _]?|Allview[ _]?)((?:Speed|SPEED).*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Allview',
      'model_replacement' => '$2',
    ],
    27 =>
     [
      'regex' => '; *(ALLVIEW[ _]?|Allview[ _]?|)(AX1_Shine|AX2_Frenzy)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Allview',
      'model_replacement' => '$2',
    ],
    28 =>
     [
      'regex' => '; *(ALLVIEW[ _]?|Allview[ _]?)([^;/]*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Allview',
      'model_replacement' => '$2',
    ],
    29 =>
     [
      'regex' => '; *(A13-MID)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Allwinner',
      'model_replacement' => '$1',
    ],
    30 =>
     [
      'regex' => '; *(Allwinner)[ _\\-]?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Allwinner',
      'model_replacement' => '$1',
    ],
    31 =>
     [
      'regex' => '; *(A651|A701B?|A702|A703|A705|A706|A707|A711|A712|A713|A717|A722|A785|A801|A802|A803|A901|A902|A1002|A1003|A1006|A1007|A9701|A9703|Q710|Q80)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Amaway',
      'model_replacement' => '$1',
    ],
    32 =>
     [
      'regex' => '; *(?:AMOI|Amoi)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Amoi $1',
      'brand_replacement' => 'Amoi',
      'model_replacement' => '$1',
    ],
    33 =>
     [
      'regex' => '^(?:AMOI|Amoi)[ _]([^;/]+?) Linux',
      'device_replacement' => 'Amoi $1',
      'brand_replacement' => 'Amoi',
      'model_replacement' => '$1',
    ],
    34 =>
     [
      'regex' => '; *(MW(?:0[789]|10)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Aoc',
      'model_replacement' => '$1',
    ],
    35 =>
     [
      'regex' => '; *(G7|M1013|M1015G|M11[CG]?|M-?12[B]?|M15|M19[G]?|M30[ACQ]?|M31[GQ]|M32|M33[GQ]|M36|M37|M38|M701T|M710|M712B|M713|M715G|M716G|M71(?:G|GS|T|)|M72[T]?|M73[T]?|M75[GT]?|M77G|M79T|M7L|M7LN|M81|M810|M81T|M82|M92|M92KS|M92S|M717G|M721|M722G|M723|M725G|M739|M785|M791|M92SK|M93D)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Aoson $1',
      'brand_replacement' => 'Aoson',
      'model_replacement' => '$1',
    ],
    36 =>
     [
      'regex' => '; *Aoson ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Aoson $1',
      'brand_replacement' => 'Aoson',
      'model_replacement' => '$1',
    ],
    37 =>
     [
      'regex' => '; *[Aa]panda[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Apanda $1',
      'brand_replacement' => 'Apanda',
      'model_replacement' => '$1',
    ],
    38 =>
     [
      'regex' => '; *(?:ARCHOS|Archos) ?(GAMEPAD.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Archos $1',
      'brand_replacement' => 'Archos',
      'model_replacement' => '$1',
    ],
    39 =>
     [
      'regex' => 'ARCHOS; GOGI; ([^;]+);',
      'device_replacement' => 'Archos $1',
      'brand_replacement' => 'Archos',
      'model_replacement' => '$1',
    ],
    40 =>
     [
      'regex' => '(?:ARCHOS|Archos)[ _]?(.*?)(?: Build|[;/\\(\\)\\-]|$)',
      'device_replacement' => 'Archos $1',
      'brand_replacement' => 'Archos',
      'model_replacement' => '$1',
    ],
    41 =>
     [
      'regex' => '; *(AN(?:7|8|9|10|13)[A-Z0-9]{1,4})(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Archos $1',
      'brand_replacement' => 'Archos',
      'model_replacement' => '$1',
    ],
    42 =>
     [
      'regex' => '; *(A28|A32|A43|A70(?:BHT|CHT|HB|S|X)|A101(?:B|C|IT)|A7EB|A7EB-WK|101G9|80G9)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Archos $1',
      'brand_replacement' => 'Archos',
      'model_replacement' => '$1',
    ],
    43 =>
     [
      'regex' => '; *(PAD-FMD[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Arival',
      'model_replacement' => '$1',
    ],
    44 =>
     [
      'regex' => '; *(BioniQ) ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Arival',
      'model_replacement' => '$1 $2',
    ],
    45 =>
     [
      'regex' => '; *(AN\\d[^;/]+|ARCHM\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Arnova $1',
      'brand_replacement' => 'Arnova',
      'model_replacement' => '$1',
    ],
    46 =>
     [
      'regex' => '; *(?:ARNOVA|Arnova) ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Arnova $1',
      'brand_replacement' => 'Arnova',
      'model_replacement' => '$1',
    ],
    47 =>
     [
      'regex' => '; *(?:ASSISTANT |)(AP)-?([1789]\\d{2}[A-Z]{0,2}|80104)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Assistant $1-$2',
      'brand_replacement' => 'Assistant',
      'model_replacement' => '$1-$2',
    ],
    48 =>
     [
      'regex' => '; *(ME17\\d[^;/]*|ME3\\d{2}[^;/]+|K00[A-Z]|Nexus 10|Nexus 7(?: 2013|)|PadFone[^;/]*|Transformer[^;/]*|TF\\d{3}[^;/]*|eeepc)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    49 =>
     [
      'regex' => '; *ASUS[ _]*([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    50 =>
     [
      'regex' => '; *Garmin-Asus ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Garmin-Asus $1',
      'brand_replacement' => 'Garmin-Asus',
      'model_replacement' => '$1',
    ],
    51 =>
     [
      'regex' => '; *(Garminfone)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Garmin $1',
      'brand_replacement' => 'Garmin-Asus',
      'model_replacement' => '$1',
    ],
    52 =>
     [
      'regex' => '; (\\@TAB-[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Attab',
      'model_replacement' => '$1',
    ],
    53 =>
     [
      'regex' => '; *(T-(?:07|[^0]\\d)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Audiosonic',
      'model_replacement' => '$1',
    ],
    54 =>
     [
      'regex' => '; *(?:Axioo[ _\\-]([^;/]+?)|(picopad)[ _\\-]([^;/]+?))(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Axioo $1$2 $3',
      'brand_replacement' => 'Axioo',
      'model_replacement' => '$1$2 $3',
    ],
    55 =>
     [
      'regex' => '; *(V(?:100|700|800)[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Azend',
      'model_replacement' => '$1',
    ],
    56 =>
     [
      'regex' => '; *(IBAK\\-[^;/]*)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Bak',
      'model_replacement' => '$1',
    ],
    57 =>
     [
      'regex' => '; *(HY5001|HY6501|X12|X21|I5)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Bedove $1',
      'brand_replacement' => 'Bedove',
      'model_replacement' => '$1',
    ],
    58 =>
     [
      'regex' => '; *(JC-[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Benss $1',
      'brand_replacement' => 'Benss',
      'model_replacement' => '$1',
    ],
    59 =>
     [
      'regex' => '; *(BB) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Blackberry',
      'model_replacement' => '$2',
    ],
    60 =>
     [
      'regex' => '; *(BlackBird)[ _](I8.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    61 =>
     [
      'regex' => '; *(BlackBird)[ _](.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    62 =>
     [
      'regex' => '; *([0-9]+BP[EM][^;/]*|Endeavour[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Blaupunkt $1',
      'brand_replacement' => 'Blaupunkt',
      'model_replacement' => '$1',
    ],
    63 =>
     [
      'regex' => '; *((?:BLU|Blu)[ _\\-])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Blu',
      'model_replacement' => '$2',
    ],
    64 =>
     [
      'regex' => '; *(?:BMOBILE )?(Blu|BLU|DASH [^;/]+|VIVO 4\\.3|TANK 4\\.5)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Blu',
      'model_replacement' => '$1',
    ],
    65 =>
     [
      'regex' => '; *(TOUCH\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Blusens',
      'model_replacement' => '$1',
    ],
    66 =>
     [
      'regex' => '; *(AX5\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Bmobile',
      'model_replacement' => '$1',
    ],
    67 =>
     [
      'regex' => '; *([Bb]q) ([^;/]+?);?(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'bq',
      'model_replacement' => '$2',
    ],
    68 =>
     [
      'regex' => '; *(Maxwell [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'bq',
      'model_replacement' => '$1',
    ],
    69 =>
     [
      'regex' => '; *((?:B-Tab|B-TAB) ?\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Braun',
      'model_replacement' => '$1',
    ],
    70 =>
     [
      'regex' => '; *(Broncho) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    71 =>
     [
      'regex' => '; *CAPTIVA ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Captiva $1',
      'brand_replacement' => 'Captiva',
      'model_replacement' => '$1',
    ],
    72 =>
     [
      'regex' => '; *(C771|CAL21|IS11CA)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Casio',
      'model_replacement' => '$1',
    ],
    73 =>
     [
      'regex' => '; *(?:Cat|CAT) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Cat $1',
      'brand_replacement' => 'Cat',
      'model_replacement' => '$1',
    ],
    74 =>
     [
      'regex' => '; *(?:Cat)(Nova.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Cat $1',
      'brand_replacement' => 'Cat',
      'model_replacement' => '$1',
    ],
    75 =>
     [
      'regex' => '; *(INM8002KP|ADM8000KP_[AB])(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Cat',
      'model_replacement' => 'Tablet PHOENIX 8.1J0',
    ],
    76 =>
     [
      'regex' => '; *(?:[Cc]elkon[ _\\*]|CELKON[ _\\*])([^;/\\)]+) ?(?:Build|;|\\))',
      'device_replacement' => '$1',
      'brand_replacement' => 'Celkon',
      'model_replacement' => '$1',
    ],
    77 =>
     [
      'regex' => 'Build/(?:[Cc]elkon)+_?([^;/_\\)]+)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Celkon',
      'model_replacement' => '$1',
    ],
    78 =>
     [
      'regex' => '; *(CT)-?(\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Celkon',
      'model_replacement' => '$1$2',
    ],
    79 =>
     [
      'regex' => '; *(A19|A19Q|A105|A107[^;/\\)]*) ?(?:Build|;|\\))',
      'device_replacement' => '$1',
      'brand_replacement' => 'Celkon',
      'model_replacement' => '$1',
    ],
    80 =>
     [
      'regex' => '; *(TPC[0-9]{4,5})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'ChangJia',
      'model_replacement' => '$1',
    ],
    81 =>
     [
      'regex' => '; *(Cloudfone)[ _](Excite)([^ ][^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2 $3',
      'brand_replacement' => 'Cloudfone',
      'model_replacement' => '$1 $2 $3',
    ],
    82 =>
     [
      'regex' => '; *(Excite|ICE)[ _](\\d+[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Cloudfone $1 $2',
      'brand_replacement' => 'Cloudfone',
      'model_replacement' => 'Cloudfone $1 $2',
    ],
    83 =>
     [
      'regex' => '; *(Cloudfone|CloudPad)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Cloudfone',
      'model_replacement' => '$1 $2',
    ],
    84 =>
     [
      'regex' => '; *((?:Aquila|Clanga|Rapax)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Cmx',
      'model_replacement' => '$1',
    ],
    85 =>
     [
      'regex' => '; *(?:CFW-|Kyros )?(MID[0-9]{4}(?:[ABC]|SR|TV)?)(\\(3G\\)-4G| GB 8K| 3G| 8K| GB)? *(?:Build|[;\\)])',
      'device_replacement' => 'CobyKyros $1$2',
      'brand_replacement' => 'CobyKyros',
      'model_replacement' => '$1$2',
    ],
    86 =>
     [
      'regex' => '; *([^;/]*)Coolpad[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Coolpad',
      'model_replacement' => '$1$2',
    ],
    87 =>
     [
      'regex' => '; *(CUBE[ _])?([KU][0-9]+ ?GT.*?|A5300)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Cube',
      'model_replacement' => '$2',
    ],
    88 =>
     [
      'regex' => '; *CUBOT ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Cubot',
      'model_replacement' => '$1',
    ],
    89 =>
     [
      'regex' => '; *(BOBBY)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Cubot',
      'model_replacement' => '$1',
    ],
    90 =>
     [
      'regex' => '; *(Dslide [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Danew',
      'model_replacement' => '$1',
    ],
    91 =>
     [
      'regex' => '; *(XCD)[ _]?(28|35)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1$2',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1$2',
    ],
    92 =>
     [
      'regex' => '; *(001DL)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => 'Streak',
    ],
    93 =>
     [
      'regex' => '; *(?:Dell|DELL) (Streak)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => 'Streak',
    ],
    94 =>
     [
      'regex' => '; *(101DL|GS01|Streak Pro[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => 'Streak Pro',
    ],
    95 =>
     [
      'regex' => '; *([Ss]treak ?7)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => 'Streak 7',
    ],
    96 =>
     [
      'regex' => '; *(Mini-3iX)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    97 =>
     [
      'regex' => '; *(?:Dell|DELL)[ _](Aero|Venue|Thunder|Mini.*?|Streak[ _]Pro)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    98 =>
     [
      'regex' => '; *Dell[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    99 =>
     [
      'regex' => '; *Dell ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    100 =>
     [
      'regex' => '; *(TA[CD]-\\d+[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Denver',
      'model_replacement' => '$1',
    ],
    101 =>
     [
      'regex' => '; *(iP[789]\\d{2}(?:-3G)?|IP10\\d{2}(?:-8GB)?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Dex',
      'model_replacement' => '$1',
    ],
    102 =>
     [
      'regex' => '; *(AirTab)[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'DNS',
      'model_replacement' => '$1 $2',
    ],
    103 =>
     [
      'regex' => '; *(F\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Fujitsu',
      'model_replacement' => '$1',
    ],
    104 =>
     [
      'regex' => '; *(HT-03A)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'Magic',
    ],
    105 =>
     [
      'regex' => '; *(HT\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    106 =>
     [
      'regex' => '; *(L\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    107 =>
     [
      'regex' => '; *(N\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Nec',
      'model_replacement' => '$1',
    ],
    108 =>
     [
      'regex' => '; *(P\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Panasonic',
      'model_replacement' => '$1',
    ],
    109 =>
     [
      'regex' => '; *(SC\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    110 =>
     [
      'regex' => '; *(SH\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sharp',
      'model_replacement' => '$1',
    ],
    111 =>
     [
      'regex' => '; *(SO\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => '$1',
    ],
    112 =>
     [
      'regex' => '; *(T\\-0[12][^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Toshiba',
      'model_replacement' => '$1',
    ],
    113 =>
     [
      'regex' => '; *(DOOV)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'DOOV',
      'model_replacement' => '$2',
    ],
    114 =>
     [
      'regex' => '; *(Enot|ENOT)[ -]?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Enot',
      'model_replacement' => '$2',
    ],
    115 =>
     [
      'regex' => '; *[^;/]+ Build/(?:CROSS|Cross)+[ _\\-]([^\\)]+)',
      'device_replacement' => 'CROSS $1',
      'brand_replacement' => 'Evercoss',
      'model_replacement' => 'Cross $1',
    ],
    116 =>
     [
      'regex' => '; *(CROSS|Cross)[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Evercoss',
      'model_replacement' => 'Cross $2',
    ],
    117 =>
     [
      'regex' => '; *Explay[_ ](.+?)(?:[\\)]| Build)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Explay',
      'model_replacement' => '$1',
    ],
    118 =>
     [
      'regex' => '; *(IQ.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Fly',
      'model_replacement' => '$1',
    ],
    119 =>
     [
      'regex' => '; *(Fly|FLY)[ _](IQ[^;]+?|F[34]\\d+[^;]*?);?(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Fly',
      'model_replacement' => '$2',
    ],
    120 =>
     [
      'regex' => '; *(M532|Q572|FJL21)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Fujitsu',
      'model_replacement' => '$1',
    ],
    121 =>
     [
      'regex' => '; *(G1)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Galapad',
      'model_replacement' => '$1',
    ],
    122 =>
     [
      'regex' => '; *(Geeksphone) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    123 =>
     [
      'regex' => '; *(G[^F]?FIVE) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Gfive',
      'model_replacement' => '$2',
    ],
    124 =>
     [
      'regex' => '; *(Gionee)[ _\\-]([^;/]+?)(?:/[^;/]+|)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Gionee',
      'model_replacement' => '$2',
    ],
    125 =>
     [
      'regex' => '; *(GN\\d+[A-Z]?|INFINITY_PASSION|Ctrl_V1)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Gionee $1',
      'brand_replacement' => 'Gionee',
      'model_replacement' => '$1',
    ],
    126 =>
     [
      'regex' => '; *(E3) Build/JOP40D',
      'device_replacement' => 'Gionee $1',
      'brand_replacement' => 'Gionee',
      'model_replacement' => '$1',
    ],
    127 =>
     [
      'regex' => '\\sGIONEE[-\\s_](\\w*)',
      'regex_flag' => 'i',
      'device_replacement' => 'Gionee $1',
      'brand_replacement' => 'Gionee',
      'model_replacement' => '$1',
    ],
    128 =>
     [
      'regex' => '; *((?:FONE|QUANTUM|INSIGNIA) \\d+[^;/]*|PLAYTAB)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'GoClever $1',
      'brand_replacement' => 'GoClever',
      'model_replacement' => '$1',
    ],
    129 =>
     [
      'regex' => '; *GOCLEVER ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'GoClever $1',
      'brand_replacement' => 'GoClever',
      'model_replacement' => '$1',
    ],
    130 =>
     [
      'regex' => '; *(Glass \\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Google',
      'model_replacement' => '$1',
    ],
    131 =>
     [
      'regex' => '; *(Pixel.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Google',
      'model_replacement' => '$1',
    ],
    132 =>
     [
      'regex' => '; *(GSmart)[ -]([^/]+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Gigabyte',
      'model_replacement' => '$1 $2',
    ],
    133 =>
     [
      'regex' => '; *(imx5[13]_[^/]+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Freescale $1',
      'brand_replacement' => 'Freescale',
      'model_replacement' => '$1',
    ],
    134 =>
     [
      'regex' => '; *Haier[ _\\-]([^/]+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Haier $1',
      'brand_replacement' => 'Haier',
      'model_replacement' => '$1',
    ],
    135 =>
     [
      'regex' => '; *(PAD1016)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Haipad $1',
      'brand_replacement' => 'Haipad',
      'model_replacement' => '$1',
    ],
    136 =>
     [
      'regex' => '; *(M701|M7|M8|M9)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Haipad $1',
      'brand_replacement' => 'Haipad',
      'model_replacement' => '$1',
    ],
    137 =>
     [
      'regex' => '; *(SN\\d+T[^;\\)/]*)(?: Build|[;\\)])',
      'device_replacement' => 'Hannspree $1',
      'brand_replacement' => 'Hannspree',
      'model_replacement' => '$1',
    ],
    138 =>
     [
      'regex' => 'Build/HCL ME Tablet ([^;\\)]+)[\\);]',
      'device_replacement' => 'HCLme $1',
      'brand_replacement' => 'HCLme',
      'model_replacement' => '$1',
    ],
    139 =>
     [
      'regex' => '; *([^;\\/]+) Build/HCL',
      'device_replacement' => 'HCLme $1',
      'brand_replacement' => 'HCLme',
      'model_replacement' => '$1',
    ],
    140 =>
     [
      'regex' => '; *(MID-?\\d{4}C[EM])(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Hena $1',
      'brand_replacement' => 'Hena',
      'model_replacement' => '$1',
    ],
    141 =>
     [
      'regex' => '; *(EG\\d{2,}|HS-[^;/]+|MIRA[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Hisense $1',
      'brand_replacement' => 'Hisense',
      'model_replacement' => '$1',
    ],
    142 =>
     [
      'regex' => '; *(andromax[^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Hisense $1',
      'brand_replacement' => 'Hisense',
      'model_replacement' => '$1',
    ],
    143 =>
     [
      'regex' => '; *(?:AMAZE[ _](S\\d+)|(S\\d+)[ _]AMAZE)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'AMAZE $1$2',
      'brand_replacement' => 'hitech',
      'model_replacement' => 'AMAZE $1$2',
    ],
    144 =>
     [
      'regex' => '; *(PlayBook)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'HP $1',
      'brand_replacement' => 'HP',
      'model_replacement' => '$1',
    ],
    145 =>
     [
      'regex' => '; *HP ([^/]+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'HP $1',
      'brand_replacement' => 'HP',
      'model_replacement' => '$1',
    ],
    146 =>
     [
      'regex' => '; *([^/]+_tenderloin)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'HP TouchPad',
      'brand_replacement' => 'HP',
      'model_replacement' => 'TouchPad',
    ],
    147 =>
     [
      'regex' => '; *(HUAWEI |Huawei-|)([UY][^;/]+) Build/(?:Huawei|HUAWEI)([UY][^\\);]+)\\)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    148 =>
     [
      'regex' => '; *([^;/]+) Build[/ ]Huawei(MT1-U06|[A-Z]+\\d+[^\\);]+)[^\\);]*\\)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    149 =>
     [
      'regex' => '; *(S7|M860) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    150 =>
     [
      'regex' => '; *((?:HUAWEI|Huawei)[ \\-]?)(MediaPad) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    151 =>
     [
      'regex' => '; *((?:HUAWEI[ _]?|Huawei[ _]|)Ascend[ _])([^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    152 =>
     [
      'regex' => '; *((?:HUAWEI|Huawei)[ _\\-]?)((?:G700-|MT-)[^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    153 =>
     [
      'regex' => '; *((?:HUAWEI|Huawei)[ _\\-]?)([^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$2',
    ],
    154 =>
     [
      'regex' => '; *(MediaPad[^;]+|SpringBoard) Build/Huawei',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    155 =>
     [
      'regex' => '; *([^;]+) Build/(?:Huawei|HUAWEI)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    156 =>
     [
      'regex' => '; *([Uu])([89]\\d{3}) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'U$2',
    ],
    157 =>
     [
      'regex' => '; *(?:Ideos |IDEOS )(S7) Build',
      'device_replacement' => 'Huawei Ideos$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'Ideos$1',
    ],
    158 =>
     [
      'regex' => '; *(?:Ideos |IDEOS )([^;/]+\\s*|\\s*)Build',
      'device_replacement' => 'Huawei Ideos$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'Ideos$1',
    ],
    159 =>
     [
      'regex' => '; *(Orange Daytona|Pulse|Pulse Mini|Vodafone 858|C8500|C8600|C8650|C8660|Nexus 6P|ATH-.+?) Build[/ ]',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    160 =>
     [
      'regex' => '; *((?:[A-Z]{3})\\-L[A-Za0-9]{2})[\\)]',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    161 =>
     [
      'regex' => '; *HTC[ _]([^;]+); Windows Phone',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    162 =>
     [
      'regex' => '; *(?:HTC[ _/])+([^ _/]+)(?:[/\\\\]1\\.0 | V|/| +)\\d+\\.\\d[\\d\\.]*(?: *Build|\\))',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    163 =>
     [
      'regex' => '; *(?:HTC[ _/])+([^ _/]+)(?:[ _/]([^ _/]+)|)(?:[/\\\\]1\\.0 | V|/| +)\\d+\\.\\d[\\d\\.]*(?: *Build|\\))',
      'device_replacement' => 'HTC $1 $2',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2',
    ],
    164 =>
     [
      'regex' => '; *(?:HTC[ _/])+([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ _/]+)|)|)(?:[/\\\\]1\\.0 | V|/| +)\\d+\\.\\d[\\d\\.]*(?: *Build|\\))',
      'device_replacement' => 'HTC $1 $2 $3',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2 $3',
    ],
    165 =>
     [
      'regex' => '; *(?:HTC[ _/])+([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ _/]+)|)|)|)(?:[/\\\\]1\\.0 | V|/| +)\\d+\\.\\d[\\d\\.]*(?: *Build|\\))',
      'device_replacement' => 'HTC $1 $2 $3 $4',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2 $3 $4',
    ],
    166 =>
     [
      'regex' => '; *(?:(?:HTC|htc)(?:_blocked|)[ _/])+([^ _/;]+)(?: *Build|[;\\)]| - )',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    167 =>
     [
      'regex' => '; *(?:(?:HTC|htc)(?:_blocked|)[ _/])+([^ _/]+)(?:[ _/]([^ _/;\\)]+)|)(?: *Build|[;\\)]| - )',
      'device_replacement' => 'HTC $1 $2',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2',
    ],
    168 =>
     [
      'regex' => '; *(?:(?:HTC|htc)(?:_blocked|)[ _/])+([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ _/;\\)]+)|)|)(?: *Build|[;\\)]| - )',
      'device_replacement' => 'HTC $1 $2 $3',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2 $3',
    ],
    169 =>
     [
      'regex' => '; *(?:(?:HTC|htc)(?:_blocked|)[ _/])+([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ _/]+)(?:[ _/]([^ /;]+)|)|)|)(?: *Build|[;\\)]| - )',
      'device_replacement' => 'HTC $1 $2 $3 $4',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2 $3 $4',
    ],
    170 =>
     [
      'regex' => 'HTC Streaming Player [^\\/]*/[^\\/]*/ htc_([^/]+) /',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    171 =>
     [
      'regex' => '(?:[;,] *|^)(?:htccn_chs-|)HTC[ _-]?([^;]+?)(?: *Build|clay|Android|-?Mozilla| Opera| Profile| UNTRUSTED|[;/\\(\\)]|$)',
      'regex_flag' => 'i',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    172 =>
     [
      'regex' => '; *(A6277|ADR6200|ADR6300|ADR6350|ADR6400[A-Z]*|ADR6425[A-Z]*|APX515CKT|ARIA|Desire[^_ ]*|Dream|EndeavorU|Eris|Evo|Flyer|HD2|Hero|HERO200|Hero CDMA|HTL21|Incredible|Inspire[A-Z0-9]*|Legend|Liberty|Nexus ?(?:One|HD2)|One|One S C2|One[ _]?(?:S|V|X\\+?)\\w*|PC36100|PG06100|PG86100|S31HT|Sensation|Wildfire)(?: Build|[/;\\(\\)])',
      'regex_flag' => 'i',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    173 =>
     [
      'regex' => '; *(ADR6200|ADR6400L|ADR6425LVW|Amaze|DesireS?|EndeavorU|Eris|EVO|Evo\\d[A-Z]+|HD2|IncredibleS?|Inspire[A-Z0-9]*|Inspire[A-Z0-9]*|Sensation[A-Z0-9]*|Wildfire)[ _-](.+?)(?:[/;\\)]|Build|MIUI|1\\.0)',
      'regex_flag' => 'i',
      'device_replacement' => 'HTC $1 $2',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1 $2',
    ],
    174 =>
     [
      'regex' => '; *HYUNDAI (T\\d[^/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Hyundai $1',
      'brand_replacement' => 'Hyundai',
      'model_replacement' => '$1',
    ],
    175 =>
     [
      'regex' => '; *HYUNDAI ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Hyundai $1',
      'brand_replacement' => 'Hyundai',
      'model_replacement' => '$1',
    ],
    176 =>
     [
      'regex' => '; *(X700|Hold X|MB-6900)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Hyundai $1',
      'brand_replacement' => 'Hyundai',
      'model_replacement' => '$1',
    ],
    177 =>
     [
      'regex' => '; *(?:iBall[ _\\-]|)(Andi)[ _]?(\\d[^;/]*)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'iBall',
      'model_replacement' => '$1 $2',
    ],
    178 =>
     [
      'regex' => '; *(IBall)(?:[ _]([^;/]+?)|)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'iBall',
      'model_replacement' => '$2',
    ],
    179 =>
     [
      'regex' => '; *(NT-\\d+[^ ;/]*|Net[Tt]AB [^;/]+|Mercury [A-Z]+|iconBIT)(?: S/N:[^;/]+|)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'IconBIT',
      'model_replacement' => '$1',
    ],
    180 =>
     [
      'regex' => '; *(IMO)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'IMO',
      'model_replacement' => '$2',
    ],
    181 =>
     [
      'regex' => '; *i-?mobile[ _]([^/]+)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'i-mobile $1',
      'brand_replacement' => 'imobile',
      'model_replacement' => '$1',
    ],
    182 =>
     [
      'regex' => '; *(i-(?:style|note)[^/]*)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'i-mobile $1',
      'brand_replacement' => 'imobile',
      'model_replacement' => '$1',
    ],
    183 =>
     [
      'regex' => '; *(ImPAD) ?(\\d+(?:.)*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Impression',
      'model_replacement' => '$1 $2',
    ],
    184 =>
     [
      'regex' => '; *(Infinix)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Infinix',
      'model_replacement' => '$2',
    ],
    185 =>
     [
      'regex' => '; *(Informer)[ \\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Informer',
      'model_replacement' => '$2',
    ],
    186 =>
     [
      'regex' => '; *(TAB) ?([78][12]4)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Intenso $1',
      'brand_replacement' => 'Intenso',
      'model_replacement' => '$1 $2',
    ],
    187 =>
     [
      'regex' => '; *(?:Intex[ _]|)(AQUA|Aqua)([ _\\.\\-])([^;/]+?) *(?:Build|;)',
      'device_replacement' => '$1$2$3',
      'brand_replacement' => 'Intex',
      'model_replacement' => '$1 $3',
    ],
    188 =>
     [
      'regex' => '; *(?:INTEX|Intex)(?:[_ ]([^\\ _;/]+))(?:[_ ]([^\\ _;/]+)|) *(?:Build|;)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Intex',
      'model_replacement' => '$1 $2',
    ],
    189 =>
     [
      'regex' => '; *([iI]Buddy)[ _]?(Connect)(?:_|\\?_| |)([^;/]*) *(?:Build|;)',
      'device_replacement' => '$1 $2 $3',
      'brand_replacement' => 'Intex',
      'model_replacement' => 'iBuddy $2 $3',
    ],
    190 =>
     [
      'regex' => '; *(I-Buddy)[ _]([^;/]+?) *(?:Build|;)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Intex',
      'model_replacement' => 'iBuddy $2',
    ],
    191 =>
     [
      'regex' => '; *(iOCEAN) ([^/]+)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'iOCEAN',
      'model_replacement' => '$2',
    ],
    192 =>
     [
      'regex' => '; *(TP\\d+(?:\\.\\d+|)\\-\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'ionik $1',
      'brand_replacement' => 'ionik',
      'model_replacement' => '$1',
    ],
    193 =>
     [
      'regex' => '; *(M702pro)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Iru',
      'model_replacement' => '$1',
    ],
    194 =>
     [
      'regex' => '; *(DE88Plus|MD70)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Ivio',
      'model_replacement' => '$1',
    ],
    195 =>
     [
      'regex' => '; *IVIO[_\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Ivio',
      'model_replacement' => '$1',
    ],
    196 =>
     [
      'regex' => '; *(TPC-\\d+|JAY-TECH)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Jaytech',
      'model_replacement' => '$1',
    ],
    197 =>
     [
      'regex' => '; *(JY-[^;/]+|G[234]S?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Jiayu',
      'model_replacement' => '$1',
    ],
    198 =>
     [
      'regex' => '; *(JXD)[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'JXD',
      'model_replacement' => '$2',
    ],
    199 =>
     [
      'regex' => '; *Karbonn[ _]?([^;/]+) *(?:Build|;)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Karbonn',
      'model_replacement' => '$1',
    ],
    200 =>
     [
      'regex' => '; *([^;]+) Build/Karbonn',
      'device_replacement' => '$1',
      'brand_replacement' => 'Karbonn',
      'model_replacement' => '$1',
    ],
    201 =>
     [
      'regex' => '; *(A11|A39|A37|A34|ST8|ST10|ST7|Smart Tab3|Smart Tab2|Titanium S\\d) +Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Karbonn',
      'model_replacement' => '$1',
    ],
    202 =>
     [
      'regex' => '; *(IS01|IS03|IS05|IS\\d{2}SH)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sharp',
      'model_replacement' => '$1',
    ],
    203 =>
     [
      'regex' => '; *(IS04)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Regza',
      'model_replacement' => '$1',
    ],
    204 =>
     [
      'regex' => '; *(IS06|IS\\d{2}PT)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Pantech',
      'model_replacement' => '$1',
    ],
    205 =>
     [
      'regex' => '; *(IS11S)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => 'Xperia Acro',
    ],
    206 =>
     [
      'regex' => '; *(IS11CA)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Casio',
      'model_replacement' => 'GzOne $1',
    ],
    207 =>
     [
      'regex' => '; *(IS11LG)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'LG',
      'model_replacement' => 'Optimus X',
    ],
    208 =>
     [
      'regex' => '; *(IS11N)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Medias',
      'model_replacement' => '$1',
    ],
    209 =>
     [
      'regex' => '; *(IS11PT)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Pantech',
      'model_replacement' => 'MIRACH',
    ],
    210 =>
     [
      'regex' => '; *(IS12F)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Fujitsu',
      'model_replacement' => 'Arrows ES',
    ],
    211 =>
     [
      'regex' => '; *(IS12M)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => 'XT909',
    ],
    212 =>
     [
      'regex' => '; *(IS12S)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => 'Xperia Acro HD',
    ],
    213 =>
     [
      'regex' => '; *(ISW11F)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Fujitsu',
      'model_replacement' => 'Arrowz Z',
    ],
    214 =>
     [
      'regex' => '; *(ISW11HT)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'EVO',
    ],
    215 =>
     [
      'regex' => '; *(ISW11K)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Kyocera',
      'model_replacement' => 'DIGNO',
    ],
    216 =>
     [
      'regex' => '; *(ISW11M)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => 'Photon',
    ],
    217 =>
     [
      'regex' => '; *(ISW11SC)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => 'GALAXY S II WiMAX',
    ],
    218 =>
     [
      'regex' => '; *(ISW12HT)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'EVO 3D',
    ],
    219 =>
     [
      'regex' => '; *(ISW13HT)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'J',
    ],
    220 =>
     [
      'regex' => '; *(ISW?[0-9]{2}[A-Z]{0,2})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'KDDI',
      'model_replacement' => '$1',
    ],
    221 =>
     [
      'regex' => '; *(INFOBAR [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'KDDI',
      'model_replacement' => '$1',
    ],
    222 =>
     [
      'regex' => '; *(JOYPAD|Joypad)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Kingcom',
      'model_replacement' => '$1 $2',
    ],
    223 =>
     [
      'regex' => '; *(Vox|VOX|Arc|K080)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Kobo',
      'model_replacement' => '$1',
    ],
    224 =>
     [
      'regex' => '\\b(Kobo Touch)\\b',
      'device_replacement' => '$1',
      'brand_replacement' => 'Kobo',
      'model_replacement' => '$1',
    ],
    225 =>
     [
      'regex' => '; *(K-Touch)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Ktouch',
      'model_replacement' => '$2',
    ],
    226 =>
     [
      'regex' => '; *((?:EV|KM)-S\\d+[A-Z]?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'KTtech',
      'model_replacement' => '$1',
    ],
    227 =>
     [
      'regex' => '; *(Zio|Hydro|Torque|Event|EVENT|Echo|Milano|Rise|URBANO PROGRESSO|WX04K|WX06K|WX10K|KYL21|101K|C5[12]\\d{2})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Kyocera',
      'model_replacement' => '$1',
    ],
    228 =>
     [
      'regex' => '; *(?:LAVA[ _]|)IRIS[ _\\-]?([^/;\\)]+) *(?:;|\\)|Build)',
      'regex_flag' => 'i',
      'device_replacement' => 'Iris $1',
      'brand_replacement' => 'Lava',
      'model_replacement' => 'Iris $1',
    ],
    229 =>
     [
      'regex' => '; *LAVA[ _]([^;/]+) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Lava',
      'model_replacement' => '$1',
    ],
    230 =>
     [
      'regex' => '; *(?:(Aspire A1)|(?:LEMON|Lemon)[ _]([^;/]+))_?(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Lemon $1$2',
      'brand_replacement' => 'Lemon',
      'model_replacement' => '$1$2',
    ],
    231 =>
     [
      'regex' => '; *(TAB-1012)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Lenco $1',
      'brand_replacement' => 'Lenco',
      'model_replacement' => '$1',
    ],
    232 =>
     [
      'regex' => '; Lenco ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Lenco $1',
      'brand_replacement' => 'Lenco',
      'model_replacement' => '$1',
    ],
    233 =>
     [
      'regex' => '; *(A1_07|A2107A-H|S2005A-H|S1-37AH0) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1',
    ],
    234 =>
     [
      'regex' => '; *(Idea[Tp]ab)[ _]([^;/]+);? Build',
      'device_replacement' => 'Lenovo $1 $2',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1 $2',
    ],
    235 =>
     [
      'regex' => '; *(Idea(?:Tab|pad)) ?([^;/]+) Build',
      'device_replacement' => 'Lenovo $1 $2',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1 $2',
    ],
    236 =>
     [
      'regex' => '; *(ThinkPad) ?(Tablet) Build/',
      'device_replacement' => 'Lenovo $1 $2',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1 $2',
    ],
    237 =>
     [
      'regex' => '; *(?:LNV-|)(?:=?[Ll]enovo[ _\\-]?|LENOVO[ _])(.+?)(?:Build|[;/\\)])',
      'device_replacement' => 'Lenovo $1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1',
    ],
    238 =>
     [
      'regex' => '[;,] (?:Vodafone |)(SmartTab) ?(II) ?(\\d+) Build/',
      'device_replacement' => 'Lenovo $1 $2 $3',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1 $2 $3',
    ],
    239 =>
     [
      'regex' => '; *(?:Ideapad |)K1 Build/',
      'device_replacement' => 'Lenovo Ideapad K1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => 'Ideapad K1',
    ],
    240 =>
     [
      'regex' => '; *(3GC101|3GW10[01]|A390) Build/',
      'device_replacement' => '$1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1',
    ],
    241 =>
     [
      'regex' => '\\b(?:Lenovo|LENOVO)+[ _\\-]?([^,;:/ ]+)',
      'device_replacement' => 'Lenovo $1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1',
    ],
    242 =>
     [
      'regex' => '; *(MFC\\d+)[A-Z]{2}([^;,/]*),?(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Lexibook',
      'model_replacement' => '$1$2',
    ],
    243 =>
     [
      'regex' => '; *(E[34][0-9]{2}|LS[6-8][0-9]{2}|VS[6-9][0-9]+[^;/]+|Nexus 4|Nexus 5X?|GT540f?|Optimus (?:2X|G|4X HD)|OptimusX4HD) *(?:Build|;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    244 =>
     [
      'regex' => '[;:] *(L-\\d+[A-Z]|LGL\\d+[A-Z]?)(?:/V\\d+|) *(?:Build|[;\\)])',
      'device_replacement' => '$1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    245 =>
     [
      'regex' => '; *(LG-)([A-Z]{1,2}\\d{2,}[^,;/\\)\\(]*?)(?:Build| V\\d+|[,;/\\)\\(]|$)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'LG',
      'model_replacement' => '$2',
    ],
    246 =>
     [
      'regex' => '; *(LG[ \\-]|LG)([^;/]+)[;/]? Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'LG',
      'model_replacement' => '$2',
    ],
    247 =>
     [
      'regex' => '^(LG)-([^;/]+)/ Mozilla/.*; Android',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'LG',
      'model_replacement' => '$2',
    ],
    248 =>
     [
      'regex' => '(Web0S); Linux/(SmartTV)',
      'device_replacement' => 'LG $1 $2',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1 $2',
    ],
    249 =>
     [
      'regex' => '; *((?:SMB|smb)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Malata',
      'model_replacement' => '$1',
    ],
    250 =>
     [
      'regex' => '; *(?:Malata|MALATA) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Malata',
      'model_replacement' => '$1',
    ],
    251 =>
     [
      'regex' => '; *(MS[45][0-9]{3}|MID0[568][NS]?|MID[1-9]|MID[78]0[1-9]|MID970[1-9]|MID100[1-9])(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Manta',
      'model_replacement' => '$1',
    ],
    252 =>
     [
      'regex' => '; *(M1052|M806|M9000|M9100|M9701|MID100|MID120|MID125|MID130|MID135|MID140|MID701|MID710|MID713|MID727|MID728|MID731|MID732|MID733|MID735|MID736|MID737|MID760|MID800|MID810|MID820|MID830|MID833|MID835|MID860|MID900|MID930|MID933|MID960|MID980)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Match',
      'model_replacement' => '$1',
    ],
    253 =>
     [
      'regex' => '; *(GenxDroid7|MSD7.*?|AX\\d.*?|Tab 701|Tab 722)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Maxx $1',
      'brand_replacement' => 'Maxx',
      'model_replacement' => '$1',
    ],
    254 =>
     [
      'regex' => '; *(M-PP[^;/]+|PhonePad ?\\d{2,}[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Mediacom $1',
      'brand_replacement' => 'Mediacom',
      'model_replacement' => '$1',
    ],
    255 =>
     [
      'regex' => '; *(M-MP[^;/]+|SmartPad ?\\d{2,}[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Mediacom $1',
      'brand_replacement' => 'Mediacom',
      'model_replacement' => '$1',
    ],
    256 =>
     [
      'regex' => '; *(?:MD_|)LIFETAB[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Medion Lifetab $1',
      'brand_replacement' => 'Medion',
      'model_replacement' => 'Lifetab $1',
    ],
    257 =>
     [
      'regex' => '; *MEDION ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Medion $1',
      'brand_replacement' => 'Medion',
      'model_replacement' => '$1',
    ],
    258 =>
     [
      'regex' => '; *(M030|M031|M035|M040|M065|m9)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Meizu $1',
      'brand_replacement' => 'Meizu',
      'model_replacement' => '$1',
    ],
    259 =>
     [
      'regex' => '; *(?:meizu_|MEIZU )(.+?) *(?:Build|[;\\)])',
      'device_replacement' => 'Meizu $1',
      'brand_replacement' => 'Meizu',
      'model_replacement' => '$1',
    ],
    260 =>
     [
      'regex' => '; *(?:Micromax[ _](A111|A240)|(A111|A240)) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Micromax $1$2',
      'brand_replacement' => 'Micromax',
      'model_replacement' => '$1$2',
    ],
    261 =>
     [
      'regex' => '; *Micromax[ _](A\\d{2,3}[^;/]*) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Micromax $1',
      'brand_replacement' => 'Micromax',
      'model_replacement' => '$1',
    ],
    262 =>
     [
      'regex' => '; *(A\\d{2}|A[12]\\d{2}|A90S|A110Q) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Micromax $1',
      'brand_replacement' => 'Micromax',
      'model_replacement' => '$1',
    ],
    263 =>
     [
      'regex' => '; *Micromax[ _](P\\d{3}[^;/]*) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Micromax $1',
      'brand_replacement' => 'Micromax',
      'model_replacement' => '$1',
    ],
    264 =>
     [
      'regex' => '; *(P\\d{3}|P\\d{3}\\(Funbook\\)) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Micromax $1',
      'brand_replacement' => 'Micromax',
      'model_replacement' => '$1',
    ],
    265 =>
     [
      'regex' => '; *(MITO)[ _\\-]?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Mito',
      'model_replacement' => '$2',
    ],
    266 =>
     [
      'regex' => '; *(Cynus)[ _](F5|T\\d|.+?) *(?:Build|[;/\\)])',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Mobistel',
      'model_replacement' => '$1 $2',
    ],
    267 =>
     [
      'regex' => '; *(MODECOM |)(FreeTab) ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1$2 $3',
      'brand_replacement' => 'Modecom',
      'model_replacement' => '$2 $3',
    ],
    268 =>
     [
      'regex' => '; *(MODECOM )([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Modecom',
      'model_replacement' => '$2',
    ],
    269 =>
     [
      'regex' => '; *(MZ\\d{3}\\+?|MZ\\d{3} 4G|Xoom|XOOM[^;/]*) Build',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    270 =>
     [
      'regex' => '; *(Milestone )(XT[^;/]*) Build',
      'device_replacement' => 'Motorola $1$2',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$2',
    ],
    271 =>
     [
      'regex' => '; *(Motoroi ?x|Droid X|DROIDX) Build',
      'regex_flag' => 'i',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => 'DROID X',
    ],
    272 =>
     [
      'regex' => '; *(Droid[^;/]*|DROID[^;/]*|Milestone[^;/]*|Photon|Triumph|Devour|Titanium) Build',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    273 =>
     [
      'regex' => '; *(A555|A85[34][^;/]*|A95[356]|ME[58]\\d{2}\\+?|ME600|ME632|ME722|MB\\d{3}\\+?|MT680|MT710|MT870|MT887|MT917|WX435|WX453|WX44[25]|XT\\d{3,4}[A-Z\\+]*|CL[iI]Q|CL[iI]Q XT) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    274 =>
     [
      'regex' => '; *(Motorola MOT-|Motorola[ _\\-]|MOT\\-?)([^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$2',
    ],
    275 =>
     [
      'regex' => '; *(Moto[_ ]?|MOT\\-)([^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$2',
    ],
    276 =>
     [
      'regex' => '; *((?:MP[DQ]C|MPG\\d{1,4}|MP\\d{3,4}|MID(?:(?:10[234]|114|43|7[247]|8[24]|7)C|8[01]1))[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Mpman',
      'model_replacement' => '$1',
    ],
    277 =>
     [
      'regex' => '; *(?:MSI[ _]|)(Primo\\d+|Enjoy[ _\\-][^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'Msi',
      'model_replacement' => '$1',
    ],
    278 =>
     [
      'regex' => '; *Multilaser[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Multilaser',
      'model_replacement' => '$1',
    ],
    279 =>
     [
      'regex' => '; *(My)[_]?(Pad)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2 $3',
      'brand_replacement' => 'MyPhone',
      'model_replacement' => '$1$2 $3',
    ],
    280 =>
     [
      'regex' => '; *(My)\\|?(Phone)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2 $3',
      'brand_replacement' => 'MyPhone',
      'model_replacement' => '$3',
    ],
    281 =>
     [
      'regex' => '; *(A\\d+)[ _](Duo|)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'MyPhone',
      'model_replacement' => '$1 $2',
    ],
    282 =>
     [
      'regex' => '; *(myTab[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Mytab',
      'model_replacement' => '$1',
    ],
    283 =>
     [
      'regex' => '; *(NABI2?-)([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Nabi',
      'model_replacement' => '$2',
    ],
    284 =>
     [
      'regex' => '; *(N-\\d+[CDE])(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Nec',
      'model_replacement' => '$1',
    ],
    285 =>
     [
      'regex' => '; ?(NEC-)(.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Nec',
      'model_replacement' => '$2',
    ],
    286 =>
     [
      'regex' => '; *(LT-NA7)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Nec',
      'model_replacement' => 'Lifetouch Note',
    ],
    287 =>
     [
      'regex' => '; *(NXM\\d+[A-Za-z0-9_]*|Next\\d[A-Za-z0-9_ \\-]*|NEXT\\d[A-Za-z0-9_ \\-]*|Nextbook [A-Za-z0-9_ ]*|DATAM803HC|M805)(?: Build|[\\);])',
      'device_replacement' => '$1',
      'brand_replacement' => 'Nextbook',
      'model_replacement' => '$1',
    ],
    288 =>
     [
      'regex' => '; *(Nokia)([ _\\-]*)([^;/]*) Build',
      'regex_flag' => 'i',
      'device_replacement' => '$1$2$3',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$3',
    ],
    289 =>
     [
      'regex' => '; *(Nook ?|Barnes & Noble Nook |BN )([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Nook',
      'model_replacement' => '$2',
    ],
    290 =>
     [
      'regex' => '; *(NOOK |)(BNRV200|BNRV200A|BNTV250|BNTV250A|BNTV400|BNTV600|LogicPD Zoom2)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Nook',
      'model_replacement' => '$2',
    ],
    291 =>
     [
      'regex' => '; Build/(Nook)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Nook',
      'model_replacement' => 'Tablet',
    ],
    292 =>
     [
      'regex' => '; *(OP110|OliPad[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Olivetti $1',
      'brand_replacement' => 'Olivetti',
      'model_replacement' => '$1',
    ],
    293 =>
     [
      'regex' => '; *OMEGA[ _\\-](MID[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Omega $1',
      'brand_replacement' => 'Omega',
      'model_replacement' => '$1',
    ],
    294 =>
     [
      'regex' => '^(MID7500|MID\\d+) Mozilla/5\\.0 \\(iPad;',
      'device_replacement' => 'Omega $1',
      'brand_replacement' => 'Omega',
      'model_replacement' => '$1',
    ],
    295 =>
     [
      'regex' => '; *((?:CIUS|cius)[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Openpeak $1',
      'brand_replacement' => 'Openpeak',
      'model_replacement' => '$1',
    ],
    296 =>
     [
      'regex' => '; *(Find ?(?:5|7a)|R8[012]\\d{1,2}|T703\\d{0,1}|U70\\d{1,2}T?|X90\\d{1,2})(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Oppo $1',
      'brand_replacement' => 'Oppo',
      'model_replacement' => '$1',
    ],
    297 =>
     [
      'regex' => '; *OPPO ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Oppo $1',
      'brand_replacement' => 'Oppo',
      'model_replacement' => '$1',
    ],
    298 =>
     [
      'regex' => '; *(?:Odys\\-|ODYS\\-|ODYS )([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Odys $1',
      'brand_replacement' => 'Odys',
      'model_replacement' => '$1',
    ],
    299 =>
     [
      'regex' => '; *(SELECT) ?(7)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Odys $1 $2',
      'brand_replacement' => 'Odys',
      'model_replacement' => '$1 $2',
    ],
    300 =>
     [
      'regex' => '; *(PEDI)_(PLUS)_(W)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Odys $1 $2 $3',
      'brand_replacement' => 'Odys',
      'model_replacement' => '$1 $2 $3',
    ],
    301 =>
     [
      'regex' => '; *(AEON|BRAVIO|FUSION|FUSION2IN1|Genio|EOS10|IEOS[^;/]*|IRON|Loox|LOOX|LOOX Plus|Motion|NOON|NOON_PRO|NEXT|OPOS|PEDI[^;/]*|PRIME[^;/]*|STUDYTAB|TABLO|Tablet-PC-4|UNO_X8|XELIO[^;/]*|Xelio ?\\d+ ?[Pp]ro|XENO10|XPRESS PRO)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Odys $1',
      'brand_replacement' => 'Odys',
      'model_replacement' => '$1',
    ],
    302 =>
     [
      'regex' => '; (ONE [a-zA-Z]\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'OnePlus $1',
      'brand_replacement' => 'OnePlus',
      'model_replacement' => '$1',
    ],
    303 =>
     [
      'regex' => '; (ONEPLUS [a-zA-Z]\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'OnePlus $1',
      'brand_replacement' => 'OnePlus',
      'model_replacement' => '$1',
    ],
    304 =>
     [
      'regex' => '; *(TP-\\d+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Orion $1',
      'brand_replacement' => 'Orion',
      'model_replacement' => '$1',
    ],
    305 =>
     [
      'regex' => '; *(G100W?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'PackardBell $1',
      'brand_replacement' => 'PackardBell',
      'model_replacement' => '$1',
    ],
    306 =>
     [
      'regex' => '; *(Panasonic)[_ ]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    307 =>
     [
      'regex' => '; *(FZ-A1B|JT-B1)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Panasonic $1',
      'brand_replacement' => 'Panasonic',
      'model_replacement' => '$1',
    ],
    308 =>
     [
      'regex' => '; *(dL1|DL1)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Panasonic $1',
      'brand_replacement' => 'Panasonic',
      'model_replacement' => '$1',
    ],
    309 =>
     [
      'regex' => '; *(SKY[ _]|)(IM\\-[AT]\\d{3}[^;/]+).* Build/',
      'device_replacement' => 'Pantech $1$2',
      'brand_replacement' => 'Pantech',
      'model_replacement' => '$1$2',
    ],
    310 =>
     [
      'regex' => '; *((?:ADR8995|ADR910L|ADR930L|ADR930VW|PTL21|P8000)(?: 4G|)) Build/',
      'device_replacement' => '$1',
      'brand_replacement' => 'Pantech',
      'model_replacement' => '$1',
    ],
    311 =>
     [
      'regex' => '; *Pantech([^;/]+).* Build/',
      'device_replacement' => 'Pantech $1',
      'brand_replacement' => 'Pantech',
      'model_replacement' => '$1',
    ],
    312 =>
     [
      'regex' => '; *(papyre)[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Papyre',
      'model_replacement' => '$2',
    ],
    313 =>
     [
      'regex' => '; *(?:Touchlet )?(X10\\.[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Pearl $1',
      'brand_replacement' => 'Pearl',
      'model_replacement' => '$1',
    ],
    314 =>
     [
      'regex' => '; PHICOMM (i800)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Phicomm $1',
      'brand_replacement' => 'Phicomm',
      'model_replacement' => '$1',
    ],
    315 =>
     [
      'regex' => '; PHICOMM ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Phicomm $1',
      'brand_replacement' => 'Phicomm',
      'model_replacement' => '$1',
    ],
    316 =>
     [
      'regex' => '; *(FWS\\d{3}[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Phicomm $1',
      'brand_replacement' => 'Phicomm',
      'model_replacement' => '$1',
    ],
    317 =>
     [
      'regex' => '; *(D633|D822|D833|T539|T939|V726|W335|W336|W337|W3568|W536|W5510|W626|W632|W6350|W6360|W6500|W732|W736|W737|W7376|W820|W832|W8355|W8500|W8510|W930)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Philips',
      'model_replacement' => '$1',
    ],
    318 =>
     [
      'regex' => '; *(?:Philips|PHILIPS)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Philips $1',
      'brand_replacement' => 'Philips',
      'model_replacement' => '$1',
    ],
    319 =>
     [
      'regex' => 'Android 4\\..*; *(M[12356789]|U[12368]|S[123])\\ ?(pro)?(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Pipo $1$2',
      'brand_replacement' => 'Pipo',
      'model_replacement' => '$1$2',
    ],
    320 =>
     [
      'regex' => '; *(MOMO[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Ployer',
      'model_replacement' => '$1',
    ],
    321 =>
     [
      'regex' => '; *(?:Polaroid[ _]|)((?:MIDC\\d{3,}|PMID\\d{2,}|PTAB\\d{3,})[^;/]*?)(\\/[^;/]*|)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Polaroid',
      'model_replacement' => '$1',
    ],
    322 =>
     [
      'regex' => '; *(?:Polaroid )(Tablet)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Polaroid',
      'model_replacement' => '$1',
    ],
    323 =>
     [
      'regex' => '; *(POMP)[ _\\-](.+?) *(?:Build|[;/\\)])',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Pomp',
      'model_replacement' => '$2',
    ],
    324 =>
     [
      'regex' => '; *(TB07STA|TB10STA|TB07FTA|TB10FTA)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Positivo',
      'model_replacement' => '$1',
    ],
    325 =>
     [
      'regex' => '; *(?:Positivo |)((?:YPY|Ypy)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Positivo',
      'model_replacement' => '$1',
    ],
    326 =>
     [
      'regex' => '; *(MOB-[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'POV',
      'model_replacement' => '$1',
    ],
    327 =>
     [
      'regex' => '; *POV[ _\\-]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'POV $1',
      'brand_replacement' => 'POV',
      'model_replacement' => '$1',
    ],
    328 =>
     [
      'regex' => '; *((?:TAB-PLAYTAB|TAB-PROTAB|PROTAB|PlayTabPro|Mobii[ _\\-]|TAB-P)[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'POV $1',
      'brand_replacement' => 'POV',
      'model_replacement' => '$1',
    ],
    329 =>
     [
      'regex' => '; *(?:Prestigio |)((?:PAP|PMP)\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Prestigio $1',
      'brand_replacement' => 'Prestigio',
      'model_replacement' => '$1',
    ],
    330 =>
     [
      'regex' => '; *(PLT[0-9]{4}.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Proscan',
      'model_replacement' => '$1',
    ],
    331 =>
     [
      'regex' => '; *(A2|A5|A8|A900)_?(Classic|)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Qmobile',
      'model_replacement' => '$1 $2',
    ],
    332 =>
     [
      'regex' => '; *(Q[Mm]obile)_([^_]+)_([^_]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Qmobile $2 $3',
      'brand_replacement' => 'Qmobile',
      'model_replacement' => '$2 $3',
    ],
    333 =>
     [
      'regex' => '; *(Q\\-?[Mm]obile)[_ ](A[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Qmobile $2',
      'brand_replacement' => 'Qmobile',
      'model_replacement' => '$2',
    ],
    334 =>
     [
      'regex' => '; *(Q\\-Smart)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Qmobilevn',
      'model_replacement' => '$2',
    ],
    335 =>
     [
      'regex' => '; *(Q\\-?[Mm]obile)[ _\\-](S[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Qmobilevn',
      'model_replacement' => '$2',
    ],
    336 =>
     [
      'regex' => '; *(TA1013)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Quanta',
      'model_replacement' => '$1',
    ],
    337 =>
     [
      'regex' => '; (RCT\\w+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'RCA',
      'model_replacement' => '$1',
    ],
    338 =>
     [
      'regex' => '; RCA (\\w+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'RCA $1',
      'brand_replacement' => 'RCA',
      'model_replacement' => '$1',
    ],
    339 =>
     [
      'regex' => '; *(RK\\d+),?(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Rockchip',
      'model_replacement' => '$1',
    ],
    340 =>
     [
      'regex' => ' Build/(RK\\d+)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Rockchip',
      'model_replacement' => '$1',
    ],
    341 =>
     [
      'regex' => '; *(SAMSUNG |Samsung |)((?:Galaxy (?:Note II|S\\d)|GT-I9082|GT-I9205|GT-N7\\d{3}|SM-N9005)[^;/]*)\\/?[^;/]* Build/',
      'device_replacement' => 'Samsung $1$2',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    342 =>
     [
      'regex' => '; *(Google |)(Nexus [Ss](?: 4G|)) Build/',
      'device_replacement' => 'Samsung $1$2',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    343 =>
     [
      'regex' => '; *(SAMSUNG |Samsung )([^\\/]*)\\/[^ ]* Build/',
      'device_replacement' => 'Samsung $2',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    344 =>
     [
      'regex' => '; *(Galaxy(?: Ace| Nexus| S ?II+|Nexus S| with MCR 1.2| Mini Plus 4G|)) Build/',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    345 =>
     [
      'regex' => '; *(SAMSUNG[ _\\-]|)(?:SAMSUNG[ _\\-])([^;/]+) Build',
      'device_replacement' => 'Samsung $2',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    346 =>
     [
      'regex' => '; *(SAMSUNG-|)(GT\\-[BINPS]\\d{4}[^\\/]*)(\\/[^ ]*) Build',
      'device_replacement' => 'Samsung $1$2$3',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    347 =>
     [
      'regex' => '(?:; *|^)((?:GT\\-[BIiNPS]\\d{4}|I9\\d{2}0[A-Za-z\\+]?\\b)[^;/\\)]*?)(?:Build|Linux|MIUI|[;/\\)])',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    348 =>
     [
      'regex' => '; (SAMSUNG-)([A-Za-z0-9\\-]+).* Build/',
      'device_replacement' => 'Samsung $1$2',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$2',
    ],
    349 =>
     [
      'regex' => '; *((?:SCH|SGH|SHV|SHW|SPH|SC|SM)\\-[A-Za-z0-9 ]+)(/?[^ ]*|) Build',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    350 =>
     [
      'regex' => '; *((?:SC)\\-[A-Za-z0-9 ]+)(/?[^ ]*|)\\)',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    351 =>
     [
      'regex' => ' ((?:SCH)\\-[A-Za-z0-9 ]+)(/?[^ ]*|) Build',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    352 =>
     [
      'regex' => '; *(Behold ?(?:2|II)|YP\\-G[^;/]+|EK-GC100|SCL21|I9300) Build',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    353 =>
     [
      'regex' => '; *((?:SCH|SGH|SHV|SHW|SPH|SC|SM)\\-[A-Za-z0-9]{5,6})[\\)]',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    354 =>
     [
      'regex' => '; *(SH\\-?\\d\\d[^;/]+|SBM\\d[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sharp',
      'model_replacement' => '$1',
    ],
    355 =>
     [
      'regex' => '; *(SHARP[ -])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Sharp',
      'model_replacement' => '$2',
    ],
    356 =>
     [
      'regex' => '; *(SPX[_\\-]\\d[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Simvalley',
      'model_replacement' => '$1',
    ],
    357 =>
     [
      'regex' => '; *(SX7\\-PEARL\\.GmbH)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Simvalley',
      'model_replacement' => '$1',
    ],
    358 =>
     [
      'regex' => '; *(SP[T]?\\-\\d{2}[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Simvalley',
      'model_replacement' => '$1',
    ],
    359 =>
     [
      'regex' => '; *(SK\\-.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'SKtelesys',
      'model_replacement' => '$1',
    ],
    360 =>
     [
      'regex' => '; *(?:SKYTEX|SX)-([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Skytex',
      'model_replacement' => '$1',
    ],
    361 =>
     [
      'regex' => '; *(IMAGINE [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Skytex',
      'model_replacement' => '$1',
    ],
    362 =>
     [
      'regex' => '; *(SmartQ) ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    363 =>
     [
      'regex' => '; *(WF7C|WF10C|SBT[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Smartbitt',
      'model_replacement' => '$1',
    ],
    364 =>
     [
      'regex' => '; *(SBM(?:003SH|005SH|006SH|007SH|102SH)) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sharp',
      'model_replacement' => '$1',
    ],
    365 =>
     [
      'regex' => '; *(003P|101P|101P11C|102P) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Panasonic',
      'model_replacement' => '$1',
    ],
    366 =>
     [
      'regex' => '; *(00\\dZ) Build/',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    367 =>
     [
      'regex' => '; HTC(X06HT) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    368 =>
     [
      'regex' => '; *(001HT|X06HT) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    369 =>
     [
      'regex' => '; *(201M) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => 'XT902',
    ],
    370 =>
     [
      'regex' => '; *(ST\\d{4}.*)Build/ST',
      'device_replacement' => 'Trekstor $1',
      'brand_replacement' => 'Trekstor',
      'model_replacement' => '$1',
    ],
    371 =>
     [
      'regex' => '; *(ST\\d{4}.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Trekstor $1',
      'brand_replacement' => 'Trekstor',
      'model_replacement' => '$1',
    ],
    372 =>
     [
      'regex' => '; *(Sony ?Ericsson ?)([^;/]+) Build',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => '$2',
    ],
    373 =>
     [
      'regex' => '; *((?:SK|ST|E|X|LT|MK|MT|WT)\\d{2}[a-z0-9]*(?:-o|)|R800i|U20i) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => '$1',
    ],
    374 =>
     [
      'regex' => '; *(Xperia (?:A8|Arc|Acro|Active|Live with Walkman|Mini|Neo|Play|Pro|Ray|X\\d+)[^;/]*) Build',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => '$1',
    ],
    375 =>
     [
      'regex' => '; Sony (Tablet[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Sony $1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    376 =>
     [
      'regex' => '; Sony ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Sony $1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    377 =>
     [
      'regex' => '; *(Sony)([A-Za-z0-9\\-]+)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    378 =>
     [
      'regex' => '; *(Xperia [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    379 =>
     [
      'regex' => '; *(C(?:1[0-9]|2[0-9]|53|55|6[0-9])[0-9]{2}|D[25]\\d{3}|D6[56]\\d{2})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    380 =>
     [
      'regex' => '; *(SGP\\d{3}|SGPT\\d{2})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    381 =>
     [
      'regex' => '; *(NW-Z1000Series)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    382 =>
     [
      'regex' => 'PLAYSTATION 3',
      'device_replacement' => 'PlayStation 3',
      'brand_replacement' => 'Sony',
      'model_replacement' => 'PlayStation 3',
    ],
    383 =>
     [
      'regex' => '(PlayStation (?:Portable|Vita|\\d+))',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1',
    ],
    384 =>
     [
      'regex' => '; *((?:CSL_Spice|Spice|SPICE|CSL)[ _\\-]?|)([Mm][Ii])([ _\\-]|)(\\d{3}[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2$3$4',
      'brand_replacement' => 'Spice',
      'model_replacement' => 'Mi$4',
    ],
    385 =>
     [
      'regex' => '; *(Sprint )(.+?) *(?:Build|[;/])',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Sprint',
      'model_replacement' => '$2',
    ],
    386 =>
     [
      'regex' => '\\b(Sprint)[: ]([^;,/ ]+)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Sprint',
      'model_replacement' => '$2',
    ],
    387 =>
     [
      'regex' => '; *(TAGI[ ]?)(MID) ?([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2$3',
      'brand_replacement' => 'Tagi',
      'model_replacement' => '$2$3',
    ],
    388 =>
     [
      'regex' => '; *(Oyster500|Opal 800)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Tecmobile $1',
      'brand_replacement' => 'Tecmobile',
      'model_replacement' => '$1',
    ],
    389 =>
     [
      'regex' => '; *(TECNO[ _])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Tecno',
      'model_replacement' => '$2',
    ],
    390 =>
     [
      'regex' => '; *Android for (Telechips|Techvision) ([^ ]+) ',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    391 =>
     [
      'regex' => '; *(T-Hub2)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Telstra',
      'model_replacement' => '$1',
    ],
    392 =>
     [
      'regex' => '; *(PAD) ?(100[12])(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Terra $1$2',
      'brand_replacement' => 'Terra',
      'model_replacement' => '$1$2',
    ],
    393 =>
     [
      'regex' => '; *(T[BM]-\\d{3}[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Texet',
      'model_replacement' => '$1',
    ],
    394 =>
     [
      'regex' => '; *(tolino [^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Thalia',
      'model_replacement' => '$1',
    ],
    395 =>
     [
      'regex' => '; *Build/.* (TOLINO_BROWSER)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Thalia',
      'model_replacement' => 'Tolino Shine',
    ],
    396 =>
     [
      'regex' => '; *(?:CJ[ -])?(ThL|THL)[ -]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Thl',
      'model_replacement' => '$2',
    ],
    397 =>
     [
      'regex' => '; *(T100|T200|T5|W100|W200|W8s)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Thl',
      'model_replacement' => '$1',
    ],
    398 =>
     [
      'regex' => '; *(T-Mobile[ _]G2[ _]Touch) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'Hero',
    ],
    399 =>
     [
      'regex' => '; *(T-Mobile[ _]G2) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'Desire Z',
    ],
    400 =>
     [
      'regex' => '; *(T-Mobile myTouch Q) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'U8730',
    ],
    401 =>
     [
      'regex' => '; *(T-Mobile myTouch) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'U8680',
    ],
    402 =>
     [
      'regex' => '; *(T-Mobile_Espresso) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'Espresso',
    ],
    403 =>
     [
      'regex' => '; *(T-Mobile G1) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'HTC',
      'model_replacement' => 'Dream',
    ],
    404 =>
     [
      'regex' => '\\b(T-Mobile ?|)(myTouch)[ _]?([34]G)[ _]?([^\\/]*) (?:Mozilla|Build)',
      'device_replacement' => '$1$2 $3 $4',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$2 $3 $4',
    ],
    405 =>
     [
      'regex' => '\\b(T-Mobile)_([^_]+)_(.*) Build',
      'device_replacement' => '$1 $2 $3',
      'brand_replacement' => 'Tmobile',
      'model_replacement' => '$2 $3',
    ],
    406 =>
     [
      'regex' => '\\b(T-Mobile)[_ ]?(.*?)Build',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Tmobile',
      'model_replacement' => '$2',
    ],
    407 =>
     [
      'regex' => ' (ATP[0-9]{4})(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Tomtec',
      'model_replacement' => '$1',
    ],
    408 =>
     [
      'regex' => ' *(TOOKY)[ _\\-]([^;/]+?) ?(?:Build|;)',
      'regex_flag' => 'i',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Tooky',
      'model_replacement' => '$2',
    ],
    409 =>
     [
      'regex' => '\\b(TOSHIBA_AC_AND_AZ|TOSHIBA_FOLIO_AND_A|FOLIO_AND_A)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Toshiba',
      'model_replacement' => 'Folio 100',
    ],
    410 =>
     [
      'regex' => '; *([Ff]olio ?100)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Toshiba',
      'model_replacement' => 'Folio 100',
    ],
    411 =>
     [
      'regex' => '; *(AT[0-9]{2,3}(?:\\-A|LE\\-A|PE\\-A|SE|a|)|AT7-A|AT1S0|Hikari-iFrame/WDPF-[^;/]+|THRiVE|Thrive)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Toshiba $1',
      'brand_replacement' => 'Toshiba',
      'model_replacement' => '$1',
    ],
    412 =>
     [
      'regex' => '; *(TM-MID\\d+[^;/]+|TOUCHMATE|MID-750)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Touchmate',
      'model_replacement' => '$1',
    ],
    413 =>
     [
      'regex' => '; *(TM-SM\\d+[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Touchmate',
      'model_replacement' => '$1',
    ],
    414 =>
     [
      'regex' => '; *(A10 [Bb]asic2?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Treq',
      'model_replacement' => '$1',
    ],
    415 =>
     [
      'regex' => '; *(TREQ[ _\\-])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Treq',
      'model_replacement' => '$2',
    ],
    416 =>
     [
      'regex' => '; *(X-?5|X-?3)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Umeox',
      'model_replacement' => '$1',
    ],
    417 =>
     [
      'regex' => '; *(A502\\+?|A936|A603|X1|X2)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Umeox',
      'model_replacement' => '$1',
    ],
    418 =>
     [
      'regex' => '(TOUCH(?:TAB|PAD).+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Versus $1',
      'brand_replacement' => 'Versus',
      'model_replacement' => '$1',
    ],
    419 =>
     [
      'regex' => '(VERTU) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Vertu',
      'model_replacement' => '$2',
    ],
    420 =>
     [
      'regex' => '; *(Videocon)[ _\\-]([^;/]+?) *(?:Build|;)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'Videocon',
      'model_replacement' => '$2',
    ],
    421 =>
     [
      'regex' => ' (VT\\d{2}[A-Za-z]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Videocon',
      'model_replacement' => '$1',
    ],
    422 =>
     [
      'regex' => '; *((?:ViewPad|ViewPhone|VSD)[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Viewsonic',
      'model_replacement' => '$1',
    ],
    423 =>
     [
      'regex' => '; *(ViewSonic-)([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'Viewsonic',
      'model_replacement' => '$2',
    ],
    424 =>
     [
      'regex' => '; *(GTablet.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Viewsonic',
      'model_replacement' => '$1',
    ],
    425 =>
     [
      'regex' => '; *([Vv]ivo)[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'vivo',
      'model_replacement' => '$2',
    ],
    426 =>
     [
      'regex' => '(Vodafone) (.*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    427 =>
     [
      'regex' => '; *(?:Walton[ _\\-]|)(Primo[ _\\-][^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Walton $1',
      'brand_replacement' => 'Walton',
      'model_replacement' => '$1',
    ],
    428 =>
     [
      'regex' => '; *(?:WIKO[ \\-]|)(CINK\\+?|BARRY|BLOOM|DARKFULL|DARKMOON|DARKNIGHT|DARKSIDE|FIZZ|HIGHWAY|IGGY|OZZY|RAINBOW|STAIRWAY|SUBLIM|WAX|CINK [^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Wiko $1',
      'brand_replacement' => 'Wiko',
      'model_replacement' => '$1',
    ],
    429 =>
     [
      'regex' => '; *WellcoM-([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Wellcom $1',
      'brand_replacement' => 'Wellcom',
      'model_replacement' => '$1',
    ],
    430 =>
     [
      'regex' => '(?:(WeTab)-Browser|; (wetab) Build)',
      'device_replacement' => '$1',
      'brand_replacement' => 'WeTab',
      'model_replacement' => 'WeTab',
    ],
    431 =>
     [
      'regex' => '; *(AT-AS[^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Wolfgang $1',
      'brand_replacement' => 'Wolfgang',
      'model_replacement' => '$1',
    ],
    432 =>
     [
      'regex' => '; *(?:Woxter|Wxt) ([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Woxter $1',
      'brand_replacement' => 'Woxter',
      'model_replacement' => '$1',
    ],
    433 =>
     [
      'regex' => '; *(?:Xenta |Luna |)(TAB[234][0-9]{2}|TAB0[78]-\\d{3}|TAB0?9-\\d{3}|TAB1[03]-\\d{3}|SMP\\d{2}-\\d{3})(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Yarvik $1',
      'brand_replacement' => 'Yarvik',
      'model_replacement' => '$1',
    ],
    434 =>
     [
      'regex' => '; *([A-Z]{2,4})(M\\d{3,}[A-Z]{2})([^;\\)\\/]*)(?: Build|[;\\)])',
      'device_replacement' => 'Yifang $1$2$3',
      'brand_replacement' => 'Yifang',
      'model_replacement' => '$2',
    ],
    435 =>
     [
      'regex' => '; *((Mi|MI|HM|MI-ONE|Redmi)[ -](NOTE |Note |)[^;/]*) (Build|MIUI)/',
      'device_replacement' => 'XiaoMi $1',
      'brand_replacement' => 'XiaoMi',
      'model_replacement' => '$1',
    ],
    436 =>
     [
      'regex' => '; *((Mi|MI|HM|MI-ONE|Redmi)[ -](NOTE |Note |)[^;/\\)]*)',
      'device_replacement' => 'XiaoMi $1',
      'brand_replacement' => 'XiaoMi',
      'model_replacement' => '$1',
    ],
    437 =>
     [
      'regex' => '; *(MIX) (Build|MIUI)/',
      'device_replacement' => 'XiaoMi $1',
      'brand_replacement' => 'XiaoMi',
      'model_replacement' => '$1',
    ],
    438 =>
     [
      'regex' => '; *((MIX) ([^;/]*)) (Build|MIUI)/',
      'device_replacement' => 'XiaoMi $1',
      'brand_replacement' => 'XiaoMi',
      'model_replacement' => '$1',
    ],
    439 =>
     [
      'regex' => '; *XOLO[ _]([^;/]*tab.*)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Xolo $1',
      'brand_replacement' => 'Xolo',
      'model_replacement' => '$1',
    ],
    440 =>
     [
      'regex' => '; *XOLO[ _]([^;/]+?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Xolo $1',
      'brand_replacement' => 'Xolo',
      'model_replacement' => '$1',
    ],
    441 =>
     [
      'regex' => '; *(q\\d0{2,3}[a-z]?)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => 'Xolo $1',
      'brand_replacement' => 'Xolo',
      'model_replacement' => '$1',
    ],
    442 =>
     [
      'regex' => '; *(PAD ?[79]\\d+[^;/]*|TelePAD\\d+[^;/])(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'Xoro $1',
      'brand_replacement' => 'Xoro',
      'model_replacement' => '$1',
    ],
    443 =>
     [
      'regex' => '; *(?:(?:ZOPO|Zopo)[ _]([^;/]+?)|(ZP ?(?:\\d{2}[^;/]+|C2))|(C[2379]))(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2$3',
      'brand_replacement' => 'Zopo',
      'model_replacement' => '$1$2$3',
    ],
    444 =>
     [
      'regex' => '; *(ZiiLABS) (Zii[^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'ZiiLabs',
      'model_replacement' => '$2',
    ],
    445 =>
     [
      'regex' => '; *(Zii)_([^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'ZiiLabs',
      'model_replacement' => '$2',
    ],
    446 =>
     [
      'regex' => '; *(ARIZONA|(?:ATLAS|Atlas) W|D930|Grand (?:[SX][^;]*?|Era|Memo[^;]*?)|JOE|(?:Kis|KIS)\\b[^;]*?|Libra|Light [^;]*?|N8[056][01]|N850L|N8000|N9[15]\\d{2}|N9810|NX501|Optik|(?:Vip )Racer[^;]*?|RacerII|RACERII|San Francisco[^;]*?|V9[AC]|V55|V881|Z[679][0-9]{2}[A-z]?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    447 =>
     [
      'regex' => '; *([A-Z]\\d+)_USA_[^;]*(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    448 =>
     [
      'regex' => '; *(SmartTab\\d+)[^;]*(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    449 =>
     [
      'regex' => '; *(?:Blade|BLADE|ZTE-BLADE)([^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'ZTE Blade$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => 'Blade$1',
    ],
    450 =>
     [
      'regex' => '; *(?:Skate|SKATE|ZTE-SKATE)([^;/]*)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'ZTE Skate$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => 'Skate$1',
    ],
    451 =>
     [
      'regex' => '; *(Orange |Optimus )(Monte Carlo|San Francisco)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1$2',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1$2',
    ],
    452 =>
     [
      'regex' => '; *(?:ZXY-ZTE_|ZTE\\-U |ZTE[\\- _]|ZTE-C[_ ])([^;/]+?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => 'ZTE $1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    453 =>
     [
      'regex' => '; (BASE) (lutea|Lutea 2|Tab[^;]*?)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1 $2',
    ],
    454 =>
     [
      'regex' => '; (Avea inTouch 2|soft stone|tmn smart a7|Movistar[ _]Link)(?: Build|\\) AppleWebKit)',
      'regex_flag' => 'i',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    455 =>
     [
      'regex' => '; *(vp9plus)\\)',
      'device_replacement' => '$1',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1',
    ],
    456 =>
     [
      'regex' => '; ?(Cloud[ _]Z5|z1000|Z99 2G|z99|z930|z999|z990|z909|Z919|z900)(?: Build|\\) AppleWebKit)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Zync',
      'model_replacement' => '$1',
    ],
    457 =>
     [
      'regex' => '; ?(KFOT|Kindle Fire) Build\\b',
      'device_replacement' => 'Kindle Fire',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire',
    ],
    458 =>
     [
      'regex' => '; ?(KFOTE|Amazon Kindle Fire2) Build\\b',
      'device_replacement' => 'Kindle Fire 2',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire 2',
    ],
    459 =>
     [
      'regex' => '; ?(KFTT) Build\\b',
      'device_replacement' => 'Kindle Fire HD',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HD 7"',
    ],
    460 =>
     [
      'regex' => '; ?(KFJWI) Build\\b',
      'device_replacement' => 'Kindle Fire HD 8.9" WiFi',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HD 8.9" WiFi',
    ],
    461 =>
     [
      'regex' => '; ?(KFJWA) Build\\b',
      'device_replacement' => 'Kindle Fire HD 8.9" 4G',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HD 8.9" 4G',
    ],
    462 =>
     [
      'regex' => '; ?(KFSOWI) Build\\b',
      'device_replacement' => 'Kindle Fire HD 7" WiFi',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HD 7" WiFi',
    ],
    463 =>
     [
      'regex' => '; ?(KFTHWI) Build\\b',
      'device_replacement' => 'Kindle Fire HDX 7" WiFi',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HDX 7" WiFi',
    ],
    464 =>
     [
      'regex' => '; ?(KFTHWA) Build\\b',
      'device_replacement' => 'Kindle Fire HDX 7" 4G',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HDX 7" 4G',
    ],
    465 =>
     [
      'regex' => '; ?(KFAPWI) Build\\b',
      'device_replacement' => 'Kindle Fire HDX 8.9" WiFi',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HDX 8.9" WiFi',
    ],
    466 =>
     [
      'regex' => '; ?(KFAPWA) Build\\b',
      'device_replacement' => 'Kindle Fire HDX 8.9" 4G',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire HDX 8.9" 4G',
    ],
    467 =>
     [
      'regex' => '; ?Amazon ([^;/]+) Build\\b',
      'device_replacement' => '$1',
      'brand_replacement' => 'Amazon',
      'model_replacement' => '$1',
    ],
    468 =>
     [
      'regex' => '; ?(Kindle) Build\\b',
      'device_replacement' => 'Kindle',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle',
    ],
    469 =>
     [
      'regex' => '; ?(Silk)/(\\d+)\\.(\\d+)(?:\\.([0-9\\-]+)|) Build\\b',
      'device_replacement' => 'Kindle Fire',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle Fire$2',
    ],
    470 =>
     [
      'regex' => ' (Kindle)/(\\d+\\.\\d+)',
      'device_replacement' => 'Kindle',
      'brand_replacement' => 'Amazon',
      'model_replacement' => '$1 $2',
    ],
    471 =>
     [
      'regex' => ' (Silk|Kindle)/(\\d+)\\.',
      'device_replacement' => 'Kindle',
      'brand_replacement' => 'Amazon',
      'model_replacement' => 'Kindle',
    ],
    472 =>
     [
      'regex' => '(sprd)\\-([^/]+)/',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    473 =>
     [
      'regex' => '; *(H\\d{2}00\\+?) Build',
      'device_replacement' => '$1',
      'brand_replacement' => 'Hero',
      'model_replacement' => '$1',
    ],
    474 =>
     [
      'regex' => '; *(iphone|iPhone5) Build/',
      'device_replacement' => 'Xianghe $1',
      'brand_replacement' => 'Xianghe',
      'model_replacement' => '$1',
    ],
    475 =>
     [
      'regex' => '; *(e\\d{4}[a-z]?_?v\\d+|v89_[^;/]+)[^;/]+ Build/',
      'device_replacement' => 'Xianghe $1',
      'brand_replacement' => 'Xianghe',
      'model_replacement' => '$1',
    ],
    476 =>
     [
      'regex' => '\\bUSCC[_\\-]?([^ ;/\\)]+)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Cellular',
      'model_replacement' => '$1',
    ],
    477 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:ALCATEL)[^;]*; *([^;,\\)]+)',
      'device_replacement' => 'Alcatel $1',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => '$1',
    ],
    478 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|WpsLondonTest; ?|)(?:ASUS|Asus)[^;]*; *([^;,\\)]+)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    479 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:DELL|Dell)[^;]*; *([^;,\\)]+)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    480 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|WpsLondonTest; ?|)(?:HTC|Htc|HTC_blocked[^;]*)[^;]*; *(?:HTC|)([^;,\\)]+)',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    481 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:HUAWEI)[^;]*; *(?:HUAWEI |)([^;,\\)]+)',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    482 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:LG|Lg)[^;]*; *(?:LG[ \\-]|)([^;,\\)]+)',
      'device_replacement' => 'LG $1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    483 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:rv:11; |)(?:NOKIA|Nokia)[^;]*; *(?:NOKIA ?|Nokia ?|LUMIA ?|[Ll]umia ?|)(\\d{3,10}[^;\\)]*)',
      'device_replacement' => 'Lumia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => 'Lumia $1',
    ],
    484 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:NOKIA|Nokia)[^;]*; *(RM-\\d{3,})',
      'device_replacement' => 'Nokia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1',
    ],
    485 =>
     [
      'regex' => '(?:Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)]|WPDesktop;) ?(?:ARM; ?Touch; ?|Touch; ?|)(?:NOKIA|Nokia)[^;]*; *(?:NOKIA ?|Nokia ?|LUMIA ?|[Ll]umia ?|)([^;\\)]+)',
      'device_replacement' => 'Nokia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1',
    ],
    486 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|)(?:Microsoft(?: Corporation|))[^;]*; *([^;,\\)]+)',
      'device_replacement' => 'Microsoft $1',
      'brand_replacement' => 'Microsoft',
      'model_replacement' => '$1',
    ],
    487 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|WpsLondonTest; ?|)(?:SAMSUNG)[^;]*; *(?:SAMSUNG |)([^;,\\.\\)]+)',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    488 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|WpsLondonTest; ?|)(?:TOSHIBA|FujitsuToshibaMobileCommun)[^;]*; *([^;,\\)]+)',
      'device_replacement' => 'Toshiba $1',
      'brand_replacement' => 'Toshiba',
      'model_replacement' => '$1',
    ],
    489 =>
     [
      'regex' => 'Windows Phone [^;]+; .*?IEMobile/[^;\\)]+[;\\)] ?(?:ARM; ?Touch; ?|Touch; ?|WpsLondonTest; ?|)([^;]+); *([^;,\\)]+)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    490 =>
     [
      'regex' => '(?:^|; )SAMSUNG\\-([A-Za-z0-9\\-]+).* Bada/',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    491 =>
     [
      'regex' => '\\(Mobile; ALCATEL ?(One|ONE) ?(Touch|TOUCH) ?([^;/]+?)(?:/[^;]+|); rv:[^\\)]+\\) Gecko/[^\\/]+ Firefox/',
      'device_replacement' => 'Alcatel $1 $2 $3',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => 'One Touch $3',
    ],
    492 =>
     [
      'regex' => '\\(Mobile; (?:ZTE([^;]+)|(OpenC)); rv:[^\\)]+\\) Gecko/[^\\/]+ Firefox/',
      'device_replacement' => 'ZTE $1$2',
      'brand_replacement' => 'ZTE',
      'model_replacement' => '$1$2',
    ],
    493 =>
     [
      'regex' => '\\(Mobile; ALCATEL([A-Za-z0-9\\-]+); rv:[^\\)]+\\) Gecko/[^\\/]+ Firefox/[^\\/]+ KaiOS/',
      'device_replacement' => 'Alcatel $1',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => '$1',
    ],
    494 =>
     [
      'regex' => '\\(Mobile; LYF\\/([A-Za-z0-9\\-]+)\\/.+;.+rv:[^\\)]+\\) Gecko/[^\\/]+ Firefox/[^\\/]+ KAIOS/',
      'device_replacement' => 'LYF $1',
      'brand_replacement' => 'LYF',
      'model_replacement' => '$1',
    ],
    495 =>
     [
      'regex' => '\\(Mobile; Nokia_([A-Za-z0-9\\-]+)_.+; rv:[^\\)]+\\) Gecko/[^\\/]+ Firefox/[^\\/]+ KAIOS/',
      'device_replacement' => 'Nokia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1',
    ],
    496 =>
     [
      'regex' => 'Nokia(N[0-9]+)([A-Za-z_\\-][A-Za-z0-9_\\-]*)',
      'device_replacement' => 'Nokia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1$2',
    ],
    497 =>
     [
      'regex' => '(?:NOKIA|Nokia)(?:\\-| *)(?:([A-Za-z0-9]+)\\-[0-9a-f]{32}|([A-Za-z0-9\\-]+)(?:UCBrowser)|([A-Za-z0-9\\-]+))',
      'device_replacement' => 'Nokia $1$2$3',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1$2$3',
    ],
    498 =>
     [
      'regex' => 'Lumia ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Lumia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => 'Lumia $1',
    ],
    499 =>
     [
      'regex' => '\\(Symbian; U; S60 V5; [A-z]{2}\\-[A-z]{2}; (SonyEricsson|Samsung|Nokia|LG)([^;/]+?)\\)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    500 =>
     [
      'regex' => '\\(Symbian(?:/3|); U; ([^;]+);',
      'device_replacement' => 'Nokia $1',
      'brand_replacement' => 'Nokia',
      'model_replacement' => '$1',
    ],
    501 =>
     [
      'regex' => 'BB10; ([A-Za-z0-9\\- ]+)\\)',
      'device_replacement' => 'BlackBerry $1',
      'brand_replacement' => 'BlackBerry',
      'model_replacement' => '$1',
    ],
    502 =>
     [
      'regex' => 'Play[Bb]ook.+RIM Tablet OS',
      'device_replacement' => 'BlackBerry Playbook',
      'brand_replacement' => 'BlackBerry',
      'model_replacement' => 'Playbook',
    ],
    503 =>
     [
      'regex' => 'Black[Bb]erry ([0-9]+);',
      'device_replacement' => 'BlackBerry $1',
      'brand_replacement' => 'BlackBerry',
      'model_replacement' => '$1',
    ],
    504 =>
     [
      'regex' => 'Black[Bb]erry([0-9]+)',
      'device_replacement' => 'BlackBerry $1',
      'brand_replacement' => 'BlackBerry',
      'model_replacement' => '$1',
    ],
    505 =>
     [
      'regex' => 'Black[Bb]erry;',
      'device_replacement' => 'BlackBerry',
      'brand_replacement' => 'BlackBerry',
    ],
    506 =>
     [
      'regex' => '(Pre|Pixi)/\\d+\\.\\d+',
      'device_replacement' => 'Palm $1',
      'brand_replacement' => 'Palm',
      'model_replacement' => '$1',
    ],
    507 =>
     [
      'regex' => 'Palm([0-9]+)',
      'device_replacement' => 'Palm $1',
      'brand_replacement' => 'Palm',
      'model_replacement' => '$1',
    ],
    508 =>
     [
      'regex' => 'Treo([A-Za-z0-9]+)',
      'device_replacement' => 'Palm Treo $1',
      'brand_replacement' => 'Palm',
      'model_replacement' => 'Treo $1',
    ],
    509 =>
     [
      'regex' => 'webOS.*(P160U(?:NA|))/(\\d+).(\\d+)',
      'device_replacement' => 'HP Veer',
      'brand_replacement' => 'HP',
      'model_replacement' => 'Veer',
    ],
    510 =>
     [
      'regex' => '(Touch[Pp]ad)/\\d+\\.\\d+',
      'device_replacement' => 'HP TouchPad',
      'brand_replacement' => 'HP',
      'model_replacement' => 'TouchPad',
    ],
    511 =>
     [
      'regex' => 'HPiPAQ([A-Za-z0-9]+)/\\d+.\\d+',
      'device_replacement' => 'HP iPAQ $1',
      'brand_replacement' => 'HP',
      'model_replacement' => 'iPAQ $1',
    ],
    512 =>
     [
      'regex' => 'PDA; (PalmOS)/sony/model ([a-z]+)/Revision',
      'device_replacement' => '$1',
      'brand_replacement' => 'Sony',
      'model_replacement' => '$1 $2',
    ],
    513 =>
     [
      'regex' => '(Apple\\s?TV)',
      'device_replacement' => 'AppleTV',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'AppleTV',
    ],
    514 =>
     [
      'regex' => '(QtCarBrowser)',
      'device_replacement' => 'Tesla Model S',
      'brand_replacement' => 'Tesla',
      'model_replacement' => 'Model S',
    ],
    515 =>
     [
      'regex' => '(iPhone|iPad|iPod)(\\d+,\\d+)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1$2',
    ],
    516 =>
     [
      'regex' => '(iPad)(?:;| Simulator;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1',
    ],
    517 =>
     [
      'regex' => '(iPod)(?:;| touch;| Simulator;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1',
    ],
    518 =>
     [
      'regex' => '(iPhone)(?:;| Simulator;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1',
    ],
    519 =>
     [
      'regex' => '(Watch)(\\d+,\\d+)',
      'device_replacement' => 'Apple $1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1$2',
    ],
    520 =>
     [
      'regex' => '(Apple Watch)(?:;| Simulator;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1',
    ],
    521 =>
     [
      'regex' => '(HomePod)(?:;| Simulator;)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1',
    ],
    522 =>
     [
      'regex' => 'iPhone',
      'device_replacement' => 'iPhone',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'iPhone',
    ],
    523 =>
     [
      'regex' => 'CFNetwork/.* Darwin/\\d.*\\(((?:Mac|iMac|PowerMac|PowerBook)[^\\d]*)(\\d+)(?:,|%2C)(\\d+)',
      'device_replacement' => '$1$2,$3',
      'brand_replacement' => 'Apple',
      'model_replacement' => '$1$2,$3',
    ],
    524 =>
     [
      'regex' => 'CFNetwork/.* Darwin/\\d+\\.\\d+\\.\\d+ \\(x86_64\\)',
      'device_replacement' => 'Mac',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'Mac',
    ],
    525 =>
     [
      'regex' => 'CFNetwork/.* Darwin/\\d',
      'device_replacement' => 'iOS-Device',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'iOS-Device',
    ],
    526 =>
     [
      'regex' => 'Outlook-(iOS)/\\d+\\.\\d+\\.prod\\.iphone',
      'brand_replacement' => 'Apple',
      'device_replacement' => 'iPhone',
      'model_replacement' => 'iPhone',
    ],
    527 =>
     [
      'regex' => 'acer_([A-Za-z0-9]+)_',
      'device_replacement' => 'Acer $1',
      'brand_replacement' => 'Acer',
      'model_replacement' => '$1',
    ],
    528 =>
     [
      'regex' => '(?:ALCATEL|Alcatel)-([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Alcatel $1',
      'brand_replacement' => 'Alcatel',
      'model_replacement' => '$1',
    ],
    529 =>
     [
      'regex' => '(?:Amoi|AMOI)\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Amoi $1',
      'brand_replacement' => 'Amoi',
      'model_replacement' => '$1',
    ],
    530 =>
     [
      'regex' => '(?:; |\\/|^)((?:Transformer (?:Pad|Prime) |Transformer |PadFone[ _]?)[A-Za-z0-9]*)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    531 =>
     [
      'regex' => '(?:asus.*?ASUS|Asus|ASUS|asus)[\\- ;]*((?:Transformer (?:Pad|Prime) |Transformer |Padfone |Nexus[ _]|)[A-Za-z0-9]+)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    532 =>
     [
      'regex' => '(?:ASUS)_([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Asus $1',
      'brand_replacement' => 'Asus',
      'model_replacement' => '$1',
    ],
    533 =>
     [
      'regex' => '\\bBIRD[ \\-\\.]([A-Za-z0-9]+)',
      'device_replacement' => 'Bird $1',
      'brand_replacement' => 'Bird',
      'model_replacement' => '$1',
    ],
    534 =>
     [
      'regex' => '\\bDell ([A-Za-z0-9]+)',
      'device_replacement' => 'Dell $1',
      'brand_replacement' => 'Dell',
      'model_replacement' => '$1',
    ],
    535 =>
     [
      'regex' => 'DoCoMo/2\\.0 ([A-Za-z0-9]+)',
      'device_replacement' => 'DoCoMo $1',
      'brand_replacement' => 'DoCoMo',
      'model_replacement' => '$1',
    ],
    536 =>
     [
      'regex' => '([A-Za-z0-9]+)_W;FOMA',
      'device_replacement' => 'DoCoMo $1',
      'brand_replacement' => 'DoCoMo',
      'model_replacement' => '$1',
    ],
    537 =>
     [
      'regex' => '([A-Za-z0-9]+);FOMA',
      'device_replacement' => 'DoCoMo $1',
      'brand_replacement' => 'DoCoMo',
      'model_replacement' => '$1',
    ],
    538 =>
     [
      'regex' => '\\b(?:HTC/|HTC/[a-z0-9]+/|)HTC[ _\\-;]? *(.*?)(?:-?Mozilla|fingerPrint|[;/\\(\\)]|$)',
      'device_replacement' => 'HTC $1',
      'brand_replacement' => 'HTC',
      'model_replacement' => '$1',
    ],
    539 =>
     [
      'regex' => 'Huawei([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    540 =>
     [
      'regex' => 'HUAWEI-([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    541 =>
     [
      'regex' => 'HUAWEI ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Huawei $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => '$1',
    ],
    542 =>
     [
      'regex' => 'vodafone([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei Vodafone $1',
      'brand_replacement' => 'Huawei',
      'model_replacement' => 'Vodafone $1',
    ],
    543 =>
     [
      'regex' => 'i\\-mate ([A-Za-z0-9]+)',
      'device_replacement' => 'i-mate $1',
      'brand_replacement' => 'i-mate',
      'model_replacement' => '$1',
    ],
    544 =>
     [
      'regex' => 'Kyocera\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Kyocera $1',
      'brand_replacement' => 'Kyocera',
      'model_replacement' => '$1',
    ],
    545 =>
     [
      'regex' => 'KWC\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Kyocera $1',
      'brand_replacement' => 'Kyocera',
      'model_replacement' => '$1',
    ],
    546 =>
     [
      'regex' => 'Lenovo[_\\-]([A-Za-z0-9]+)',
      'device_replacement' => 'Lenovo $1',
      'brand_replacement' => 'Lenovo',
      'model_replacement' => '$1',
    ],
    547 =>
     [
      'regex' => '(HbbTV)/[0-9]+\\.[0-9]+\\.[0-9]+ \\([^;]*; *(LG)E *; *([^;]*) *;[^;]*;[^;]*;\\)',
      'device_replacement' => '$1',
      'brand_replacement' => '$2',
      'model_replacement' => '$3',
    ],
    548 =>
     [
      'regex' => '(HbbTV)/1\\.1\\.1.*CE-HTML/1\\.\\d;(Vendor/|)(THOM[^;]*?)[;\\s].{0,30}(LF[^;]+);?',
      'device_replacement' => '$1',
      'brand_replacement' => 'Thomson',
      'model_replacement' => '$4',
    ],
    549 =>
     [
      'regex' => '(HbbTV)(?:/1\\.1\\.1|) ?(?: \\(;;;;;\\)|); *CE-HTML(?:/1\\.\\d|); *([^ ]+) ([^;]+);',
      'device_replacement' => '$1',
      'brand_replacement' => '$2',
      'model_replacement' => '$3',
    ],
    550 =>
     [
      'regex' => '(HbbTV)/1\\.1\\.1 \\(;;;;;\\) Maple_2011',
      'device_replacement' => '$1',
      'brand_replacement' => 'Samsung',
    ],
    551 =>
     [
      'regex' => '(HbbTV)/[0-9]+\\.[0-9]+\\.[0-9]+ \\([^;]*; *(?:CUS:([^;]*)|([^;]+)) *; *([^;]*) *;.*;',
      'device_replacement' => '$1',
      'brand_replacement' => '$2$3',
      'model_replacement' => '$4',
    ],
    552 =>
     [
      'regex' => '(HbbTV)/[0-9]+\\.[0-9]+\\.[0-9]+',
      'device_replacement' => '$1',
    ],
    553 =>
     [
      'regex' => 'LGE; (?:Media\\/|)([^;]*);[^;]*;[^;]*;?\\); "?LG NetCast(\\.TV|\\.Media|)-\\d+',
      'device_replacement' => 'NetCast$2',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    554 =>
     [
      'regex' => 'InettvBrowser/[0-9]+\\.[0-9A-Z]+ \\([^;]*;(Sony)([^;]*);[^;]*;[^\\)]*\\)',
      'device_replacement' => 'Inettv',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    555 =>
     [
      'regex' => 'InettvBrowser/[0-9]+\\.[0-9A-Z]+ \\([^;]*;([^;]*);[^;]*;[^\\)]*\\)',
      'device_replacement' => 'Inettv',
      'brand_replacement' => 'Generic_Inettv',
      'model_replacement' => '$1',
    ],
    556 =>
     [
      'regex' => '(?:InettvBrowser|TSBNetTV|NETTV|HBBTV)',
      'device_replacement' => 'Inettv',
      'brand_replacement' => 'Generic_Inettv',
    ],
    557 =>
     [
      'regex' => 'Series60/\\d\\.\\d (LG)[\\-]?([A-Za-z0-9 \\-]+)',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    558 =>
     [
      'regex' => '\\b(?:LGE[ \\-]LG\\-(?:AX|)|LGE |LGE?-LG|LGE?[ \\-]|LG[ /\\-]|lg[\\-])([A-Za-z0-9]+)\\b',
      'device_replacement' => 'LG $1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    559 =>
     [
      'regex' => '(?:^LG[\\-]?|^LGE[\\-/]?)([A-Za-z]+[0-9]+[A-Za-z]*)',
      'device_replacement' => 'LG $1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    560 =>
     [
      'regex' => '^LG([0-9]+[A-Za-z]*)',
      'device_replacement' => 'LG $1',
      'brand_replacement' => 'LG',
      'model_replacement' => '$1',
    ],
    561 =>
     [
      'regex' => '(KIN\\.[^ ]+) (\\d+)\\.(\\d+)',
      'device_replacement' => 'Microsoft $1',
      'brand_replacement' => 'Microsoft',
      'model_replacement' => '$1',
    ],
    562 =>
     [
      'regex' => '(?:MSIE|XBMC).*\\b(Xbox)\\b',
      'device_replacement' => '$1',
      'brand_replacement' => 'Microsoft',
      'model_replacement' => '$1',
    ],
    563 =>
     [
      'regex' => '; ARM; Trident/6\\.0; Touch[\\);]',
      'device_replacement' => 'Microsoft Surface RT',
      'brand_replacement' => 'Microsoft',
      'model_replacement' => 'Surface RT',
    ],
    564 =>
     [
      'regex' => 'Motorola\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    565 =>
     [
      'regex' => 'MOTO\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    566 =>
     [
      'regex' => 'MOT\\-([A-z0-9][A-z0-9\\-]*)',
      'device_replacement' => 'Motorola $1',
      'brand_replacement' => 'Motorola',
      'model_replacement' => '$1',
    ],
    567 =>
     [
      'regex' => 'Nintendo WiiU',
      'device_replacement' => 'Nintendo Wii U',
      'brand_replacement' => 'Nintendo',
      'model_replacement' => 'Wii U',
    ],
    568 =>
     [
      'regex' => 'Nintendo (DS|3DS|DSi|Wii);',
      'device_replacement' => 'Nintendo $1',
      'brand_replacement' => 'Nintendo',
      'model_replacement' => '$1',
    ],
    569 =>
     [
      'regex' => '(?:Pantech|PANTECH)[ _-]?([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Pantech $1',
      'brand_replacement' => 'Pantech',
      'model_replacement' => '$1',
    ],
    570 =>
     [
      'regex' => 'Philips([A-Za-z0-9]+)',
      'device_replacement' => 'Philips $1',
      'brand_replacement' => 'Philips',
      'model_replacement' => '$1',
    ],
    571 =>
     [
      'regex' => 'Philips ([A-Za-z0-9]+)',
      'device_replacement' => 'Philips $1',
      'brand_replacement' => 'Philips',
      'model_replacement' => '$1',
    ],
    572 =>
     [
      'regex' => '(SMART-TV); .* Tizen ',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    573 =>
     [
      'regex' => 'SymbianOS/9\\.\\d.* Samsung[/\\-]([A-Za-z0-9 \\-]+)',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    574 =>
     [
      'regex' => '(Samsung)(SGH)(i[0-9]+)',
      'device_replacement' => '$1 $2$3',
      'brand_replacement' => '$1',
      'model_replacement' => '$2-$3',
    ],
    575 =>
     [
      'regex' => 'SAMSUNG-ANDROID-MMS/([^;/]+)',
      'device_replacement' => '$1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    576 =>
     [
      'regex' => 'SAMSUNG(?:; |[ -/])([A-Za-z0-9\\-]+)',
      'regex_flag' => 'i',
      'device_replacement' => 'Samsung $1',
      'brand_replacement' => 'Samsung',
      'model_replacement' => '$1',
    ],
    577 =>
     [
      'regex' => '(Dreamcast)',
      'device_replacement' => 'Sega $1',
      'brand_replacement' => 'Sega',
      'model_replacement' => '$1',
    ],
    578 =>
     [
      'regex' => '^SIE-([A-Za-z0-9]+)',
      'device_replacement' => 'Siemens $1',
      'brand_replacement' => 'Siemens',
      'model_replacement' => '$1',
    ],
    579 =>
     [
      'regex' => 'Softbank/[12]\\.0/([A-Za-z0-9]+)',
      'device_replacement' => 'Softbank $1',
      'brand_replacement' => 'Softbank',
      'model_replacement' => '$1',
    ],
    580 =>
     [
      'regex' => 'SonyEricsson ?([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Ericsson $1',
      'brand_replacement' => 'SonyEricsson',
      'model_replacement' => '$1',
    ],
    581 =>
     [
      'regex' => 'Android [^;]+; ([^ ]+) (Sony)/',
      'device_replacement' => '$2 $1',
      'brand_replacement' => '$2',
      'model_replacement' => '$1',
    ],
    582 =>
     [
      'regex' => '(Sony)(?:BDP\\/|\\/|)([^ /;\\)]+)[ /;\\)]',
      'device_replacement' => '$1 $2',
      'brand_replacement' => '$1',
      'model_replacement' => '$2',
    ],
    583 =>
     [
      'regex' => 'Puffin/[\\d\\.]+IT',
      'device_replacement' => 'iPad',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'iPad',
    ],
    584 =>
     [
      'regex' => 'Puffin/[\\d\\.]+IP',
      'device_replacement' => 'iPhone',
      'brand_replacement' => 'Apple',
      'model_replacement' => 'iPhone',
    ],
    585 =>
     [
      'regex' => 'Puffin/[\\d\\.]+AT',
      'device_replacement' => 'Generic Tablet',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Tablet',
    ],
    586 =>
     [
      'regex' => 'Puffin/[\\d\\.]+AP',
      'device_replacement' => 'Generic Smartphone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Smartphone',
    ],
    587 =>
     [
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+; [A-Za-z]{2}\\-[A-Za-z]{0,2}; WOWMobile (.+)( Build[/ ]|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    588 =>
     [
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+\\-update1; [A-Za-z]{2}\\-[A-Za-z]{0,2} *; *(.+?)( Build[/ ]|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    589 =>
     [
      'regex' => 'Android[\\- ][\\d]+(?:\\.[\\d]+)(?:\\.[\\d]+|); *[A-Za-z]{2}[_\\-][A-Za-z]{0,2}\\-? *; *(.+?)( Build[/ ]|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    590 =>
     [
      'regex' => 'Android[\\- ][\\d]+(?:\\.[\\d]+)(?:\\.[\\d]+|); *[A-Za-z]{0,2}\\- *; *(.+?)( Build[/ ]|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    591 =>
     [
      'regex' => 'Android[\\- ][\\d]+(?:\\.[\\d]+)(?:\\.[\\d]+|); *[a-z]{0,2}[_\\-]?[A-Za-z]{0,2};?( Build[/ ]|\\))',
      'device_replacement' => 'Generic Smartphone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Smartphone',
    ],
    592 =>
     [
      'regex' => 'Android[\\- ][\\d]+(?:\\.[\\d]+)(?:\\.[\\d]+|); *\\-?[A-Za-z]{2}; *(.+?)( Build[/ ]|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    593 =>
     [
      'regex' => 'Android \\d+?(?:\\.\\d+|)(?:\\.\\d+|); ([^;]+?)(?: Build|\\) AppleWebKit).+? Mobile Safari',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    594 =>
     [
      'regex' => 'Android \\d+?(?:\\.\\d+|)(?:\\.\\d+|); ([^;]+?)(?: Build|\\) AppleWebKit).+? Safari',
      'brand_replacement' => 'Generic_Android_Tablet',
      'model_replacement' => '$1',
    ],
    595 =>
     [
      'regex' => 'Android \\d+?(?:\\.\\d+|)(?:\\.\\d+|); ([^;]+?)(?: Build|\\))',
      'brand_replacement' => 'Generic_Android',
      'model_replacement' => '$1',
    ],
    596 =>
     [
      'regex' => '(GoogleTV)',
      'brand_replacement' => 'Generic_Inettv',
      'model_replacement' => '$1',
    ],
    597 =>
     [
      'regex' => '(WebTV)/\\d+.\\d+',
      'brand_replacement' => 'Generic_Inettv',
      'model_replacement' => '$1',
    ],
    598 =>
     [
      'regex' => '^(Roku)/DVP-\\d+\\.\\d+',
      'brand_replacement' => 'Generic_Inettv',
      'model_replacement' => '$1',
    ],
    599 =>
     [
      'regex' => '(Android 3\\.\\d|Opera Tablet|Tablet; .+Firefox/|Android.*(?:Tab|Pad))',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Tablet',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Tablet',
    ],
    600 =>
     [
      'regex' => '(Symbian|\\bS60(Version|V\\d)|\\bS60\\b|\\((Series 60|Windows Mobile|Palm OS|Bada); Opera Mini|Windows CE|Opera Mobi|BREW|Brew|Mobile; .+Firefox/|iPhone OS|Android|MobileSafari|Windows *Phone|\\(webOS/|PalmOS)',
      'device_replacement' => 'Generic Smartphone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Smartphone',
    ],
    601 =>
     [
      'regex' => '(hiptop|avantgo|plucker|xiino|blazer|elaine)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Smartphone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Smartphone',
    ],
    602 =>
     [
      'regex' => '(bot|BUbiNG|zao|borg|DBot|oegp|silk|Xenu|zeal|^NING|CCBot|crawl|htdig|lycos|slurp|teoma|voila|yahoo|Sogou|CiBra|Nutch|^Java/|^JNLP/|Daumoa|Daum|Genieo|ichiro|larbin|pompos|Scrapy|snappy|speedy|spider|msnbot|msrbot|vortex|^vortex|crawler|favicon|indexer|Riddler|scooter|scraper|scrubby|WhatWeb|WinHTTP|bingbot|BingPreview|openbot|gigabot|furlbot|polybot|seekbot|^voyager|archiver|Icarus6j|mogimogi|Netvibes|blitzbot|altavista|charlotte|findlinks|Retreiver|TLSProber|WordPress|SeznamBot|ProoXiBot|wsr\\-agent|Squrl Java|EtaoSpider|PaperLiBot|SputnikBot|A6\\-Indexer|netresearch|searchsight|baiduspider|YisouSpider|ICC\\-Crawler|http%20client|Python-urllib|dataparksearch|converacrawler|Screaming Frog|AppEngine-Google|YahooCacheSystem|fast\\-webcrawler|Sogou Pic Spider|semanticdiscovery|Innovazion Crawler|facebookexternalhit|Google.*/\\+/web/snippet|Google-HTTP-Java-Client|BlogBridge|IlTrovatore-Setaccio|InternetArchive|GomezAgent|WebThumbnail|heritrix|NewsGator|PagePeeker|Reaper|ZooShot|holmes|NL-Crawler|Pingdom|StatusCake|WhatsApp|masscan|Google Web Preview|Qwantify|Yeti|OgScrper)',
      'regex_flag' => 'i',
      'device_replacement' => 'Spider',
      'brand_replacement' => 'Spider',
      'model_replacement' => 'Desktop',
    ],
    603 =>
     [
      'regex' => '^(1207|3gso|4thp|501i|502i|503i|504i|505i|506i|6310|6590|770s|802s|a wa|acer|acs\\-|airn|alav|asus|attw|au\\-m|aur |aus |abac|acoo|aiko|alco|alca|amoi|anex|anny|anyw|aptu|arch|argo|bmobile|bell|bird|bw\\-n|bw\\-u|beck|benq|bilb|blac|c55/|cdm\\-|chtm|capi|comp|cond|dall|dbte|dc\\-s|dica|ds\\-d|ds12|dait|devi|dmob|doco|dopo|dorado|el(?:38|39|48|49|50|55|58|68)|el[3456]\\d{2}dual|erk0|esl8|ex300|ez40|ez60|ez70|ezos|ezze|elai|emul|eric|ezwa|fake|fly\\-|fly_|g\\-mo|g1 u|g560|gf\\-5|grun|gene|go.w|good|grad|hcit|hd\\-m|hd\\-p|hd\\-t|hei\\-|hp i|hpip|hs\\-c|htc |htc\\-|htca|htcg)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
    604 =>
     [
      'regex' => '^(htcp|htcs|htct|htc_|haie|hita|huaw|hutc|i\\-20|i\\-go|i\\-ma|i\\-mobile|i230|iac|iac\\-|iac/|ig01|im1k|inno|iris|jata|kddi|kgt|kgt/|kpt |kwc\\-|klon|lexi|lg g|lg\\-a|lg\\-b|lg\\-c|lg\\-d|lg\\-f|lg\\-g|lg\\-k|lg\\-l|lg\\-m|lg\\-o|lg\\-p|lg\\-s|lg\\-t|lg\\-u|lg\\-w|lg/k|lg/l|lg/u|lg50|lg54|lge\\-|lge/|leno|m1\\-w|m3ga|m50/|maui|mc01|mc21|mcca|medi|meri|mio8|mioa|mo01|mo02|mode|modo|mot |mot\\-|mt50|mtp1|mtv |mate|maxo|merc|mits|mobi|motv|mozz|n100|n101|n102|n202|n203|n300|n302|n500|n502|n505|n700|n701|n710|nec\\-|nem\\-|newg|neon)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
    605 =>
     [
      'regex' => '^(netf|noki|nzph|o2 x|o2\\-x|opwv|owg1|opti|oran|ot\\-s|p800|pand|pg\\-1|pg\\-2|pg\\-3|pg\\-6|pg\\-8|pg\\-c|pg13|phil|pn\\-2|pt\\-g|palm|pana|pire|pock|pose|psio|qa\\-a|qc\\-2|qc\\-3|qc\\-5|qc\\-7|qc07|qc12|qc21|qc32|qc60|qci\\-|qwap|qtek|r380|r600|raks|rim9|rove|s55/|sage|sams|sc01|sch\\-|scp\\-|sdk/|se47|sec\\-|sec0|sec1|semc|sgh\\-|shar|sie\\-|sk\\-0|sl45|slid|smb3|smt5|sp01|sph\\-|spv |spv\\-|sy01|samm|sany|sava|scoo|send|siem|smar|smit|soft|sony|t\\-mo|t218|t250|t600|t610|t618|tcl\\-|tdg\\-|telm|tim\\-|ts70|tsm\\-|tsm3|tsm5|tx\\-9|tagt)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
    606 =>
     [
      'regex' => '^(talk|teli|topl|tosh|up.b|upg1|utst|v400|v750|veri|vk\\-v|vk40|vk50|vk52|vk53|vm40|vx98|virg|vertu|vite|voda|vulc|w3c |w3c\\-|wapj|wapp|wapu|wapm|wig |wapi|wapr|wapv|wapy|wapa|waps|wapt|winc|winw|wonu|x700|xda2|xdag|yas\\-|your|zte\\-|zeto|aste|audi|avan|blaz|brew|brvw|bumb|ccwa|cell|cldc|cmd\\-|dang|eml2|fetc|hipt|http|ibro|idea|ikom|ipaq|jbro|jemu|jigs|keji|kyoc|kyok|libw|m\\-cr|midp|mmef|moto|mwbp|mywa|newt|nok6|o2im|pant|pdxg|play|pluc|port|prox|rozo|sama|seri|smal|symb|treo|upsi|vx52|vx53|vx60|vx61|vx70|vx80|vx81|vx83|vx85|wap\\-|webc|whit|wmlb|xda\\-|xda_)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
    607 =>
     [
      'regex' => '^(Ice)$',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
    608 =>
     [
      'regex' => '(wap[\\-\\ ]browser|maui|netfront|obigo|teleca|up\\.browser|midp|Opera Mini)',
      'regex_flag' => 'i',
      'device_replacement' => 'Generic Feature Phone',
      'brand_replacement' => 'Generic',
      'model_replacement' => 'Feature Phone',
    ],
  ],
];