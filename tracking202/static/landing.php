<? header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
$strProtocol = $_SERVER['HTTPS'] == 'on' ? 'https' : 'http'; ?>
    function t202Init() {
        //this grabs the t202kw, but if they set a forced kw, this will be replaced 

        if (readCookie('t202forcedkw')) {
            var t202kw = readCookie('t202forcedkw');
        } else {
            var t202kw = t202GetVar('t202kw');
        }

        var lpip = '<? echo htmlentities($_GET['
        lpip ']); ?>';
        var t202id = t202GetVar('t202id');
        var OVRAW = t202GetVar('OVRAW');
        var OVKEY = t202GetVar('OVKEY');
        var OVMTC = t202GetVar('OVMTC');
        var c1 = t202GetVar('c1');
        var c2 = t202GetVar('c2');
        var c3 = t202GetVar('c3');
        var c4 = t202GetVar('c4');
        var c5 = t202GetVar('c5');
        var c6 = t202GetVar('c6');
        var c7 = t202GetVar('c7');
        var c8 = t202GetVar('c8');
        var c9 = t202GetVar('c9');
        var c10 = t202GetVar('c10');
        var c11 = t202GetVar('c11');
        var c12 = t202GetVar('c12');
        var c13 = t202GetVar('c13');
        var c14 = t202GetVar('c14');
        var c15 = t202GetVar('c15');

        /* mv vars are not always present in tracking link so we need to make sure they are not undefined */
        
        if (typeof mv1 == 'undefined') {
            mv1 = "";
        }
        if (typeof mv2 == 'undefined') {
            mv2 = "";
        }
        if (typeof mv3 == 'undefined') {
            mv3 = "";
        }
        if (typeof mv4 == 'undefined') {
            mv4 = "";
        }
        if (typeof mv5 == 'undefined') {
            mv5 = "";
        }
        if (typeof mv6 == 'undefined') {
            mv6 = "";
        }
        if (typeof mv7 == 'undefined') {
            mv7 = "";
        }
        if (typeof mv8 == 'undefined') {
            mv8 = "";
        }
        if (typeof mv9 == 'undefined') {
            mv9 = "";
        }
        if (typeof mv10 == 'undefined') {
            mv10 = "";
        }
        if (typeof mv11 == 'undefined') {
            mv11 = "";
        }
        if (typeof mv12 == 'undefined') {
            mv12 = "";
        }
        if (typeof mv13 == 'undefined') {
            mv13 = "";
        }
        if (typeof mv14 == 'undefined') {
            mv14 = "";
        }
        if (typeof mv15 == 'undefined') {
            mv15 = "";
        }

        var target_passthrough = t202GetVar('target_passthrough');
        var keyword = t202GetVar('keyword');
        var referer = document.referrer;
        var resolution = screen.width + 'x' + screen.height;
        var language = navigator.appName == 'Netscape' ? navigator.language : navigator.browserLanguage;
        language = language.substr(0, 2);

    document.write("<script src=\"<?php echo $strProtocol; ?>://<? echo $_SERVER['SERVER_NAME']; ?>/tracking202/static/record.php?lpip=" + t202Enc(lpip)
        + "&t202id="                + t202Enc(t202id)
        + "&t202kw="                + t202kw
        + "&OVRAW="                 + t202Enc(OVRAW)
        + "&OVKEY="                 + t202Enc(OVKEY)
        + "&OVMTC="                 + t202Enc(OVMTC)
        + "&c1="                    + t202Enc(c1)
        + "&c2="                    + t202Enc(c2)
        + "&c3="                    + t202Enc(c3)
        + "&c4="                    + t202Enc(c4)
        + "&c5="                    + t202Enc(c5)
        + "&c6="                    + t202Enc(c6)
        + "&c7="                    + t202Enc(c7)
        + "&c8="                    + t202Enc(c8)  
        + "&c9="                    + t202Enc(c9)
        + "&c10="                    + t202Enc(c10)
        + "&c11="                    + t202Enc(c11)
        + "&c12="                    + t202Enc(c12)
        + "&c13="                    + t202Enc(c13)
        + "&c14="                    + t202Enc(c14)
        + "&c15="                    + t202Enc(c15)             
        + "&mv1="                    + t202Enc(mv1)
        + "&mv2="                    + t202Enc(mv2)
        + "&mv3="                    + t202Enc(mv3)
        + "&mv4="                    + t202Enc(mv4)        
        + "&mv5="                    + t202Enc(mv5)
        + "&mv6="                    + t202Enc(mv6)
        + "&mv7="                    + t202Enc(mv7)
        + "&mv8="                    + t202Enc(mv8) 
        + "&mv9="                    + t202Enc(mv9)
        + "&mv10="                    + t202Enc(mv10)
        + "&mv11="                    + t202Enc(mv11)
        + "&mv12="                    + t202Enc(mv12)        
        + "&mv13="                    + t202Enc(mv13)
        + "&mv14="                    + t202Enc(mv14)
        + "&mv15="                    + t202Enc(mv15)         
        + "&target_passthrough="    + t202Enc(target_passthrough)
        + "&keyword="               + t202Enc(keyword)
        + "&referer="               + t202Enc(referer)
        + "&resolution="            + t202Enc(resolution)
        + "&language="              + t202Enc(language)
        + "\" type=\"text/javascript\" ></script>"
    );
        
}

function t202Enc(e) {
    return encodeURIComponent(e);

}

function t202GetVar(name) {
    get_string = document.location.search;
    return_value = '';

    do {
        name_index = get_string.indexOf(name + '=');

        if (name_index != -1) {
            get_string = get_string.substr(name_index + name.length + 1, get_string.length - name_index);

            end_of_value = get_string.indexOf('&');
            if (end_of_value != -1) {
                value = get_string.substr(0, end_of_value);
            } else {
                value = get_string;
            }

            if (return_value == '' || value == '') {
                return_value += value;
            } else {
                return_value += ', ' + value;
            }
        }
    }

    while (name_index != -1)

    //Restores all the blank spaces.
    space = return_value.indexOf('+');
    while (space != -1) {
        return_value = return_value.substr(0, space) + ' ' +
            return_value.substr(space + 1, return_value.length);

        space = return_value.indexOf('+');
    }

    return (return_value);

}

function createCookie(name, value, days) {
    if (days) {
        var date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        var expires = "; expires=" + date.toGMTString();
    } else var expires = "";
    document.cookie = name + "=" + value + expires + "; path=/";

}

function readCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');
    for (var i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
    }
    return null;

}

function eraseCookie(name) {
    createCookie(name, "", -1);
}


t202Init();