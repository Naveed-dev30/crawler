<?php

namespace Tests\Feature;

use App\Services\FreelancerProfileClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreelancerProfileClientTest extends TestCase
{
    public function test_parses_profiles_keyed_by_id(): void
    {
        Http::fake([
            '*/api/users/0.1/profiles*' => Http::response([
                'status' => 'success',
                'result' => ['profiles' => [
                    '101' => ['id' => 101, 'title' => 'Web Development'],
                    '202' => ['id' => 202, 'name' => 'Mobile Apps'],
                ]],
            ], 200),
        ]);

        $out = (new FreelancerProfileClient)->fetch();

        $this->assertEqualsCanonicalizing(
            [['id' => 101, 'title' => 'Web Development'], ['id' => 202, 'title' => 'Mobile Apps']],
            $out
        );
    }

    public function test_parses_real_freelancer_shape_with_profile_name(): void
    {
        // Real Freelancer payload: the title is under `profile_name`.
        Http::fake([
            '*/api/users/0.1/profiles*' => Http::response([
                'status' => 'success',
                'result' => ['profiles' => [
                    ['id' => 137284, 'user_id' => 55555, 'profile_name' => 'General', 'tagline' => '', 'is_default' => true],
                ]],
            ], 200),
        ]);

        $this->assertSame([['id' => 137284, 'title' => 'General']], (new FreelancerProfileClient)->fetch());
    }

    public function test_parses_profiles_as_list(): void
    {
        Http::fake([
            '*/api/users/0.1/profiles*' => Http::response([
                'status' => 'success',
                'result' => ['profiles' => [['id' => 5, 'headline' => 'SEO Expert']]],
            ], 200),
        ]);

        $this->assertSame([['id' => 5, 'title' => 'SEO Expert']], (new FreelancerProfileClient)->fetch());
    }

    public function test_http_error_returns_empty(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->assertSame([], (new FreelancerProfileClient)->fetch());
    }

    public function test_sends_oauth_header_and_user_id(): void
    {
        config(['variables.flKey' => 'TESTTOKEN', 'variables.flUserId' => 4242]);
        Http::fake(['*' => Http::response(['status' => 'success', 'result' => ['profiles' => []]], 200)]);

        (new FreelancerProfileClient)->fetch();

        Http::assertSent(function ($request) {
            return $request->hasHeader('freelancer-oauth-v1', 'TESTTOKEN')
                && str_contains($request->url(), 'user_id=4242')
                && str_contains($request->url(), '/api/users/0.1/profiles');
        });
    }
}
