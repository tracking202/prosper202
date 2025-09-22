<?php
declare(strict_types=1);
namespace GeoIp2\Record;

abstract class AbstractPlaceRecord extends AbstractRecord
{
    /**
     * @ignore
     *
     * @param mixed $record
     * @param mixed $locales
     */
    public function __construct($record, private $locales = ['en'])
    {
        parent::__construct($record);
    }

    /**
     * @ignore
     *
     * @param mixed $attr
     */
    #[\Override]
    public function __get($attr)
    {
        if ($attr === 'name') {
            return $this->name();
        }

        return parent::__get($attr);
    }

    /**
     * @ignore
     *
     * @param mixed $attr
     */
    #[\Override]
    public function __isset($attr)
    {
        if ($attr === 'name') {
            return $this->firstSetNameLocale() === null ? false : true;
        }

        return parent::__isset($attr);
    }

    private function name()
    {
        $locale = $this->firstSetNameLocale();

        return $locale === null ? null : $this->names[$locale];
    }

    private function firstSetNameLocale()
    {
        foreach ($this->locales as $locale) {
            if (isset($this->names[$locale])) {
                return $locale;
            }
        }

        return null;
    }
}
