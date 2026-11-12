<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Http\PostedId;

/**
 * Setup › Destinations' edit form, read into the patch DestinationStore::
 * update() takes. Settings are replaced whole there, so the patch starts
 * from the stored ones and a setting the form does not show keeps its value.
 * A credential left blank is left out: the page saves with
 * $keepUnnamedCredentials, so blank means keep.
 */
final class DestinationForm
{
    /** Blank rows offered for a new event parameter or per-campaign action. */
    public const BLANK_ROWS = 2;

    /**
     * @param array<string, mixed> $post the posted form
     * @param array<string, mixed> $destination as DestinationStore::get() shapes it
     * @return array{name: string, settings: array<string, mixed>, credentials?: array<string, string>}
     */
    public static function patch(array $post, array $destination, DestinationType $type): array
    {
        $str = static fn (string $key): string => self::text($post, $key);
        $settings = (array) $destination['settings'];
        unset($settings['settings_invalid']);

        if (($post['all_campaigns'] ?? '') === '1') {
            $settings['campaign_ids'] = [];
        } else {
            $ids = self::ids($post['campaign_ids'] ?? [], 'settings.campaign_ids', 'campaign_ids');
            if ($ids === []) {
                throw new DestinationInputException('settings.campaign_ids', 'Pick the campaigns it sends, or tick Every campaign.');
            }
            $settings['campaign_ids'] = $ids;
        }
        $settings['send_user_data'] = ($post['send_user_data'] ?? '') === '1';
        if ($type->takesConsent()) {
            $settings['consent'] = array_filter(['ad_user_data' => $str('ad_user_data'), 'ad_personalization' => $str('ad_personalization')], static fn (string $v): bool => $v !== '');
        }
        $settings = $type->formSettings($post, $settings);

        $patch = ['name' => $str('name'), 'settings' => $settings];
        $credentials = [];
        foreach ($type->credentialKeys() as $key) {
            if ($str($key) !== '') {
                $credentials[$key] = $str($key);
            }
        }
        if ($credentials !== []) {
            $patch['credentials'] = $credentials;
        }

        return $patch;
    }

    /**
     * The rows of two parallel lists where the second is filled: a row with
     * a blank second value is one the operator removed.
     *
     * @param array<string, mixed> $post
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(array $post, string $first, string $second, string $field, string $firstMissing): array
    {
        $a = $post[$first] ?? [];
        $b = $post[$second] ?? [];
        if (!is_array($a) || !is_array($b) || !array_is_list($a) || !array_is_list($b) || count($a) !== count($b) || count($a) > 600) {
            throw new DestinationInputException($field, 'The form\'s rows could not be read; reload the page and try again.');
        }
        $out = [];
        foreach ($a as $i => $one) {
            $one = is_string($one) ? trim($one) : '';
            $two = is_string($b[$i]) ? trim($b[$i]) : '';
            if ($two === '') {
                continue;
            }
            if ($one === '') {
                throw new DestinationInputException($field, $firstMissing);
            }
            $out[] = [$one, $two];
        }

        return $out;
    }

    /** A posted text field, trimmed; '' when it is absent or not text. */
    public static function text(array $post, string $key): string
    {
        return is_string($post[$key] ?? null) ? trim($post[$key]) : '';
    }

    /** @return list<int> */
    public static function ids(mixed $posted, string $field, string $name): array
    {
        if (!is_array($posted) || !array_is_list($posted)) {
            throw new DestinationInputException($field, $name . ' could not be read; reload the page and try again.');
        }
        $ids = [];
        foreach ($posted as $value) {
            $id = PostedId::read($value) ?? throw new DestinationInputException($field, $name . ' names a campaign from the list.');
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
