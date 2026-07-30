<?php

namespace Tests\Unit;

use App\Support\InsightSkillMetric as M;
use Tests\TestCase;

class InsightSkillMetricTest extends TestCase
{
    public function test_parses_values_per_section(): void
    {
        $this->assertSame(264759.91, M::value('earnings_per_skill', ['value' => '$264,759.91']));
        $this->assertSame(5.0, M::value('rating_per_skill', ['value' => 5]));
        $this->assertSame(9.0, M::value('ranking_per_skill', ['displayValue' => 'Top 9%']));
        $this->assertSame(27.0, M::value('high_demand_skills', ['value' => '+27%']));
        $this->assertSame(-10.0, M::value('high_demand_skills', ['value' => '-10%']));
        $this->assertNull(M::value('earnings_per_skill', ['value' => null]));
        $this->assertNull(M::value('earnings_per_skill', ['value' => 'n/a']));
    }

    public function test_label_prefers_label_then_name(): void
    {
        $this->assertSame('PHP', M::label(['label' => 'PHP']));
        $this->assertSame('HTML', M::label(['name' => 'HTML']));
        $this->assertNull(M::label(['value' => 1]));
    }

    public function test_higher_is_better_flags(): void
    {
        $this->assertTrue(M::higherIsBetter('earnings_per_skill'));
        $this->assertFalse(M::higherIsBetter('ranking_per_skill'));
        $this->assertFalse(M::higherIsBetter('trending_skills'));
    }

    public function test_delta_direction_and_number(): void
    {
        // earnings: higher is better, went up
        $up = M::delta('earnings_per_skill', 1200.0, 1000.0);
        $this->assertSame('up', $up['direction']);
        $this->assertSame('$200', $up['number']);

        // ranking: lower percentile is better, 20% -> 12% is an improvement
        $rank = M::delta('ranking_per_skill', 12.0, 20.0);
        $this->assertSame('up', $rank['direction']);
        $this->assertSame('8 pts', $rank['number']);

        // trending: position 5 -> position 2 is moving up the list
        $tr = M::delta('trending_skills', 2.0, 5.0);
        $this->assertSame('up', $tr['direction']);
        $this->assertSame('3 places', $tr['number']);

        // no prior -> even, null number
        $none = M::delta('rating_per_skill', 5.0, null);
        $this->assertSame('even', $none['direction']);
        $this->assertNull($none['number']);

        // exactly equal -> even with a formatted zero
        $eq = M::delta('rating_per_skill', 5.0, 5.0);
        $this->assertSame('even', $eq['direction']);
        $this->assertSame('0.0', $eq['number']);
    }
}
