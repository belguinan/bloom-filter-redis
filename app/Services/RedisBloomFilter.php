<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class RedisBloomFilter
{
    private string $key;

    private string $metadataKey;

    private ?array $cachedMetadata = null;

    public function __construct()
    {
        $this->key = (string) config('bloom.key');
        $this->metadataKey = $this->key.':metadata';
    }

    public static function shape(int $capacity, float $errorRate): array
    {
        if ($capacity < 1) {
            throw new RuntimeException('La capacité doit être positive.');
        }

        if ($errorRate <= 0 || $errorRate >= 1) {
            throw new RuntimeException('Le taux d’erreur doit être compris entre 0 et 1.');
        }

        $logTwo = log(2);
        $bits = (int) ceil(-$capacity * log($errorRate) / ($logTwo ** 2));
        $hashes = max(1, (int) round($bits / $capacity * $logTwo));

        return [
            'capacity' => $capacity,
            'error_rate' => $errorRate,
            'bits' => $bits,
            'hashes' => $hashes,
            'theoretical_bytes' => (int) ceil($bits / 8),
        ];
    }

    public function initialize(int $capacity, float $errorRate, bool $reset = false): array
    {
        $shape = self::shape($capacity, $errorRate);

        if ($reset) {
            $this->reset();
        }

        Redis::hMSet($this->metadataKey, [
            'capacity' => (string) $shape['capacity'],
            'error_rate' => (string) $shape['error_rate'],
            'bits' => (string) $shape['bits'],
            'hashes' => (string) $shape['hashes'],
            'theoretical_bytes' => (string) $shape['theoretical_bytes'],
            'inserted' => '0',
            'status' => 'building',
            'implementation' => 'redis-bitmap-sha256-double-hashing',
        ]);

        $this->cachedMetadata = [
            ...$shape,
            'inserted' => 0,
            'status' => 'building',
            'implementation' => 'redis-bitmap-sha256-double-hashing',
        ];

        return $shape;
    }

    public function reset(): void
    {
        Redis::del($this->key, $this->metadataKey);
        $this->cachedMetadata = null;
    }

    public function addMany(array $emails): void
    {
        if ($emails === []) {
            return;
        }

        $shape = $this->metadata();

        Redis::pipeline(function ($pipe) use ($emails, $shape): void {
            foreach ($emails as $email) {
                foreach ($this->positions($email, $shape['bits'], $shape['hashes']) as $position) {
                    $pipe->setbit($this->key, $position, 1);
                }
            }
        });

        Redis::hincrby($this->metadataKey, 'inserted', count($emails));
        $this->cachedMetadata['inserted'] += count($emails);
    }

    public function markReady(): void
    {
        Redis::hset($this->metadataKey, 'status', 'ready');
        $this->cachedMetadata['status'] = 'ready';
    }

    public function contains(string $email): bool
    {
        $shape = $this->readyMetadata();
        $bits = Redis::pipeline(function ($pipe) use ($email, $shape): void {
            foreach ($this->positions($email, $shape['bits'], $shape['hashes']) as $position) {
                $pipe->getbit($this->key, $position);
            }
        });

        return ! in_array(0, array_map('intval', $bits), true);
    }

    public function metadata(): array
    {
        if ($this->cachedMetadata !== null) {
            return $this->cachedMetadata;
        }

        $metadata = Redis::hgetall($this->metadataKey);

        if ($metadata === []) {
            throw new RuntimeException('Le Bloom Filter n’est pas initialisé.');
        }

        $this->cachedMetadata = [
            'capacity' => (int) $metadata['capacity'],
            'error_rate' => (float) $metadata['error_rate'],
            'bits' => (int) $metadata['bits'],
            'hashes' => (int) $metadata['hashes'],
            'theoretical_bytes' => (int) $metadata['theoretical_bytes'],
            'inserted' => (int) $metadata['inserted'],
            'status' => $metadata['status'],
            'implementation' => $metadata['implementation'],
        ];

        return $this->cachedMetadata;
    }

    public function stats(): array
    {
        $metadata = $this->metadata();
        $connection = Redis::connection();
        $bitmapBytes = (int) ($connection->executeRaw(['MEMORY', 'USAGE', $this->key]) ?? 0);
        $metadataBytes = (int) ($connection->executeRaw(['MEMORY', 'USAGE', $this->metadataKey]) ?? 0);
        $setBits = (int) Redis::bitcount($this->key);

        return [
            ...$metadata,
            'bitmap_memory_bytes' => $bitmapBytes,
            'metadata_memory_bytes' => $metadataBytes,
            'total_memory_bytes' => $bitmapBytes + $metadataBytes,
            'set_bits' => $setBits,
            'fill_ratio' => $setBits / $metadata['bits'],
        ];
    }

    public function positions(string $value, int $bits, int $hashes): array
    {
        $digest = hash('sha256', $value, true);
        $parts = unpack('Nfirst/Nsecond', substr($digest, 0, 8));
        $first = (int) $parts['first'];
        $second = (int) $parts['second'];
        $step = $second === 0 ? 2654435761 : $second;
        $positions = [];

        for ($index = 0; $index < $hashes; $index++) {
            $positions[] = (int) (($first + $index * $step) % $bits);
        }

        return $positions;
    }

    public function ensureReady(): array
    {
        return $this->readyMetadata();
    }

    private function readyMetadata(): array
    {
        $metadata = $this->metadata();

        if ($metadata['status'] !== 'ready') {
            throw new RuntimeException('Le Bloom Filter n’est pas prêt.');
        }

        return $metadata;
    }
}
