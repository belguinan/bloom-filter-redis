<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailLookup
{
    public function __construct(private RedisBloomFilter $bloomFilter) {}

    public function exists(string $engine, string $email): bool
    {
        return match ($engine) {
            'mysql' => Email::query()->whereKey($email)->exists(),
            'redis' => $this->bloomFilter->contains($email),
            default => throw new InvalidArgumentException('Moteur inconnu.'),
        };
    }

    public function prepare(string $engine): void
    {
        match ($engine) {
            'mysql' => DB::connection()->getPdo(),
            'redis' => $this->bloomFilter->ensureReady(),
            default => throw new InvalidArgumentException('Moteur inconnu.'),
        };
    }
}
