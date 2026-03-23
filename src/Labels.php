<?php

declare(strict_types=1);

namespace SeeOurFamily;

/**
 * Multilingual label loader.
 *
 * Replaces Include/Label.asp (one giant Select Case with ~50 vars x 7 languages).
 * Each language is now a separate file in lang/ returning an associative array.
 * English (lang/en.php) serves as the fallback for missing keys.
 *
 * Usage:
 *   $labels = new Labels('FRA');
 *   echo $labels->get('menu_home');  // "<b>a</b>ccueil"
 */
class Labels
{
    /** @var array<string, string|array> */
    private array $strings;

    private static array $langMap = [
        'ENG' => 'en',
        'FRA' => 'fr',
        'ESP' => 'es',
        'ITA' => 'it',
        'POR' => 'pt',
        'DEU' => 'de',
        'NLD' => 'nl',
    ];

    public function __construct(string $langCode = 'ENG')
    {
        $langDir = dirname(__DIR__) . '/lang';
        $defaults = require $langDir . '/en.php';
        $file = self::$langMap[$langCode] ?? 'en';
        $langFile = $langDir . '/' . $file . '.php';

        if ($file !== 'en' && file_exists($langFile)) {
            $overrides = require $langFile;
            $this->strings = array_merge($defaults, $overrides);
        } else {
            $this->strings = $defaults;
        }
    }

    /** Get a label by key. Returns the key itself if not found. */
    public function get(string $key): string|array
    {
        return $this->strings[$key] ?? $key;
    }

    /** Get all labels as an array (for templates that use $L['key']). */
    public function all(): array
    {
        return $this->strings;
    }
}
