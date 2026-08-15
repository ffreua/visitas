<?php

namespace Tests\Unit;

use App\Services\Percentiles;
use Tests\TestCase;

class PercentilesTest extends TestCase
{
    public function test_empty_returns_nulls(): void
    {
        $result = Percentiles::summarize([]);
        $this->assertNull($result['median']);
    }

    public function test_single_value(): void
    {
        $result = Percentiles::summarize([10.0]);
        $this->assertSame(10.0, $result['median']);
        $this->assertSame(10.0, $result['p25']);
    }

    public function test_median_of_known_set(): void
    {
        $result = Percentiles::summarize([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $this->assertSame(5.5, $result['median']);
        $this->assertEqualsWithDelta(3.25, $result['p25'], 0.01);
        $this->assertEqualsWithDelta(7.75, $result['p75'], 0.01);
    }
}
