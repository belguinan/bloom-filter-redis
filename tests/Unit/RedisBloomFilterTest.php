<?php

namespace Tests\Unit;

use App\Services\RedisBloomFilter;
use Tests\TestCase;

class RedisBloomFilterTest extends TestCase
{
    public function test_shape_matches_one_million_items_at_one_percent(): void
    {
        $shape = RedisBloomFilter::shape(1_000_000, 0.01);

        $this->assertSame(9_585_059, $shape['bits']);
        $this->assertSame(7, $shape['hashes']);
        $this->assertSame(1_198_133, $shape['theoretical_bytes']);
    }

    public function test_positions_are_deterministic_and_bounded(): void
    {
        $filter = app(RedisBloomFilter::class);
        $first = $filter->positions('student0000001@example.test', 9_585_059, 7);
        $second = $filter->positions('student0000001@example.test', 9_585_059, 7);

        $this->assertSame($first, $second);
        $this->assertCount(7, $first);
        $this->assertContainsOnlyInt($first);
        $this->assertTrue(max($first) < 9_585_059);
        $this->assertTrue(min($first) >= 0);
    }
}
