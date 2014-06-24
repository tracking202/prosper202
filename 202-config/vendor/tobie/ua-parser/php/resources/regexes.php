<?php
return array (
  'user_agent_parsers' => 
  array (
    0 => 
    array (
      'regex' => '(HbbTV)/(\\d+)\\.(\\d+)\\.(\\d+) \\(',
    ),
    1 => 
    array (
      'regex' => '(SeaMonkey|Camino)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+[a-z]*)',
    ),
    2 => 
    array (
      'regex' => '(Pale[Mm]oon)/(\\d+)\\.(\\d+)\\.?(\\d+)?',
      'family_replacement' => 'Pale Moon (Firefox Variant)',
    ),
    3 => 
    array (
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)\\.?([ab]?\\d+[a-z]*)',
      'family_replacement' => 'Firefox Mobile',
    ),
    4 => 
    array (
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)(pre)',
      'family_replacement' => 'Firefox Mobile',
    ),
    5 => 
    array (
      'regex' => '(Fennec)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Firefox Mobile',
    ),
    6 => 
    array (
      'regex' => 'Mobile.*(Firefox)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Firefox Mobile',
    ),
    7 => 
    array (
      'regex' => '(Namoroka|Shiretoko|Minefield)/(\\d+)\\.(\\d+)\\.(\\d+(?:pre)?)',
      'family_replacement' => 'Firefox ($1)',
    ),
    8 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(a\\d+[a-z]*)',
      'family_replacement' => 'Firefox Alpha',
    ),
    9 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(b\\d+[a-z]*)',
      'family_replacement' => 'Firefox Beta',
    ),
    10 => 
    array (
      'regex' => '(Firefox)-(?:\\d+\\.\\d+)?/(\\d+)\\.(\\d+)(a\\d+[a-z]*)',
      'family_replacement' => 'Firefox Alpha',
    ),
    11 => 
    array (
      'regex' => '(Firefox)-(?:\\d+\\.\\d+)?/(\\d+)\\.(\\d+)(b\\d+[a-z]*)',
      'family_replacement' => 'Firefox Beta',
    ),
    12 => 
    array (
      'regex' => '(Namoroka|Shiretoko|Minefield)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*)?',
      'family_replacement' => 'Firefox ($1)',
    ),
    13 => 
    array (
      'regex' => '(Firefox).*Tablet browser (\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'MicroB',
    ),
    14 => 
    array (
      'regex' => '(MozillaDeveloperPreview)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*)?',
    ),
    15 => 
    array (
      'regex' => '(Flock)/(\\d+)\\.(\\d+)(b\\d+?)',
    ),
    16 => 
    array (
      'regex' => '(RockMelt)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    17 => 
    array (
      'regex' => '(Navigator)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Netscape',
    ),
    18 => 
    array (
      'regex' => '(Navigator)/(\\d+)\\.(\\d+)([ab]\\d+)',
      'family_replacement' => 'Netscape',
    ),
    19 => 
    array (
      'regex' => '(Netscape6)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Netscape',
    ),
    20 => 
    array (
      'regex' => '(MyIBrow)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'My Internet Browser',
    ),
    21 => 
    array (
      'regex' => '(Opera Tablet).*Version/(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
    ),
    22 => 
    array (
      'regex' => '(Opera)/.+Opera Mobi.+Version/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ),
    23 => 
    array (
      'regex' => '(Opera)/(\\d+)\\.(\\d+).+Opera Mobi',
      'family_replacement' => 'Opera Mobile',
    ),
    24 => 
    array (
      'regex' => 'Opera Mobi.+(Opera)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ),
    25 => 
    array (
      'regex' => 'Opera Mobi',
      'family_replacement' => 'Opera Mobile',
    ),
    26 => 
    array (
      'regex' => '(Opera Mini)(?:/att)?/(\\d+)\\.(\\d+)',
    ),
    27 => 
    array (
      'regex' => '(Opera)/9.80.*Version/(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
    ),
    28 => 
    array (
      'regex' => '(?:Mobile Safari).*(OPR)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera Mobile',
    ),
    29 => 
    array (
      'regex' => '(?:Chrome).*(OPR)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Opera',
    ),
    30 => 
    array (
      'regex' => '(hpw|web)OS/(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
      'family_replacement' => 'webOS Browser',
    ),
    31 => 
    array (
      'regex' => '(luakit)',
      'family_replacement' => 'LuaKit',
    ),
    32 => 
    array (
      'regex' => '(Snowshoe)/(\\d+)\\.(\\d+).(\\d+)',
    ),
    33 => 
    array (
      'regex' => '(Lightning)/(\\d+)\\.(\\d+)\\.?((?:[ab]?\\d+[a-z]*)|(?:\\d*))',
    ),
    34 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)\\.(\\d+(?:pre)?) \\(Swiftfox\\)',
      'family_replacement' => 'Swiftfox',
    ),
    35 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)([ab]\\d+[a-z]*)? \\(Swiftfox\\)',
      'family_replacement' => 'Swiftfox',
    ),
    36 => 
    array (
      'regex' => '(rekonq)/(\\d+)\\.(\\d+)\\.?(\\d+)? Safari',
      'family_replacement' => 'Rekonq',
    ),
    37 => 
    array (
      'regex' => 'rekonq',
      'family_replacement' => 'Rekonq',
    ),
    38 => 
    array (
      'regex' => '(conkeror|Conkeror)/(\\d+)\\.(\\d+)\\.?(\\d+)?',
      'family_replacement' => 'Conkeror',
    ),
    39 => 
    array (
      'regex' => '(konqueror)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Konqueror',
    ),
    40 => 
    array (
      'regex' => '(WeTab)-Browser',
    ),
    41 => 
    array (
      'regex' => '(Comodo_Dragon)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Comodo Dragon',
    ),
    42 => 
    array (
      'regex' => '(YottaaMonitor|BrowserMob|HttpMonitor|YandexBot|Slurp|BingPreview|PagePeeker|ThumbShotsBot|WebThumb|URL2PNG|ZooShot|GomezA|Catchpoint bot|Willow Internet Crawler|Google SketchUp|Read%20Later)',
    ),
    43 => 
    array (
      'regex' => '(Symphony) (\\d+).(\\d+)',
    ),
    44 => 
    array (
      'regex' => '(Minimo)',
    ),
    45 => 
    array (
      'regex' => '(CrMo)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile',
    ),
    46 => 
    array (
      'regex' => '(CriOS)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Mobile iOS',
    ),
    47 => 
    array (
      'regex' => '(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+) Mobile',
      'family_replacement' => 'Chrome Mobile',
    ),
    48 => 
    array (
      'regex' => '(chromeframe)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Chrome Frame',
    ),
    49 => 
    array (
      'regex' => '(UCBrowser)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'UC Browser',
    ),
    50 => 
    array (
      'regex' => '(UC Browser)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    51 => 
    array (
      'regex' => '(UC Browser|UCBrowser|UCWEB)(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'UC Browser',
    ),
    52 => 
    array (
      'regex' => '(SLP Browser)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Tizen Browser',
    ),
    53 => 
    array (
      'regex' => '(SE 2\\.X) MetaSr (\\d+)\\.(\\d+)',
      'family_replacement' => 'Sogou Explorer',
    ),
    54 => 
    array (
      'regex' => '(baidubrowser)[/\\s](\\d+)',
      'family_replacement' => 'Baidu Browser',
    ),
    55 => 
    array (
      'regex' => '(FlyFlow)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Baidu Explorer',
    ),
    56 => 
    array (
      'regex' => '(MQQBrowser/Mini)(?:(\\d+)(?:\\.(\\d+)(?:\\.(\\d+))?)?)?',
      'family_replacement' => 'QQ Browser Mini',
    ),
    57 => 
    array (
      'regex' => '(MQQBrowser)(?:/(\\d+)(?:\\.(\\d+)(?:\\.(\\d+))?)?)?',
      'family_replacement' => 'QQ Browser Mobile',
    ),
    58 => 
    array (
      'regex' => '(QQBrowser)(?:/(\\d+)(?:\\.(\\d+)\\.(\\d+)(?:\\.(\\d+))?)?)?',
      'family_replacement' => 'QQ Browser',
    ),
    59 => 
    array (
      'regex' => '(Pingdom.com_bot_version_)(\\d+)\\.(\\d+)',
      'family_replacement' => 'PingdomBot',
    ),
    60 => 
    array (
      'regex' => '(facebookexternalhit)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'FacebookBot',
    ),
    61 => 
    array (
      'regex' => '(LinkedInBot)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'LinkedInBot',
    ),
    62 => 
    array (
      'regex' => '(Twitterbot)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'TwitterBot',
    ),
    63 => 
    array (
      'regex' => 'Google.*/\\+/web/snippet',
      'family_replacement' => 'GooglePlusBot',
    ),
    64 => 
    array (
      'regex' => '(Rackspace Monitoring)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'RackspaceBot',
    ),
    65 => 
    array (
      'regex' => '(PyAMF)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    66 => 
    array (
      'regex' => '(YaBrowser)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Yandex Browser',
    ),
    67 => 
    array (
      'regex' => '(Chrome)/(\\d+)\\.(\\d+)\\.(\\d+).* MRCHROME',
      'family_replacement' => 'Mail.ru Chromium Browser',
    ),
    68 => 
    array (
      'regex' => '(AOL) (\\d+)\\.(\\d+); AOLBuild (\\d+)',
    ),
    69 => 
    array (
      'regex' => '(AdobeAIR|FireWeb|Jasmine|ANTGalio|Midori|Fresco|Lobo|PaleMoon|Maxthon|Lynx|OmniWeb|Dillo|Camino|Demeter|Fluid|Fennec|Epiphany|Shiira|Sunrise|Flock|Netscape|Lunascape|WebPilot|NetFront|Netfront|Konqueror|SeaMonkey|Kazehakase|Vienna|Iceape|Iceweasel|IceWeasel|Iron|K-Meleon|Sleipnir|Galeon|GranParadiso|Opera Mini|iCab|NetNewsWire|ThunderBrowse|Iris|UP\\.Browser|Bunjalloo|Google Earth|Raven for Mac|Openwave)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    70 => 
    array (
      'regex' => 'MSOffice 12',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2007',
    ),
    71 => 
    array (
      'regex' => 'MSOffice 14',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2010',
    ),
    72 => 
    array (
      'regex' => 'Microsoft Outlook 15\\.\\d+\\.\\d+',
      'family_replacement' => 'Outlook',
      'v1_replacement' => '2013',
    ),
    73 => 
    array (
      'regex' => '(Airmail) (\\d+)\\.(\\d+)(?:\\.(\\d+))?',
    ),
    74 => 
    array (
      'regex' => '(Thunderbird)/(\\d+)\\.(\\d+)\\.(\\d+(?:pre)?)',
      'family_replacement' => 'Thunderbird',
    ),
    75 => 
    array (
      'regex' => '(Chromium|Chrome)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    76 => 
    array (
      'regex' => '\\b(Dolphin)(?: |HDCN/|/INT\\-)(\\d+)\\.(\\d+)\\.?(\\d+)?',
    ),
    77 => 
    array (
      'regex' => '(bingbot|Bolt|Jasmine|IceCat|Skyfire|Midori|Maxthon|Lynx|Arora|IBrowse|Dillo|Camino|Shiira|Fennec|Phoenix|Chrome|Flock|Netscape|Lunascape|Epiphany|WebPilot|Opera Mini|Opera|NetFront|Netfront|Konqueror|Googlebot|SeaMonkey|Kazehakase|Vienna|Iceape|Iceweasel|IceWeasel|Iron|K-Meleon|Sleipnir|Galeon|GranParadiso|iCab|NetNewsWire|Space Bison|Stainless|Orca|Dolfin|BOLT|Minimo|Tizen Browser|Polaris|Abrowser|Planetweb|ICE Browser|mDolphin)/(\\d+)\\.(\\d+)\\.?(\\d+)?',
    ),
    78 => 
    array (
      'regex' => '(Chromium|Chrome)/(\\d+)\\.(\\d+)',
    ),
    79 => 
    array (
      'regex' => '(iRider|Crazy Browser|SkipStone|iCab|Lunascape|Sleipnir|Maemo Browser) (\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    80 => 
    array (
      'regex' => '(iCab|Lunascape|Opera|Android|Jasmine|Polaris) (\\d+)\\.(\\d+)\\.?(\\d+)?',
    ),
    81 => 
    array (
      'regex' => '(Kindle)/(\\d+)\\.(\\d+)',
    ),
    82 => 
    array (
      'regex' => '(Android) Donut',
      'v1_replacement' => '1',
      'v2_replacement' => '2',
    ),
    83 => 
    array (
      'regex' => '(Android) Eclair',
      'v1_replacement' => '2',
      'v2_replacement' => '1',
    ),
    84 => 
    array (
      'regex' => '(Android) Froyo',
      'v1_replacement' => '2',
      'v2_replacement' => '2',
    ),
    85 => 
    array (
      'regex' => '(Android) Gingerbread',
      'v1_replacement' => '2',
      'v2_replacement' => '3',
    ),
    86 => 
    array (
      'regex' => '(Android) Honeycomb',
      'v1_replacement' => '3',
    ),
    87 => 
    array (
      'regex' => '(IEMobile)[ /](\\d+)\\.(\\d+)',
      'family_replacement' => 'IE Mobile',
    ),
    88 => 
    array (
      'regex' => '(MSIE) (\\d+)\\.(\\d+).*XBLWP7',
      'family_replacement' => 'IE Large Screen',
    ),
    89 => 
    array (
      'regex' => '(Obigo)InternetBrowser',
    ),
    90 => 
    array (
      'regex' => '(Obigo)\\-Browser',
    ),
    91 => 
    array (
      'regex' => '(Obigo|OBIGO)[^\\d]*(\\d+)(?:.(\\d+))?',
      'family_replacement' => 'Obigo',
    ),
    92 => 
    array (
      'regex' => '(MAXTHON|Maxthon) (\\d+)\\.(\\d+)',
      'family_replacement' => 'Maxthon',
    ),
    93 => 
    array (
      'regex' => '(Maxthon|MyIE2|Uzbl|Shiira)',
      'v1_replacement' => '0',
    ),
    94 => 
    array (
      'regex' => 'PLAYSTATION 3.+WebKit',
      'family_replacement' => 'NetFront NX',
    ),
    95 => 
    array (
      'regex' => 'PLAYSTATION 3',
      'family_replacement' => 'NetFront',
    ),
    96 => 
    array (
      'regex' => '(PlayStation Portable)',
      'family_replacement' => 'NetFront',
    ),
    97 => 
    array (
      'regex' => '(PlayStation Vita)',
      'family_replacement' => 'NetFront NX',
    ),
    98 => 
    array (
      'regex' => 'AppleWebKit.+ (NX)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'NetFront NX',
    ),
    99 => 
    array (
      'regex' => '(Nintendo 3DS)',
      'family_replacement' => 'NetFront NX',
    ),
    100 => 
    array (
      'regex' => '(BrowseX) \\((\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    101 => 
    array (
      'regex' => '(NCSA_Mosaic)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'NCSA Mosaic',
    ),
    102 => 
    array (
      'regex' => '(POLARIS)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Polaris',
    ),
    103 => 
    array (
      'regex' => '(Embider)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Polaris',
    ),
    104 => 
    array (
      'regex' => '(BonEcho)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Bon Echo',
    ),
    105 => 
    array (
      'regex' => '(iPod).+Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    106 => 
    array (
      'regex' => '(iPod).*Version/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    107 => 
    array (
      'regex' => '(iPhone).*Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    108 => 
    array (
      'regex' => '(iPhone).*Version/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    109 => 
    array (
      'regex' => '(iPad).*Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    110 => 
    array (
      'regex' => '(iPad).*Version/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Mobile Safari',
    ),
    111 => 
    array (
      'regex' => '(iPod|iPhone|iPad);.*CPU.*OS (\\d+)(?:_\\d+)?_(\\d+).*Mobile',
      'family_replacement' => 'Mobile Safari',
    ),
    112 => 
    array (
      'regex' => '(iPod|iPhone|iPad)',
      'family_replacement' => 'Mobile Safari',
    ),
    113 => 
    array (
      'regex' => '(AvantGo) (\\d+).(\\d+)',
    ),
    114 => 
    array (
      'regex' => '(OneBrowser)/(\\d+).(\\d+)',
      'family_replacement' => 'ONE Browser',
    ),
    115 => 
    array (
      'regex' => '(Avant)',
      'v1_replacement' => '1',
    ),
    116 => 
    array (
      'regex' => '(QtCarBrowser)',
      'v1_replacement' => '1',
    ),
    117 => 
    array (
      'regex' => '^(iBrowser/Mini)(\\d+).(\\d+)',
      'family_replacement' => 'iBrowser Mini',
    ),
    118 => 
    array (
      'regex' => '^(iBrowser|iRAPP)/(\\d+).(\\d+)',
    ),
    119 => 
    array (
      'regex' => '^(Nokia)',
      'family_replacement' => 'Nokia Services (WAP) Browser',
    ),
    120 => 
    array (
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+).(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ),
    121 => 
    array (
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+).(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ),
    122 => 
    array (
      'regex' => '(NokiaBrowser)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ),
    123 => 
    array (
      'regex' => '(BrowserNG)/(\\d+)\\.(\\d+).(\\d+)',
      'family_replacement' => 'Nokia Browser',
    ),
    124 => 
    array (
      'regex' => '(Series60)/5\\.0',
      'family_replacement' => 'Nokia Browser',
      'v1_replacement' => '7',
      'v2_replacement' => '0',
    ),
    125 => 
    array (
      'regex' => '(Series60)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Nokia OSS Browser',
    ),
    126 => 
    array (
      'regex' => '(S40OviBrowser)/(\\d+)\\.(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'Ovi Browser',
    ),
    127 => 
    array (
      'regex' => '(Nokia)[EN]?(\\d+)',
    ),
    128 => 
    array (
      'regex' => '(BB10);',
      'family_replacement' => 'BlackBerry WebKit',
    ),
    129 => 
    array (
      'regex' => '(PlayBook).+RIM Tablet OS (\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'BlackBerry WebKit',
    ),
    130 => 
    array (
      'regex' => '(Black[bB]erry).+Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'BlackBerry WebKit',
    ),
    131 => 
    array (
      'regex' => '(Black[bB]erry)\\s?(\\d+)',
      'family_replacement' => 'BlackBerry',
    ),
    132 => 
    array (
      'regex' => '(OmniWeb)/v(\\d+)\\.(\\d+)',
    ),
    133 => 
    array (
      'regex' => '(Blazer)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Palm Blazer',
    ),
    134 => 
    array (
      'regex' => '(Pre)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Palm Pre',
    ),
    135 => 
    array (
      'regex' => '(ELinks)/(\\d+)\\.(\\d+)',
    ),
    136 => 
    array (
      'regex' => '(ELinks) \\((\\d+)\\.(\\d+)',
    ),
    137 => 
    array (
      'regex' => '(Links) \\((\\d+)\\.(\\d+)',
    ),
    138 => 
    array (
      'regex' => '(QtWeb) Internet Browser/(\\d+)\\.(\\d+)',
    ),
    139 => 
    array (
      'regex' => '(Silk)/(\\d+)\\.(\\d+)(?:\\.([0-9\\-]+))?',
      'family_replacement' => 'Amazon Silk',
    ),
    140 => 
    array (
      'regex' => '(PhantomJS)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    141 => 
    array (
      'regex' => '(AppleWebKit)/(\\d+)\\.?(\\d+)?\\+ .* Safari',
      'family_replacement' => 'WebKit Nightly',
    ),
    142 => 
    array (
      'regex' => '(Version)/(\\d+)\\.(\\d+)(?:\\.(\\d+))?.*Safari/',
      'family_replacement' => 'Safari',
    ),
    143 => 
    array (
      'regex' => '(Safari)/\\d+',
    ),
    144 => 
    array (
      'regex' => '(OLPC)/Update(\\d+)\\.(\\d+)',
    ),
    145 => 
    array (
      'regex' => '(OLPC)/Update()\\.(\\d+)',
      'v1_replacement' => '0',
    ),
    146 => 
    array (
      'regex' => '(SEMC\\-Browser)/(\\d+)\\.(\\d+)',
    ),
    147 => 
    array (
      'regex' => '(Teleca)',
      'family_replacement' => 'Teleca Browser',
    ),
    148 => 
    array (
      'regex' => '(Phantom)/V(\\d+)\\.(\\d+)',
      'family_replacement' => 'Phantom Browser',
    ),
    149 => 
    array (
      'regex' => 'Trident(.*)rv.(\\d+)\\.(\\d+)',
      'family_replacement' => 'IE',
    ),
    150 => 
    array (
      'regex' => '(AppleWebKit)/(\\d+)\\.(\\d+)\\.(\\d+)',
      'family_replacement' => 'AppleMail',
    ),
    151 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    152 => 
    array (
      'regex' => '(Firefox)/(\\d+)\\.(\\d+)(pre|[ab]\\d+[a-z]*)?',
    ),
    153 => 
    array (
      'regex' => '([MS]?IE) (\\d+)\\.(\\d+)',
      'family_replacement' => 'IE',
    ),
    154 => 
    array (
      'regex' => '(python-requests)/(\\d+)\\.(\\d+)',
      'family_replacement' => 'Python Requests',
    ),
  ),
  'os_parsers' => 
  array (
    0 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\( ;(LG)E ;NetCast 4.0',
      'os_v1_replacement' => '2013',
    ),
    1 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\( ;(LG)E ;NetCast 3.0',
      'os_v1_replacement' => '2012',
    ),
    2 => 
    array (
      'regex' => 'HbbTV/1.1.1 \\(;;;;;\\) Maple_2011',
      'os_replacement' => 'Samsung',
      'os_v1_replacement' => '2011',
    ),
    3 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(;(Samsung);SmartTV([0-9]{4});.*FXPDEUC',
      'os_v2_replacement' => 'UE40F7000',
    ),
    4 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(;(Samsung);SmartTV([0-9]{4});.*MST12DEUC',
      'os_v2_replacement' => 'UE32F4500',
    ),
    5 => 
    array (
      'regex' => 'HbbTV/1.1.1 \\(; (Philips);.*NETTV/4',
      'os_v1_replacement' => '2013',
    ),
    6 => 
    array (
      'regex' => 'HbbTV/1.1.1 \\(; (Philips);.*NETTV/3',
      'os_v1_replacement' => '2012',
    ),
    7 => 
    array (
      'regex' => 'HbbTV/1.1.1 \\(; (Philips);.*NETTV/2',
      'os_v1_replacement' => '2011',
    ),
    8 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+.*(firetv)-firefox-plugin (\\d+).(\\d+).(\\d+)',
      'os_replacement' => 'FireHbbTV',
    ),
    9 => 
    array (
      'regex' => 'HbbTV/\\d+\\.\\d+\\.\\d+ \\(.*; ?([a-zA-Z]+) ?;.*(201[1-9]).*\\)',
    ),
    10 => 
    array (
      'regex' => '(Android)[ \\-/](\\d+)\\.(\\d+)(?:[.\\-]([a-z0-9]+))?',
    ),
    11 => 
    array (
      'regex' => '(Android) Donut',
      'os_v1_replacement' => '1',
      'os_v2_replacement' => '2',
    ),
    12 => 
    array (
      'regex' => '(Android) Eclair',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '1',
    ),
    13 => 
    array (
      'regex' => '(Android) Froyo',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '2',
    ),
    14 => 
    array (
      'regex' => '(Android) Gingerbread',
      'os_v1_replacement' => '2',
      'os_v2_replacement' => '3',
    ),
    15 => 
    array (
      'regex' => '(Android) Honeycomb',
      'os_v1_replacement' => '3',
    ),
    16 => 
    array (
      'regex' => '^UCWEB.*; (Adr) (\\d+)\\.(\\d+)(?:[.\\-]([a-z0-9]+))?;',
      'os_replacement' => 'Android',
    ),
    17 => 
    array (
      'regex' => '^UCWEB.*; (iPad OS|iPh OS) (\\d+)_(\\d+)(?:_(\\d+))?;',
      'os_replacement' => 'iOS',
    ),
    18 => 
    array (
      'regex' => '^UCWEB.*; (wds) (\\d+)\\.(\\d+)(?:\\.(\\d+))?;',
      'os_replacement' => 'Windows Phone',
    ),
    19 => 
    array (
      'regex' => '^(JUC).*; ?U; ?(?:Android)?(\\d+)\\.(\\d+)(?:[\\.\\-]([a-z0-9]+))?',
      'os_replacement' => 'Android',
    ),
    20 => 
    array (
      'regex' => '(Silk-Accelerated=[a-z]{4,5})',
      'os_replacement' => 'Android',
    ),
    21 => 
    array (
      'regex' => '(XBLWP7)',
      'os_replacement' => 'Windows Phone',
    ),
    22 => 
    array (
      'regex' => '(Windows Phone) (?:OS[ /])?(\\d+)\\.(\\d+)',
    ),
    23 => 
    array (
      'regex' => '(Windows ?Mobile)',
      'os_replacement' => 'Windows Mobile',
    ),
    24 => 
    array (
      'regex' => '(Windows (?:NT 5\\.2|NT 5\\.1))',
      'os_replacement' => 'Windows XP',
    ),
    25 => 
    array (
      'regex' => '(Windows NT 6\\.1)',
      'os_replacement' => 'Windows 7',
    ),
    26 => 
    array (
      'regex' => '(Windows NT 6\\.0)',
      'os_replacement' => 'Windows Vista',
    ),
    27 => 
    array (
      'regex' => '(Win 9x 4\\.90)',
      'os_replacement' => 'Windows ME',
    ),
    28 => 
    array (
      'regex' => '(Windows 98|Windows XP|Windows ME|Windows 95|Windows CE|Windows 7|Windows NT 4\\.0|Windows Vista|Windows 2000|Windows 3.1)',
    ),
    29 => 
    array (
      'regex' => '(Windows NT 6\\.2; ARM;)',
      'os_replacement' => 'Windows RT',
    ),
    30 => 
    array (
      'regex' => '(Windows NT 6\\.2)',
      'os_replacement' => 'Windows 8',
    ),
    31 => 
    array (
      'regex' => '(Windows NT 6\\.3; ARM;)',
      'os_replacement' => 'Windows RT 8.1',
    ),
    32 => 
    array (
      'regex' => '(Windows NT 6\\.3)',
      'os_replacement' => 'Windows 8.1',
    ),
    33 => 
    array (
      'regex' => '(Windows NT 5\\.0)',
      'os_replacement' => 'Windows 2000',
    ),
    34 => 
    array (
      'regex' => '(WinNT4.0)',
      'os_replacement' => 'Windows NT 4.0',
    ),
    35 => 
    array (
      'regex' => '(Windows ?CE)',
      'os_replacement' => 'Windows CE',
    ),
    36 => 
    array (
      'regex' => 'Win ?(95|98|3.1|NT|ME|2000)',
      'os_replacement' => 'Windows $1',
    ),
    37 => 
    array (
      'regex' => 'Win16',
      'os_replacement' => 'Windows 3.1',
    ),
    38 => 
    array (
      'regex' => 'Win32',
      'os_replacement' => 'Windows 95',
    ),
    39 => 
    array (
      'regex' => '(Tizen)/(\\d+)\\.(\\d+)',
    ),
    40 => 
    array (
      'regex' => '(Mac OS X) (\\d+)[_.](\\d+)(?:[_.](\\d+))?',
    ),
    41 => 
    array (
      'regex' => 'Mac_PowerPC',
      'os_replacement' => 'Mac OS',
    ),
    42 => 
    array (
      'regex' => '(?:PPC|Intel) (Mac OS X)',
    ),
    43 => 
    array (
      'regex' => '(CPU OS|iPhone OS|CPU iPhone) (\\d+)[_\\.](\\d+)(?:[_\\.](\\d+))?',
      'os_replacement' => 'iOS',
    ),
    44 => 
    array (
      'regex' => '(iPhone|iPad|iPod); Opera',
      'os_replacement' => 'iOS',
    ),
    45 => 
    array (
      'regex' => '(iPhone|iPad|iPod).*Mac OS X.*Version/(\\d+)\\.(\\d+)',
      'os_replacement' => 'iOS',
    ),
    46 => 
    array (
      'regex' => '(AppleTV)/(\\d+)\\.(\\d+)',
      'os_replacement' => 'ATV OS X',
    ),
    47 => 
    array (
      'regex' => '(CrOS) [a-z0-9_]+ (\\d+)\\.(\\d+)(?:\\.(\\d+))?',
      'os_replacement' => 'Chrome OS',
    ),
    48 => 
    array (
      'regex' => '([Dd]ebian)',
      'os_replacement' => 'Debian',
    ),
    49 => 
    array (
      'regex' => '(Linux Mint)(?:/(\\d+))?',
    ),
    50 => 
    array (
      'regex' => '(Mandriva)(?: Linux)?/(?:[\\d.-]+m[a-z]{2}(\\d+).(\\d))?',
    ),
    51 => 
    array (
      'regex' => '(Symbian[Oo][Ss])[/ ](\\d+)\\.(\\d+)',
      'os_replacement' => 'Symbian OS',
    ),
    52 => 
    array (
      'regex' => '(Symbian/3).+NokiaBrowser/7\\.3',
      'os_replacement' => 'Symbian^3 Anna',
    ),
    53 => 
    array (
      'regex' => '(Symbian/3).+NokiaBrowser/7\\.4',
      'os_replacement' => 'Symbian^3 Belle',
    ),
    54 => 
    array (
      'regex' => '(Symbian/3)',
      'os_replacement' => 'Symbian^3',
    ),
    55 => 
    array (
      'regex' => '\\b(Series 60|SymbOS|S60Version|S60V\\d|S60\\b)',
      'os_replacement' => 'Symbian OS',
    ),
    56 => 
    array (
      'regex' => '(MeeGo)',
    ),
    57 => 
    array (
      'regex' => 'Symbian [Oo][Ss]',
      'os_replacement' => 'Symbian OS',
    ),
    58 => 
    array (
      'regex' => 'Series40;',
      'os_replacement' => 'Nokia Series 40',
    ),
    59 => 
    array (
      'regex' => 'Series30Plus;',
      'os_replacement' => 'Nokia Series 30 Plus',
    ),
    60 => 
    array (
      'regex' => '(BB10);.+Version/(\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'BlackBerry OS',
    ),
    61 => 
    array (
      'regex' => '(Black[Bb]erry)[0-9a-z]+/(\\d+)\\.(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
      'os_replacement' => 'BlackBerry OS',
    ),
    62 => 
    array (
      'regex' => '(Black[Bb]erry).+Version/(\\d+)\\.(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
      'os_replacement' => 'BlackBerry OS',
    ),
    63 => 
    array (
      'regex' => '(RIM Tablet OS) (\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'BlackBerry Tablet OS',
    ),
    64 => 
    array (
      'regex' => '(Play[Bb]ook)',
      'os_replacement' => 'BlackBerry Tablet OS',
    ),
    65 => 
    array (
      'regex' => '(Black[Bb]erry)',
      'os_replacement' => 'BlackBerry OS',
    ),
    66 => 
    array (
      'regex' => '\\((?:Mobile|Tablet);.+Firefox/\\d+\\.\\d+',
      'os_replacement' => 'Firefox OS',
    ),
    67 => 
    array (
      'regex' => '(BREW)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
    ),
    68 => 
    array (
      'regex' => '(BREW);',
    ),
    69 => 
    array (
      'regex' => '(Brew MP|BMP)[ /](\\d+)\\.(\\d+)\\.(\\d+)',
      'os_replacement' => 'Brew MP',
    ),
    70 => 
    array (
      'regex' => 'BMP;',
      'os_replacement' => 'Brew MP',
    ),
    71 => 
    array (
      'regex' => '(GoogleTV)(?: (\\d+)\\.(\\d+)(?:\\.(\\d+))?|/[\\da-z]+)',
    ),
    72 => 
    array (
      'regex' => '(WebTV)/(\\d+).(\\d+)',
    ),
    73 => 
    array (
      'regex' => '(hpw|web)OS/(\\d+)\\.(\\d+)(?:\\.(\\d+))?',
      'os_replacement' => 'webOS',
    ),
    74 => 
    array (
      'regex' => '(VRE);',
    ),
    75 => 
    array (
      'regex' => '(Fedora|Red Hat|PCLinuxOS|Puppy|Ubuntu|Kindle|Bada|Lubuntu|BackTrack|Slackware|(?:Free|Open|Net|\\b)BSD)[/ ](\\d+)\\.(\\d+)(?:\\.(\\d+)(?:\\.(\\d+))?)?',
    ),
    76 => 
    array (
      'regex' => '(Linux)[ /](\\d+)\\.(\\d+)(?:\\.(\\d+))?.*gentoo',
      'os_replacement' => 'Gentoo',
    ),
    77 => 
    array (
      'regex' => '\\((Bada);',
    ),
    78 => 
    array (
      'regex' => '(Windows|Android|WeTab|Maemo)',
    ),
    79 => 
    array (
      'regex' => '(Ubuntu|Kubuntu|Arch Linux|CentOS|Slackware|Gentoo|openSUSE|SUSE|Red Hat|Fedora|PCLinuxOS|Gentoo|Mageia|(?:Free|Open|Net|\\b)BSD)',
    ),
    80 => 
    array (
      'regex' => '(Linux)(?:[ /](\\d+)\\.(\\d+)(?:\\.(\\d+))?)?',
    ),
    81 => 
    array (
      'regex' => 'SunOS',
      'os_replacement' => 'Solaris',
    ),
  ),
  'device_parsers' => 
  array (
    0 => 
    array (
      'regex' => 'HTC ([A-Z][a-z0-9]+) Build',
      'device_replacement' => 'HTC $1',
    ),
    1 => 
    array (
      'regex' => 'HTC ([A-Z][a-z0-9 ]+) \\d+\\.\\d+\\.\\d+\\.\\d+',
      'device_replacement' => 'HTC $1',
    ),
    2 => 
    array (
      'regex' => 'HTC_Touch_([A-Za-z0-9]+)',
      'device_replacement' => 'HTC Touch ($1)',
    ),
    3 => 
    array (
      'regex' => 'USCCHTC(\\d+)',
      'device_replacement' => 'HTC $1 (US Cellular)',
    ),
    4 => 
    array (
      'regex' => 'Sprint APA(9292)',
      'device_replacement' => 'HTC $1 (Sprint)',
    ),
    5 => 
    array (
      'regex' => 'HTC ([A-Za-z0-9]+ [A-Z])',
      'device_replacement' => 'HTC $1',
    ),
    6 => 
    array (
      'regex' => 'HTC[-_/\\s]([A-Za-z0-9]+)',
      'device_replacement' => 'HTC $1',
    ),
    7 => 
    array (
      'regex' => '(ADR[A-Za-z0-9]+)',
      'device_replacement' => 'HTC $1',
    ),
    8 => 
    array (
      'regex' => '(HTC)',
    ),
    9 => 
    array (
      'regex' => '(QtCarBrowser)',
      'device_replacement' => 'Tesla Model S',
    ),
    10 => 
    array (
      'regex' => '(SamsungSGHi560)',
      'device_replacement' => 'Samsung SGHi560',
    ),
    11 => 
    array (
      'regex' => 'SonyEricsson([A-Za-z0-9]+)/',
      'device_replacement' => 'Ericsson $1',
    ),
    12 => 
    array (
      'regex' => 'PLAYSTATION 3',
      'device_replacement' => 'PlayStation 3',
    ),
    13 => 
    array (
      'regex' => '(PlayStation Portable)',
    ),
    14 => 
    array (
      'regex' => '(PlayStation Vita)',
    ),
    15 => 
    array (
      'regex' => '(KFOT Build)',
      'device_replacement' => 'Kindle Fire',
    ),
    16 => 
    array (
      'regex' => '(KFTT Build)',
      'device_replacement' => 'Kindle Fire HD',
    ),
    17 => 
    array (
      'regex' => '(KFJWI Build)',
      'device_replacement' => 'Kindle Fire HD 8.9" WiFi',
    ),
    18 => 
    array (
      'regex' => '(KFJWA Build)',
      'device_replacement' => 'Kindle Fire HD 8.9" 4G',
    ),
    19 => 
    array (
      'regex' => '(KFSOWI Build)',
      'device_replacement' => 'Kindle Fire HD 7" WiFi',
    ),
    20 => 
    array (
      'regex' => '(KFTHWI Build)',
      'device_replacement' => 'Kindle Fire HDX 7" WiFi',
    ),
    21 => 
    array (
      'regex' => '(KFTHWA Build)',
      'device_replacement' => 'Kindle Fire HDX 7" 4G',
    ),
    22 => 
    array (
      'regex' => '(KFAPWI Build)',
      'device_replacement' => 'Kindle Fire HDX 8.9" WiFi',
    ),
    23 => 
    array (
      'regex' => '(KFAPWA Build)',
      'device_replacement' => 'Kindle Fire HDX 8.9" 4G',
    ),
    24 => 
    array (
      'regex' => '(Kindle Fire)',
    ),
    25 => 
    array (
      'regex' => '(Kindle)',
    ),
    26 => 
    array (
      'regex' => '(Silk)/(\\d+)\\.(\\d+)(?:\\.([0-9\\-]+))?',
      'device_replacement' => 'Kindle Fire',
    ),
    27 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+; [A-Za-z]{2}\\-[A-Za-z]{0,2}; WOWMobile (.+) Build',
    ),
    28 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+\\-update1; [A-Za-z]{2}\\-[A-Za-z]{0,2}; (.+) Build',
    ),
    29 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+\\.[\\d]+; [A-Za-z]{2}\\-[A-Za-z]{0,2}; (.+) Build',
    ),
    30 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+\\.[\\d]+;[A-Za-z]{2}\\-[A-Za-z]{0,2};(.+) Build',
    ),
    31 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+; [A-Za-z]{2}\\-[A-Za-z]{0,2}; (.+) Build',
    ),
    32 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+\\.[\\d]+; (.+) Build',
    ),
    33 => 
    array (
      'regex' => 'Android[\\- ][\\d]+\\.[\\d]+; (.+) Build',
    ),
    34 => 
    array (
      'regex' => 'NokiaN([0-9]+)',
      'device_replacement' => 'Nokia N$1',
    ),
    35 => 
    array (
      'regex' => 'NOKIA([A-Za-z0-9\\v-]+)',
      'device_replacement' => 'Nokia $1',
    ),
    36 => 
    array (
      'regex' => 'Nokia([A-Za-z0-9\\v-]+)',
      'device_replacement' => 'Nokia $1',
    ),
    37 => 
    array (
      'regex' => 'NOKIA ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Nokia $1',
    ),
    38 => 
    array (
      'regex' => 'Nokia ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Nokia $1',
    ),
    39 => 
    array (
      'regex' => 'Lumia ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Lumia $1',
    ),
    40 => 
    array (
      'regex' => 'Symbian',
      'device_replacement' => 'Nokia',
    ),
    41 => 
    array (
      'regex' => 'BB10; ([A-Za-z0-9\\- ]+)\\)',
      'device_replacement' => 'BlackBerry $1',
    ),
    42 => 
    array (
      'regex' => '(PlayBook).+RIM Tablet OS',
      'device_replacement' => 'BlackBerry Playbook',
    ),
    43 => 
    array (
      'regex' => 'Black[Bb]erry ([0-9]+);',
      'device_replacement' => 'BlackBerry $1',
    ),
    44 => 
    array (
      'regex' => 'Black[Bb]erry([0-9]+)',
      'device_replacement' => 'BlackBerry $1',
    ),
    45 => 
    array (
      'regex' => 'Black[Bb]erry;',
      'device_replacement' => 'BlackBerry',
    ),
    46 => 
    array (
      'regex' => '(Pre)/(\\d+)\\.(\\d+)',
      'device_replacement' => 'Palm Pre',
    ),
    47 => 
    array (
      'regex' => '(Pixi)/(\\d+)\\.(\\d+)',
      'device_replacement' => 'Palm Pixi',
    ),
    48 => 
    array (
      'regex' => '(Touch[Pp]ad)/(\\d+)\\.(\\d+)',
      'device_replacement' => 'HP TouchPad',
    ),
    49 => 
    array (
      'regex' => 'HPiPAQ([A-Za-z0-9]+)/(\\d+).(\\d+)',
      'device_replacement' => 'HP iPAQ $1',
    ),
    50 => 
    array (
      'regex' => 'Palm([A-Za-z0-9]+)',
      'device_replacement' => 'Palm $1',
    ),
    51 => 
    array (
      'regex' => 'Treo([A-Za-z0-9]+)',
      'device_replacement' => 'Palm Treo $1',
    ),
    52 => 
    array (
      'regex' => 'webOS.*(P160UNA)/(\\d+).(\\d+)',
      'device_replacement' => 'HP Veer',
    ),
    53 => 
    array (
      'regex' => '(AppleTV)',
      'device_replacement' => 'AppleTV',
    ),
    54 => 
    array (
      'regex' => 'AdsBot-Google-Mobile',
      'device_replacement' => 'Bot',
    ),
    55 => 
    array (
      'regex' => 'Googlebot-Mobile/(\\d+).(\\d+)',
      'device_replacement' => 'Bot',
    ),
    56 => 
    array (
      'regex' => '(iPad) Simulator;',
    ),
    57 => 
    array (
      'regex' => '(iPad);',
    ),
    58 => 
    array (
      'regex' => '(iPod) touch;',
    ),
    59 => 
    array (
      'regex' => '(iPod);',
    ),
    60 => 
    array (
      'regex' => '(iPhone) Simulator;',
    ),
    61 => 
    array (
      'regex' => '(iPhone);',
    ),
    62 => 
    array (
      'regex' => 'acer_([A-Za-z0-9]+)_',
      'device_replacement' => 'Acer $1',
    ),
    63 => 
    array (
      'regex' => 'acer_([A-Za-z0-9]+)_',
      'device_replacement' => 'Acer $1',
    ),
    64 => 
    array (
      'regex' => 'ALCATEL-([A-Za-z0-9]+)',
      'device_replacement' => 'Alcatel $1',
    ),
    65 => 
    array (
      'regex' => 'Alcatel-([A-Za-z0-9]+)',
      'device_replacement' => 'Alcatel $1',
    ),
    66 => 
    array (
      'regex' => 'Amoi\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Amoi $1',
    ),
    67 => 
    array (
      'regex' => 'AMOI\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Amoi $1',
    ),
    68 => 
    array (
      'regex' => 'Asus\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Asus $1',
    ),
    69 => 
    array (
      'regex' => 'ASUS\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Asus $1',
    ),
    70 => 
    array (
      'regex' => 'BIRD\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Bird $1',
    ),
    71 => 
    array (
      'regex' => 'BIRD\\.([A-Za-z0-9]+)',
      'device_replacement' => 'Bird $1',
    ),
    72 => 
    array (
      'regex' => 'BIRD ([A-Za-z0-9]+)',
      'device_replacement' => 'Bird $1',
    ),
    73 => 
    array (
      'regex' => 'Dell ([A-Za-z0-9]+)',
      'device_replacement' => 'Dell $1',
    ),
    74 => 
    array (
      'regex' => 'DoCoMo/2\\.0 ([A-Za-z0-9]+)',
      'device_replacement' => 'DoCoMo $1',
    ),
    75 => 
    array (
      'regex' => '([A-Za-z0-9]+)_W\\;FOMA',
      'device_replacement' => 'DoCoMo $1',
    ),
    76 => 
    array (
      'regex' => '([A-Za-z0-9]+)\\;FOMA',
      'device_replacement' => 'DoCoMo $1',
    ),
    77 => 
    array (
      'regex' => 'Huawei([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei $1',
    ),
    78 => 
    array (
      'regex' => 'HUAWEI-([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei $1',
    ),
    79 => 
    array (
      'regex' => 'vodafone([A-Za-z0-9]+)',
      'device_replacement' => 'Huawei Vodafone $1',
    ),
    80 => 
    array (
      'regex' => 'i\\-mate ([A-Za-z0-9]+)',
      'device_replacement' => 'i-mate $1',
    ),
    81 => 
    array (
      'regex' => 'Kyocera\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Kyocera $1',
    ),
    82 => 
    array (
      'regex' => 'KWC\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Kyocera $1',
    ),
    83 => 
    array (
      'regex' => 'Lenovo\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Lenovo $1',
    ),
    84 => 
    array (
      'regex' => 'Lenovo_([A-Za-z0-9]+)',
      'device_replacement' => 'Lenovo $1',
    ),
    85 => 
    array (
      'regex' => '(HbbTV)/[0-9]+\\.[0-9]+\\.[0-9]+',
    ),
    86 => 
    array (
      'regex' => 'LG/([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    87 => 
    array (
      'regex' => 'LG-LG([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    88 => 
    array (
      'regex' => 'LGE-LG([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    89 => 
    array (
      'regex' => 'LGE VX([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    90 => 
    array (
      'regex' => 'LG ([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    91 => 
    array (
      'regex' => 'LGE LG\\-AX([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    92 => 
    array (
      'regex' => 'LG\\-([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    93 => 
    array (
      'regex' => 'LGE\\-([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    94 => 
    array (
      'regex' => 'LG([A-Za-z0-9]+)',
      'device_replacement' => 'LG $1',
    ),
    95 => 
    array (
      'regex' => '(KIN)\\.One (\\d+)\\.(\\d+)',
      'device_replacement' => 'Microsoft $1',
    ),
    96 => 
    array (
      'regex' => '(KIN)\\.Two (\\d+)\\.(\\d+)',
      'device_replacement' => 'Microsoft $1',
    ),
    97 => 
    array (
      'regex' => '(Motorola)\\-([A-Za-z0-9]+)',
    ),
    98 => 
    array (
      'regex' => 'MOTO\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Motorola $1',
    ),
    99 => 
    array (
      'regex' => 'MOT\\-([A-Za-z0-9]+)',
      'device_replacement' => 'Motorola $1',
    ),
    100 => 
    array (
      'regex' => '(Nintendo WiiU)',
      'device_replacement' => 'Nintendo Wii U',
    ),
    101 => 
    array (
      'regex' => 'Nintendo (DS|3DS|DSi|Wii);',
      'device_replacement' => 'Nintendo $1',
    ),
    102 => 
    array (
      'regex' => 'Pantech([A-Za-z0-9]+)',
      'device_replacement' => 'Pantech $1',
    ),
    103 => 
    array (
      'regex' => 'Philips([A-Za-z0-9]+)',
      'device_replacement' => 'Philips $1',
    ),
    104 => 
    array (
      'regex' => 'Philips ([A-Za-z0-9]+)',
      'device_replacement' => 'Philips $1',
    ),
    105 => 
    array (
      'regex' => 'SAMSUNG-([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Samsung $1',
    ),
    106 => 
    array (
      'regex' => 'SAMSUNG\\; ([A-Za-z0-9\\-]+)',
      'device_replacement' => 'Samsung $1',
    ),
    107 => 
    array (
      'regex' => 'Dreamcast',
      'device_replacement' => 'Sega Dreamcast',
    ),
    108 => 
    array (
      'regex' => 'Softbank/1\\.0/([A-Za-z0-9]+)',
      'device_replacement' => 'Softbank $1',
    ),
    109 => 
    array (
      'regex' => 'Softbank/2\\.0/([A-Za-z0-9]+)',
      'device_replacement' => 'Softbank $1',
    ),
    110 => 
    array (
      'regex' => '(WebTV)/(\\d+).(\\d+)',
    ),
    111 => 
    array (
      'regex' => '(hiptop|avantgo|plucker|xiino|blazer|elaine|up.browser|up.link|mmp|smartphone|midp|wap|vodafone|o2|pocket|mobile|pda)',
      'device_replacement' => 'Generic Smartphone',
    ),
    112 => 
    array (
      'regex' => '^(1207|3gso|4thp|501i|502i|503i|504i|505i|506i|6310|6590|770s|802s|a wa|acer|acs\\-|airn|alav|asus|attw|au\\-m|aur |aus |abac|acoo|aiko|alco|alca|amoi|anex|anny|anyw|aptu|arch|argo|bell|bird|bw\\-n|bw\\-u|beck|benq|bilb|blac|c55/|cdm\\-|chtm|capi|comp|cond|craw|dall|dbte|dc\\-s|dica|ds\\-d|ds12|dait|devi|dmob|doco|dopo|el49|erk0|esl8|ez40|ez60|ez70|ezos|ezze|elai|emul|eric|ezwa|fake|fly\\-|fly_|g\\-mo|g1 u|g560|gf\\-5|grun|gene|go.w|good|grad|hcit|hd\\-m|hd\\-p|hd\\-t|hei\\-|hp i|hpip|hs\\-c|htc |htc\\-|htca|htcg)',
      'device_replacement' => 'Generic Feature Phone',
    ),
    113 => 
    array (
      'regex' => '^(htcp|htcs|htct|htc_|haie|hita|huaw|hutc|i\\-20|i\\-go|i\\-ma|i230|iac|iac\\-|iac/|ig01|im1k|inno|iris|jata|java|kddi|kgt|kgt/|kpt |kwc\\-|klon|lexi|lg g|lg\\-a|lg\\-b|lg\\-c|lg\\-d|lg\\-f|lg\\-g|lg\\-k|lg\\-l|lg\\-m|lg\\-o|lg\\-p|lg\\-s|lg\\-t|lg\\-u|lg\\-w|lg/k|lg/l|lg/u|lg50|lg54|lge\\-|lge/|lynx|leno|m1\\-w|m3ga|m50/|maui|mc01|mc21|mcca|medi|meri|mio8|mioa|mo01|mo02|mode|modo|mot |mot\\-|mt50|mtp1|mtv |mate|maxo|merc|mits|mobi|motv|mozz|n100|n101|n102|n202|n203|n300|n302|n500|n502|n505|n700|n701|n710|nec\\-|nem\\-|newg|neon)',
      'device_replacement' => 'Generic Feature Phone',
    ),
    114 => 
    array (
      'regex' => '^(netf|noki|nzph|o2 x|o2\\-x|opwv|owg1|opti|oran|ot\\-s|p800|pand|pg\\-1|pg\\-2|pg\\-3|pg\\-6|pg\\-8|pg\\-c|pg13|phil|pn\\-2|pt\\-g|palm|pana|pire|pock|pose|psio|qa\\-a|qc\\-2|qc\\-3|qc\\-5|qc\\-7|qc07|qc12|qc21|qc32|qc60|qci\\-|qwap|qtek|r380|r600|raks|rim9|rove|s55/|sage|sams|sc01|sch\\-|scp\\-|sdk/|se47|sec\\-|sec0|sec1|semc|sgh\\-|shar|sie\\-|sk\\-0|sl45|slid|smb3|smt5|sp01|sph\\-|spv |spv\\-|sy01|samm|sany|sava|scoo|send|siem|smar|smit|soft|sony|t\\-mo|t218|t250|t600|t610|t618|tcl\\-|tdg\\-|telm|tim\\-|ts70|tsm\\-|tsm3|tsm5|tx\\-9|tagt)',
      'device_replacement' => 'Generic Feature Phone',
    ),
    115 => 
    array (
      'regex' => '^(talk|teli|topl|tosh|up.b|upg1|utst|v400|v750|veri|vk\\-v|vk40|vk50|vk52|vk53|vm40|vx98|virg|vite|voda|vulc|w3c |w3c\\-|wapj|wapp|wapu|wapm|wig |wapi|wapr|wapv|wapy|wapa|waps|wapt|winc|winw|wonu|x700|xda2|xdag|yas\\-|your|zte\\-|zeto|aste|audi|avan|blaz|brew|brvw|bumb|ccwa|cell|cldc|cmd\\-|dang|eml2|fetc|hipt|http|ibro|idea|ikom|ipaq|jbro|jemu|jigs|keji|kyoc|kyok|libw|m\\-cr|midp|mmef|moto|mwbp|mywa|newt|nok6|o2im|pant|pdxg|play|pluc|port|prox|rozo|sama|seri|smal|symb|treo|upsi|vx52|vx53|vx60|vx61|vx70|vx80|vx81|vx83|vx85|wap\\-|webc|whit|wmlb|xda\\-|xda_)',
      'device_replacement' => 'Generic Feature Phone',
    ),
    116 => 
    array (
      'regex' => '(facebookexternalhit|bingbot|bot|borg|google(^tv)|yahoo|slurp|msnbot|msrbot|openbot|archiver|netresearch|lycos|scooter|altavista|teoma|gigabot|baiduspider|blitzbot|oegp|charlotte|furlbot|http%20client|polybot|htdig|ichiro|mogimogi|larbin|pompos|scrubby|searchsight|seekbot|semanticdiscovery|silk|snappy|speedy|spider|voila|vortex|voyager|zao|zeal|fast\\-webcrawler|converacrawler|dataparksearch|findlinks|crawler)',
      'device_replacement' => 'Bot',
    ),
  ),
);