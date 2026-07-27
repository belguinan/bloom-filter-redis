<?php

namespace App\Support;

use InvalidArgumentException;

class SampleEmail
{
    public static function at(int $index): string
    {
        if ($index < 1) {
            throw new InvalidArgumentException('L’index doit être positif.');
        }

        return sprintf('student%07d@example.test', $index);
    }

    public static function missing(int $index): string
    {
        if ($index < 1) {
            throw new InvalidArgumentException('L’index doit être positif.');
        }

        return sprintf('unknown%07d@example.test', $index);
    }

    public static function batch(int $first, int $count): array
    {
        return array_map(
            fn (int $index): string => self::at($index),
            range($first, $first + $count - 1),
        );
    }
}
