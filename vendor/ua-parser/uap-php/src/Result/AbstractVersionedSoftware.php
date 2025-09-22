<?php
declare(strict_types=1);
/**
 * ua-parser
 *
 * Copyright (c) 2011-2013 Dave Olsen, http://dmolsen.com
 * Copyright (c) 2013-2014 Lars Strojny, http://usrportage.de
 *
 * Released under the MIT license
 */
namespace UAParser\Result;

abstract class AbstractVersionedSoftware extends AbstractSoftware
{
    /** @return string */
    abstract public function toVersion();

    /** @return string */
    #[\Override]
    public function toString()
    {
        return implode(' ', array_filter([$this->family, $this->toVersion()]));
    }

    /** @return string */
    protected function formatVersion()
    {
        return implode('.', array_filter(func_get_args(), 'is_numeric'));
    }
}
