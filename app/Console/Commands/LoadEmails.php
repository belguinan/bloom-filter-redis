<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Services\RedisBloomFilter;
use App\Support\SampleEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LoadEmails extends Command
{
    protected $signature = 'emails:load
        {--count=1000000 : Nombre d’adresses à générer}
        {--error-rate=0.01 : Taux cible de faux positifs}
        {--batch=1000 : Taille des lots}';

    protected $description = 'Charge le même dataset synthétique dans MySQL et Redis Bloom';

    public function handle(RedisBloomFilter $bloomFilter): int
    {
        $count = (int) $this->option('count');
        $errorRate = (float) $this->option('error-rate');
        $batchSize = (int) $this->option('batch');

        if ($count < 1) {
            $this->error('count doit être positif.');

            return self::FAILURE;
        }

        if ($errorRate <= 0 || $errorRate >= 1) {
            $this->error('error-rate doit être compris entre 0 et 1.');

            return self::FAILURE;
        }

        if ($batchSize < 1 || $batchSize > 5000) {
            $this->error('batch doit être compris entre 1 et 5000.');

            return self::FAILURE;
        }

        $startedAt = hrtime(true);
        Email::query()->truncate();
        $shape = $bloomFilter->initialize($count, $errorRate, true);
        $batches = (int) ceil($count / $batchSize);
        $this->output->progressStart($batches);

        for ($first = 1; $first <= $count; $first += $batchSize) {
            $size = min($batchSize, $count - $first + 1);
            $emails = SampleEmail::batch($first, $size);
            $rows = array_map(fn (string $email): array => ['email' => $email], $emails);

            Email::query()->insert($rows);
            $bloomFilter->addMany($emails);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $rows = Email::query()->count();

        if ($rows !== $count) {
            $this->error("MySQL contient {$rows} lignes au lieu de {$count}.");

            return self::FAILURE;
        }

        $bloomFilter->markReady();
        $stats = $bloomFilter->stats();
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $result = [
            'generated_at' => now()->toIso8601String(),
            'dataset' => [
                'type' => 'synthetic-deterministic',
                'pattern' => 'student%07d@example.test',
                'count' => $count,
                'first' => SampleEmail::at(1),
                'last' => SampleEmail::at($count),
            ],
            'mysql_rows' => $rows,
            'filter' => $stats,
            'elapsed_seconds' => round($elapsedSeconds, 3),
        ];

        File::ensureDirectoryExists(base_path('results'));
        File::put(
            base_path('results/import.json'),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->newLine();
        $this->table(
            ['Éléments', 'Bits', 'Hachages', 'Mémoire Redis', 'Durée'],
            [[
                number_format($count, 0, ',', ' '),
                number_format($shape['bits'], 0, ',', ' '),
                $shape['hashes'],
                number_format($stats['total_memory_bytes'], 0, ',', ' ').' octets',
                number_format($elapsedSeconds, 2, ',', ' ').' s',
            ]],
        );

        return self::SUCCESS;
    }
}
