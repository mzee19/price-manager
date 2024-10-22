<?php

namespace Tests\Feature\Connectors;

use App\Jobs\SendEmailJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

final class BookingControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    public string $baseUri = 'api/v1';

    public function should_be_return_user_with_jobs(): void
    {
        $user = User::factory()->create(['customer'=> true]);

        $response = $this->getJson($this->baseUri . '/booking?user_id=' . $user->id);

        $response->assertSuccessful();

        $response->assertJsonStructure([
            'emergencyJobs',
            'normalJobs',
            'cuser',
            'usertype',
        ]);
    }

    public function email_should_be_send_after_update_job(): void
    {
        $job = Job::factory()
            ->has(User::factory()->create())
            ->create(['due', Carbon::tomorrow(), 'from_language_id' => $this->faker->int(3)]);

        Bus::fake();

        $response = $this->putJson($this->baseUri . '/booking/' . $job->id . 'update', $job->toArray());

        $response->assertSuccessful();

        $response->assertNoContent();

        Bus::assertDispatched(SendEmailJob::class, function ($job) {
            return $job->data['message'] === 'Hello';
        });
    }
}
