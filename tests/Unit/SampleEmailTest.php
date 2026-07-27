<?php

namespace Tests\Unit;

use App\Support\SampleEmail;
use PHPUnit\Framework\TestCase;

class SampleEmailTest extends TestCase
{
    public function test_generates_readable_deterministic_samples(): void
    {
        $this->assertSame('student0000001@example.test', SampleEmail::at(1));
        $this->assertSame('student1000000@example.test', SampleEmail::at(1_000_000));
        $this->assertSame('unknown0000001@example.test', SampleEmail::missing(1));
    }
}
