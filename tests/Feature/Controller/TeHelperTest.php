<?php

namespace Tests\Unit;

use DTApi\Helpers\TeHelper;
use DTApi\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeHelperTest extends TestCase
{
    use RefreshDatabase;

    public function it_can_calculate_expiry_time_within_90_hours()
    {
        $due_time = now()->addHours(50)->format('Y-m-d H:i:s');
        $created_at = now()->format('Y-m-d H:i:s');

        $expiry_time = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals(now()->addMinutes(90)->format('Y-m-d H:i:s'), $expiry_time);
    }

    public function it_can_calculate_expiry_time_between_24_and_72_hours()
    {
        $due_time = now()->addHours(30)->format('Y-m-d H:i:s');
        $created_at = now()->format('Y-m-d H:i:s');

        $expiry_time = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals(now()->addHours(16)->format('Y-m-d H:i:s'), $expiry_time);
    }
}
