<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Click\ClickRequest;

/**
 * What a redirector rule can ask about a visitor (CriterionMatcher), as
 * rtr.php and offrtr.php know it. Whether the click is filtered and its
 * fraud score cost a write or a network call, so each is asked only when a
 * rule needs it, and once; null is "could not tell".
 */
final class VisitorFacts
{
    /** @var array<string, ?bool> */
    private array $asked = [];

    /**
     * @param array<string, mixed> $geo getGeoData()'s answer
     * @param array<array-key, mixed> $query the request's query
     * @param \Closure(): ?bool $filteredCheck whether the click is (to be) recorded as filtered
     * @param \Closure(): ?bool $fraudCheck whether IPQualityScore scores the visitor as fraud
     */
    public function __construct(
        public readonly string $ip,
        public readonly array $geo,
        public readonly ?string $isp,
        public readonly string $os,
        public readonly string $device,
        public readonly string $browser,
        public readonly array $query,
        public readonly string $keyword,
        public readonly string $referer,
        private readonly \Closure $filteredCheck,
        private readonly \Closure $fraudCheck,
    ) {
    }

    /**
     * The facts of this request: its keyword by the account's preference
     * (ClickRequest::keyword()), and its referer as 1.9.56 read it for a
     * referer rule: the link's t202ref when the account prefers it, else the
     * referring page's ?url=, else the referring page.
     *
     * @param array<array-key, mixed> $get
     * @param array<array-key, mixed> $server
     * @param array<string, mixed> $geo
     * @param \Closure(): ?bool $filteredCheck
     * @param \Closure(): ?bool $fraudCheck
     */
    public static function fromRequest(
        array $get,
        array $server,
        ?string $keywordPreference,
        ?string $refererPreference,
        string $ip,
        array $geo,
        ?string $isp,
        string $os,
        string $device,
        string $browser,
        \Closure $filteredCheck,
        \Closure $fraudCheck,
    ): self {
        $httpReferer = ClickRequest::text($server, 'HTTP_REFERER');
        $refererQuery = [];
        $query = parse_url($httpReferer, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $refererQuery);
        }
        $referer = $refererPreference === 't202ref' ? ClickRequest::text($get, 't202ref') : '';
        if ($referer === '') {
            $referer = ClickRequest::text($refererQuery, 'url');
        }
        if ($referer === '') {
            $referer = $httpReferer;
        }

        return new self(
            $ip,
            $geo,
            $isp,
            $os,
            $device,
            $browser,
            $get,
            ClickRequest::keyword($get, $refererQuery, $keywordPreference),
            $referer,
            $filteredCheck,
            $fraudCheck,
        );
    }

    /** A query value as the click stores it: '' when absent or a list, %20 as a space. */
    public function param(string $name): string
    {
        return str_replace('%20', ' ', ClickRequest::text($this->query, $name));
    }

    public function filtered(): ?bool
    {
        if (!array_key_exists('filtered', $this->asked)) {
            $this->asked['filtered'] = ($this->filteredCheck)();
        }

        return $this->asked['filtered'];
    }

    public function fraud(): ?bool
    {
        if (!array_key_exists('fraud', $this->asked)) {
            $this->asked['fraud'] = ($this->fraudCheck)();
        }

        return $this->asked['fraud'];
    }
}
