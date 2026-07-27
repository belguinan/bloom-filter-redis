<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Services\EmailLookup;
use App\Services\RedisBloomFilter;
use App\Support\SampleEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class BenchmarkEmails extends Command
{
    protected $signature = 'emails:benchmark
        {--queries=10000 : Requêtes par classe et par exécution}
        {--runs=5 : Nombre d’exécutions mesurées}
        {--warmup=1000 : Requêtes d’échauffement par classe}
        {--quality=100000 : Échantillon de contrôle probabiliste}';

    protected $description = 'Compare MySQL et Redis Bloom sur la même recherche d’appartenance';

    public function handle(EmailLookup $lookup, RedisBloomFilter $bloomFilter): int
    {
        $queryCount = (int) $this->option('queries');
        $runCount = (int) $this->option('runs');
        $warmupCount = (int) $this->option('warmup');
        $qualityCount = (int) $this->option('quality');

        if ($queryCount < 100) {
            $this->error('queries doit être supérieur ou égal à 100.');

            return self::FAILURE;
        }

        if ($runCount < 1) {
            $this->error('runs doit être positif.');

            return self::FAILURE;
        }

        if ($warmupCount < 0) {
            $this->error('warmup ne peut pas être négatif.');

            return self::FAILURE;
        }

        if ($qualityCount < 1000) {
            $this->error('quality doit être supérieur ou égal à 1000.');

            return self::FAILURE;
        }

        $mysqlRows = Email::query()->count();
        $filter = $bloomFilter->ensureReady();

        if ($mysqlRows !== $filter['inserted']) {
            $this->error("MySQL contient {$mysqlRows} lignes et Redis {$filter['inserted']} insertions.");

            return self::FAILURE;
        }

        if ($queryCount > $mysqlRows || $qualityCount > $mysqlRows) {
            $this->error('Les échantillons dépassent la taille du dataset.');

            return self::FAILURE;
        }

        $runs = [];

        for ($run = 1; $run <= $runCount; $run++) {
            $present = $this->presentEmails($mysqlRows, $queryCount, $run);
            $absent = $this->absentEmails($queryCount, $run);
            $mixed = $this->interleave($present, $absent);
            $order = $run % 2 === 1 ? ['redis', 'mysql'] : ['mysql', 'redis'];
            $engines = [];

            foreach ($order as $engine) {
                $lookup->prepare($engine);
                $this->warmup($lookup, $engine, $present, $absent, $warmupCount);
                $engines[$engine] = [
                    'present' => $this->measure($lookup, $engine, $present),
                    'absent' => $this->measure($lookup, $engine, $absent),
                    'mixed' => $this->measure($lookup, $engine, $mixed),
                ];
            }

            $runs[] = [
                'number' => $run,
                'order' => $order,
                'engines' => $engines,
            ];

            $this->line("Exécution {$run}/{$runCount} terminée.");
        }

        $this->line('Contrôle des faux positifs et faux négatifs.');
        $quality = $this->quality($bloomFilter, $mysqlRows, $qualityCount);
        $storage = $this->storage($bloomFilter, $mysqlRows);
        $benchmarks = $this->aggregate($runs);
        $result = [
            'generated_at' => now()->toIso8601String(),
            'scope' => 'Warm local single-client membership benchmark',
            'dataset' => [
                'type' => 'synthetic-deterministic',
                'pattern' => 'student%07d@example.test',
                'mysql_rows' => $mysqlRows,
            ],
            'protocol' => [
                'queries_per_class' => $queryCount,
                'runs' => $runCount,
                'warmup_per_class' => min($warmupCount, $queryCount),
                'quality_sample_per_class' => $qualityCount,
                'workloads' => ['present', 'absent', 'mixed-50-50'],
                'execution_order' => 'alternating',
                'http_overhead_included' => false,
            ],
            'filter' => $bloomFilter->stats(),
            'quality' => $quality,
            'storage' => $storage,
            'benchmarks' => $benchmarks,
            'runs' => $runs,
            'environment' => $this->environment(),
        ];

        File::ensureDirectoryExists(base_path('results'));
        File::put(
            base_path('results/benchmark.json'),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
        File::put(base_path('results/benchmark.md'), $this->markdown($result));

        $this->newLine();
        $this->table(
            ['Moteur', 'Charge', 'p50', 'p95', 'p99', 'req/s'],
            $this->consoleRows($benchmarks),
        );
        $this->newLine();
        $this->info('Résultats écrits dans results/benchmark.json et results/benchmark.md.');

        return self::SUCCESS;
    }

    private function warmup(
        EmailLookup $lookup,
        string $engine,
        array $present,
        array $absent,
        int $count,
    ): void {
        $limit = min($count, count($present));

        for ($index = 0; $index < $limit; $index++) {
            $lookup->exists($engine, $present[$index]);
            $lookup->exists($engine, $absent[$index]);
        }
    }

    private function measure(EmailLookup $lookup, string $engine, array $emails): array
    {
        $durations = [];
        $positiveResults = 0;
        $totalStartedAt = hrtime(true);

        foreach ($emails as $email) {
            $startedAt = hrtime(true);
            $found = $lookup->exists($engine, $email);
            $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
            $positiveResults += (int) $found;
        }

        $totalMs = (hrtime(true) - $totalStartedAt) / 1_000_000;
        sort($durations, SORT_NUMERIC);

        return [
            'queries' => count($emails),
            'positive_results' => $positiveResults,
            'total_ms' => round($totalMs, 6),
            'mean_ms' => round(array_sum($durations) / count($durations), 6),
            'p50_ms' => round($this->percentile($durations, 0.50), 6),
            'p95_ms' => round($this->percentile($durations, 0.95), 6),
            'p99_ms' => round($this->percentile($durations, 0.99), 6),
            'queries_per_second' => round(count($emails) / $totalMs * 1000, 3),
        ];
    }

    private function aggregate(array $runs): array
    {
        $result = [];
        $metrics = [
            'queries',
            'positive_results',
            'total_ms',
            'mean_ms',
            'p50_ms',
            'p95_ms',
            'p99_ms',
            'queries_per_second',
        ];

        foreach (['redis', 'mysql'] as $engine) {
            foreach (['present', 'absent', 'mixed'] as $workload) {
                foreach ($metrics as $metric) {
                    $values = array_map(
                        fn (array $run): float => (float) $run['engines'][$engine][$workload][$metric],
                        $runs,
                    );
                    $result[$engine][$workload][$metric] = round($this->median($values), 6);
                }
            }
        }

        return $result;
    }

    private function quality(
        RedisBloomFilter $bloomFilter,
        int $mysqlRows,
        int $sampleSize,
    ): array {
        $present = $this->presentEmails($mysqlRows, $sampleSize, 97);
        $absent = $this->absentEmails($sampleSize, 97);
        $mysqlPresent = $this->countExisting($present);
        $mysqlAbsent = $this->countExisting($absent);
        $bloomPresent = $this->countBloomPositives($bloomFilter, $present);
        $bloomAbsent = $this->countBloomPositives($bloomFilter, $absent);

        return [
            'sample_per_class' => $sampleSize,
            'false_negatives' => $sampleSize - $bloomPresent,
            'false_positives' => $bloomAbsent,
            'observed_false_positive_rate' => $bloomAbsent / $sampleSize,
            'target_false_positive_rate' => (float) $bloomFilter->metadata()['error_rate'],
            'mysql_present_errors' => $sampleSize - $mysqlPresent,
            'mysql_absent_errors' => $mysqlAbsent,
        ];
    }

    private function countExisting(array $emails): int
    {
        return array_reduce(
            array_chunk($emails, 1000),
            fn (int $total, array $chunk): int => $total + Email::query()->whereIn('email', $chunk)->count(),
            0,
        );
    }

    private function countBloomPositives(RedisBloomFilter $bloomFilter, array $emails): int
    {
        return array_reduce(
            $emails,
            fn (int $total, string $email): int => $total + (int) $bloomFilter->contains($email),
            0,
        );
    }

    private function storage(RedisBloomFilter $bloomFilter, int $mysqlRows): array
    {
        DB::statement('ANALYZE TABLE emails');
        $table = DB::selectOne(
            'SELECT DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['emails'],
        );
        $mysqlBytes = (int) $table->data_bytes + (int) $table->index_bytes;
        $redis = $bloomFilter->stats();

        return [
            'mysql_data_bytes' => (int) $table->data_bytes,
            'mysql_index_bytes' => (int) $table->index_bytes,
            'mysql_total_bytes' => $mysqlBytes,
            'mysql_bytes_per_email' => $mysqlBytes / $mysqlRows,
            'redis_bitmap_bytes' => $redis['bitmap_memory_bytes'],
            'redis_metadata_bytes' => $redis['metadata_memory_bytes'],
            'redis_total_bytes' => $redis['total_memory_bytes'],
            'redis_bytes_per_email' => $redis['total_memory_bytes'] / $mysqlRows,
            'redis_theoretical_bytes' => $redis['theoretical_bytes'],
            'storage_reduction_rate' => 1 - $redis['total_memory_bytes'] / $mysqlBytes,
        ];
    }

    private function environment(): array
    {
        $redis = Redis::info('server');
        $mysql = DB::selectOne('SELECT VERSION() AS version');

        return [
            'platform' => php_uname('s').' '.php_uname('r'),
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'redis' => $redis['redis_version'] ?? 'unknown',
            'mysql' => $mysql->version,
        ];
    }

    private function presentEmails(int $datasetSize, int $count, int $seed): array
    {
        $offset = ($seed * 104729) % $datasetSize;

        return array_map(
            fn (int $index): string => SampleEmail::at((($offset + $index * 7919) % $datasetSize) + 1),
            range(0, $count - 1),
        );
    }

    private function absentEmails(int $count, int $seed): array
    {
        $offset = $seed * $count;

        return array_map(
            fn (int $index): string => SampleEmail::missing($offset + $index + 1),
            range(0, $count - 1),
        );
    }

    private function interleave(array $present, array $absent): array
    {
        $mixed = [];

        foreach ($present as $index => $email) {
            $mixed[] = $email;
            $mixed[] = $absent[$index];
        }

        return $mixed;
    }

    private function percentile(array $ordered, float $ratio): float
    {
        $index = max(0, min(count($ordered) - 1, (int) ceil(count($ordered) * $ratio) - 1));

        return $ordered[$index];
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function consoleRows(array $benchmarks): array
    {
        $rows = [];

        foreach (['redis' => 'Redis Bloom', 'mysql' => 'MySQL'] as $engine => $label) {
            foreach (['present' => 'Présents', 'absent' => 'Absents', 'mixed' => '50/50'] as $workload => $name) {
                $metrics = $benchmarks[$engine][$workload];
                $rows[] = [
                    $label,
                    $name,
                    $this->decimal($metrics['p50_ms']).' ms',
                    $this->decimal($metrics['p95_ms']).' ms',
                    $this->decimal($metrics['p99_ms']).' ms',
                    number_format($metrics['queries_per_second'], 0, ',', ' '),
                ];
            }
        }

        return $rows;
    }

    private function markdown(array $result): string
    {
        $lines = [
            '# Résultats du benchmark MySQL / Redis Bloom',
            '',
            'Généré le '.$result['generated_at'].'.',
            '',
            '## Cadre expérimental',
            '',
            '- Dataset synthétique déterministe : '.number_format($result['dataset']['mysql_rows'], 0, ',', ' ').' emails.',
            '- Même séquence de requêtes pour les deux moteurs.',
            '- '.$result['protocol']['runs'].' exécutions avec ordre alterné et '.number_format($result['protocol']['warmup_per_class'], 0, ',', ' ').' requêtes d’échauffement par classe.',
            '- Mesure locale, séquentielle et à chaud ; le temps HTTP est exclu.',
            '',
            '## Latence et débit',
            '',
            '| Moteur | Charge | Moyenne | p50 | p95 | p99 | Requêtes/s |',
            '|---|---|---:|---:|---:|---:|---:|',
        ];

        foreach (['redis' => 'Redis Bloom', 'mysql' => 'MySQL indexé'] as $engine => $label) {
            foreach (['present' => 'Présents', 'absent' => 'Absents', 'mixed' => '50 % / 50 %'] as $workload => $name) {
                $metrics = $result['benchmarks'][$engine][$workload];
                $lines[] = sprintf(
                    '| %s | %s | %s ms | %s ms | %s ms | %s ms | %s |',
                    $label,
                    $name,
                    $this->decimal($metrics['mean_ms']),
                    $this->decimal($metrics['p50_ms']),
                    $this->decimal($metrics['p95_ms']),
                    $this->decimal($metrics['p99_ms']),
                    number_format($metrics['queries_per_second'], 0, ',', ' '),
                );
            }
        }

        $quality = $result['quality'];
        $storage = $result['storage'];
        $lines = [
            ...$lines,
            '',
            '## Exactitude',
            '',
            '| Indicateur | Résultat |',
            '|---|---:|',
            '| Faux négatifs Redis Bloom | '.$quality['false_negatives'].' |',
            '| Faux positifs Redis Bloom | '.number_format($quality['false_positives'], 0, ',', ' ').' |',
            '| Taux observé de faux positifs | '.$this->percent($quality['observed_false_positive_rate'], 3).' |',
            '| Taux cible | '.$this->percent($quality['target_false_positive_rate'], 3).' |',
            '| Erreurs MySQL | '.($quality['mysql_present_errors'] + $quality['mysql_absent_errors']).' |',
            '',
            '## Stockage',
            '',
            '| Structure | Octets | Octets/email |',
            '|---|---:|---:|',
            '| MySQL, données et index | '.number_format($storage['mysql_total_bytes'], 0, ',', ' ').' | '.$this->decimal($storage['mysql_bytes_per_email']).' |',
            '| Redis, bitmap et métadonnées | '.number_format($storage['redis_total_bytes'], 0, ',', ' ').' | '.$this->decimal($storage['redis_bytes_per_email']).' |',
            '| Bitmap théorique | '.number_format($storage['redis_theoretical_bytes'], 0, ',', ' ').' | '.$this->decimal($storage['redis_theoretical_bytes'] / $result['dataset']['mysql_rows']).' |',
            '',
            'Réduction de stockage mesurée pour ce test : **'.$this->percent($storage['storage_reduction_rate']).'**.',
            '',
            '## Interprétation',
            '',
            'MySQL conserve les emails complets et fournit une réponse exacte. Le Bloom Filter conserve uniquement une appartenance probabiliste : absence certaine ou présence probable. Les mesures comparent donc le coût de cette requête précise, pas les capacités générales des deux systèmes.',
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 3, ',', ' ');
    }

    private function percent(float $value, int $precision = 2): string
    {
        return number_format($value * 100, $precision, ',', ' ').' %';
    }
}
