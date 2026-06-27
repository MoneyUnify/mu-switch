<?php

use App\Models\ApiLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeLog(int $userId, int $status, array $attrs = []): ApiLog
{
    $log = ApiLog::create([
        'user_id' => $userId,
        'method' => 'POST',
        'url' => 'http://localhost/api/v1/payment/request',
        'route' => 'requestPayment',
        'response_status' => $status,
        'duration_ms' => 12,
        ...$attrs,
    ]);

    // created_at is not mass-assignable, so apply it explicitly when provided.
    if (isset($attrs['created_at'])) {
        $log->forceFill(['created_at' => $attrs['created_at']])->save();
    }

    return $log;
}

test('guests cannot view the logs page', function () {
    $this->get(route('logs.index'))->assertRedirect(route('login'));
});

test('the logs page shows a status summary and paginated rows scoped to the user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    makeLog($user->id, 200);
    makeLog($user->id, 200);
    makeLog($user->id, 422);
    makeLog($user->id, 500);
    makeLog($other->id, 200); // must not be counted or listed

    $this->actingAs($user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('logs/index')
            ->where('stats.total', 4)
            ->where('stats.success', 2)
            ->where('stats.clientError', 1)
            ->where('stats.serverError', 1)
            ->has('logs.data', 4)
            ->where('filters.status', null)
            // The timestamp must carry a timezone abbreviation (e.g. "... 14:19:21 UTC").
            ->where('logs.data.0.createdAt', fn (string $v) => (bool) preg_match('/\d{2}:\d{2}:\d{2} [A-Za-z]{2,5}$/', $v))
        );
});

test('logs can be filtered by status class', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200);
    makeLog($user->id, 500);
    makeLog($user->id, 503);

    $this->actingAs($user)
        ->get(route('logs.index', ['status' => 'server']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'server')
            ->has('logs.data', 2)
            ->where('stats.total', 3)
        );
});

test('logs can be searched by IP address', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200, ['ip_address' => '10.0.0.5']);
    makeLog($user->id, 200, ['ip_address' => '10.0.0.9']);

    $this->actingAs($user)
        ->get(route('logs.index', ['field' => 'ip', 'q' => '10.0.0.5']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.ipAddress', '10.0.0.5')
            ->where('filters.q', '10.0.0.5')
            ->where('filters.field', 'ip')
        );
});

test('logs can be searched by request body, headers and response body', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200, ['request_body' => ['account_number' => '0977000111']]);
    makeLog($user->id, 200, ['response_body' => json_encode(['reference' => 'REF-ABCDEF'])]);
    makeLog($user->id, 200, ['request_headers' => ['X-Trace' => ['trace-xyz']]]);
    makeLog($user->id, 200, ['request_body' => ['account_number' => '0966999888']]);

    // Dedicated payload field: request body.
    $this->actingAs($user)
        ->get(route('logs.index', ['field' => 'payload', 'q' => '0977000111']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.field', 'payload'));

    // Dedicated payload field: response body.
    $this->actingAs($user)
        ->get(route('logs.index', ['field' => 'payload', 'q' => 'REF-ABCDEF']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));

    // Dedicated payload field: request headers.
    $this->actingAs($user)
        ->get(route('logs.index', ['field' => 'payload', 'q' => 'trace-xyz']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));

    // The default "all" search also reaches into the payload.
    $this->actingAs($user)
        ->get(route('logs.index', ['q' => 'REF-ABCDEF']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
});

test('logs can be filtered to today versus yesterday', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200); // created now (today)
    makeLog($user->id, 200, ['created_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('logs.index', ['range' => 'today']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.range', 'today'));

    $this->actingAs($user)
        ->get(route('logs.index', ['range' => 'yesterday']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.range', 'yesterday'));
});

test('a custom date range filters logs and builds the chart without error', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200, ['created_at' => now()->setDay(15)]);
    makeLog($user->id, 500, ['created_at' => now()->subMonths(2)]); // outside the window

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $this->actingAs($user)
        ->get(route('logs.index', ['range' => 'custom', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.range', 'custom')
            ->where('filters.from', $from)
            ->where('filters.to', $to)
            ->has('logs.data', 1)
            ->has('chart.points')
        );
});

test('the logs view exposes a status-code chart series', function () {
    $user = User::factory()->create();
    makeLog($user->id, 200);
    makeLog($user->id, 500);

    $this->actingAs($user)
        ->get(route('logs.index', ['range' => 'today']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('chart.points')
            ->where('chart.grain', 'hour')
            ->has('chart.points.0.s2xx')
            ->has('chart.points.0.s5xx')
            ->has('fieldOptions')
        );
});

test('logs are paginated at 5 per page by default', function () {
    $user = User::factory()->create();
    foreach (range(1, 20) as $i) {
        makeLog($user->id, 200);
    }

    $this->actingAs($user)
        ->get(route('logs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 5)
            ->where('logs.total', 20)
            ->where('logs.last_page', 4)
            ->where('filters.perPage', 5)
            ->where('perPageOptions', [5, 10, 15, 30, 50, 100])
        );
});

test('the page size can be changed via the perPage filter', function () {
    $user = User::factory()->create();
    foreach (range(1, 60) as $i) {
        makeLog($user->id, 200);
    }

    $this->actingAs($user)
        ->get(route('logs.index', ['perPage' => 50]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 50)
            ->where('logs.total', 60)
            ->where('logs.last_page', 2)
            ->where('filters.perPage', 50)
        );
});

test('an unsupported perPage value falls back to the default page size', function () {
    $user = User::factory()->create();
    foreach (range(1, 20) as $i) {
        makeLog($user->id, 200);
    }

    $this->actingAs($user)
        ->get(route('logs.index', ['perPage' => 999]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 5)
            ->where('filters.perPage', 5)
        );
});
