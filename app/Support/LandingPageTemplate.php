<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Registered public landing layouts (all served at / with the same CMS data).
 */
final class LandingPageTemplate
{
    public const DEFAULT = 'landing-two';

    /** @var array<string, array{view: string, label_key: string, description_key: string}> */
    private const DEFINITIONS = [
        'default' => [
            'view'            => 'central.landing',
            'label_key'       => 'super.landing_templates.classic_label',
            'description_key' => 'super.landing_templates.classic_desc',
        ],
        'landing-two' => [
            'view'            => 'central.landing-two',
            'label_key'       => 'super.landing_templates.v2_label',
            'description_key' => 'super.landing_templates.v2_desc',
        ],
        'landing-three' => [
            'view'            => 'central.landing-three',
            'label_key'       => 'super.landing_templates.v3_label',
            'description_key' => 'super.landing_templates.v3_desc',
        ],
        'landing-four' => [
            'view'            => 'central.landing-four',
            'label_key'       => 'super.landing_templates.v4_label',
            'description_key' => 'super.landing_templates.v4_desc',
        ],
        'landing-five' => [
            'view'            => 'central.landing-five',
            'label_key'       => 'super.landing_templates.v5_label',
            'description_key' => 'super.landing_templates.v5_desc',
        ],
        'landing-six' => [
            'view'            => 'central.landing-six',
            'label_key'       => 'super.landing_templates.v6_label',
            'description_key' => 'super.landing_templates.v6_desc',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && $key !== '' && isset(self::DEFINITIONS[$key]);
    }

    /**
     * Normalized template key from DB or config (invalid / empty → default).
     */
    public static function canonicalKey(?string $key): string
    {
        return self::isValid($key) ? $key : self::DEFAULT;
    }

    public static function view(?string $key): string
    {
        $key = self::canonicalKey($key);

        return self::DEFINITIONS[$key]['view'];
    }

    public static function label(?string $key): string
    {
        $key = self::isValid($key) ? $key : self::DEFAULT;

        return __(self::DEFINITIONS[$key]['label_key']);
    }

    public static function description(?string $key): string
    {
        $key = self::isValid($key) ? $key : self::DEFAULT;

        return __(self::DEFINITIONS[$key]['description_key']);
    }

    /**
     * @return list<array{key: string, view: string, label: string, description: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $out[] = [
                'key'         => $key,
                'view'        => $def['view'],
                'label'       => __($def['label_key']),
                'description' => __($def['description_key']),
            ];
        }

        return $out;
    }
}
