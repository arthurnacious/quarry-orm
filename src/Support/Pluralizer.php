<?php

namespace Quarry\Support;

class Pluralizer
{
    private static array $irregulars = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'foot' => 'feet',
        'tooth' => 'teeth',
        'mouse' => 'mice',
        'goose' => 'geese',
        'ox' => 'oxen',
        'leaf' => 'leaves',
    ];

    public static function pluralize(string $word): string
    {
        $lower = strtolower($word);

        if (isset(self::$irregulars[$lower])) {
            return self::$irregulars[$lower];
        }

        $lastChar = substr($lower, -1);
        $lastTwo = substr($lower, -2);

        if (in_array($lastTwo, ['sh', 'ch']) || in_array($lastChar, ['s', 'x', 'z'])) {
            return $word . 'es';
        }

        if ($lastChar === 'y' && !self::isVowel(substr($lower, -2, 1))) {
            return substr($word, 0, -1) . 'ies';
        }

        return $word . 's';
    }

    private static function isVowel(string $char): bool
    {
        return in_array(strtolower($char), ['a', 'e', 'i', 'o', 'u']);
    }
}
