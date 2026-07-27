<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Services\EmailLookup;
use App\Services\RedisBloomFilter;
use App\Support\SampleEmail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngineIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Intégration désactivée.');
        }
    }

    public function test_both_engines_use_the_same_million_email_dataset(): void
    {
        $lookup = app(EmailLookup::class);
        $filter = app(RedisBloomFilter::class);

        $this->assertSame(1_000_000, Email::query()->count());
        $this->assertSame(1_000_000, $filter->ensureReady()['inserted']);
        $this->assertTrue($lookup->exists('mysql', SampleEmail::at(1)));
        $this->assertTrue($lookup->exists('redis', SampleEmail::at(1)));
        $this->assertFalse($lookup->exists('mysql', SampleEmail::missing(1)));
    }

    public function test_bloom_has_no_false_negatives_on_a_real_sample(): void
    {
        $filter = app(RedisBloomFilter::class);
        $falseNegatives = 0;

        for ($index = 1; $index <= 1000; $index++) {
            $falseNegatives += (int) ! $filter->contains(SampleEmail::at($index));
        }

        $this->assertSame(0, $falseNegatives);
    }

    public function test_mysql_membership_query_uses_the_primary_index(): void
    {
        $plan = DB::selectOne(
            'EXPLAIN SELECT 1 FROM emails WHERE email = ? LIMIT 1',
            [SampleEmail::at(1)],
        );

        $this->assertSame('PRIMARY', $plan->key);
        $this->assertSame('const', $plan->type);
    }
}
