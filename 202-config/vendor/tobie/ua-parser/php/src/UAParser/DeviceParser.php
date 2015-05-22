<?php
/**
 * ua-parser
 *
 * Copyright (c) 2011-2013 Dave Olsen, http://dmolsen.com
 * Copyright (c) 2013-2014 Lars Strojny, http://usrportage.de
 *
 * Released under the MIT license
 */
namespace UAParser;

use UAParser\Result\Device;

class DeviceParser extends AbstractParser
{
    /**
     * Attempts to see if the user agent matches a device regex from regexes.php
     *
     * @param string $userAgent a user agent string to test
     * @return Device
     */
    public function parseDevice($userAgent)
    {
        $device = new Device();

        list($regex, $matches) = $this->tryMatch($this->regexes['device_parsers'], $userAgent);

        if ($matches) {
            $device->family = trim($this->replaceString2($regex, 'device_replacement', $matches));
        }
        return $device;
    }
}
