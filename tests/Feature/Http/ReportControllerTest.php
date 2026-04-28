<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    // Allow gate-protected actions for the default-happy-path tests; individual
    // tests opt back into the fail-closed default to assert denial.
    Gate::define('modman.view', fn (): bool => true);
    Gate::define('modman.resolve', fn (): bool => true);
    Gate::define('modman.reopen', fn (): bool => true);
});

it('returns a report with its decisions when authenticated', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->getJson(route('modman.reports.show', $report))
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.state', 'needs_human');
});

it('returns 401 when fetching a report without auth', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $this->getJson(route('modman.reports.show', $report))
        ->assertStatus(401);
});

it('returns 403 when default modman.view gate denies', function (): void {
    Gate::define('modman.view', fn (): bool => false);

    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->getJson(route('modman.reports.show', $report))
        ->assertStatus(403);
});

// Lock the controller -> gate contract: show() must pass the authenticated
// user and the resolved Report to host-defined gates. Without this assertion,
// a refactor that called Gate::allows('modman.view') (no $report arg) or
// Gate::forUser(null) would still pass the happy-path test.
it('passes the authenticated user and the target report to the modman.view gate', function (): void {
    $captured = ['user' => null, 'report' => null];
    Gate::define('modman.view', function (Authenticatable $user, Report $report) use (&$captured): bool {
        $captured['user'] = $user;
        $captured['report'] = $report;

        return true;
    });

    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->getJson(route('modman.reports.show', $report))
        ->assertOk();

    expect($captured['user'])->toBeInstanceOf(TestReportable::class);
    expect($captured['user']->getKey())->toBe($moderator->getKey());
    expect($captured['report'])->toBeInstanceOf(Report::class);
    expect($captured['report']->id)->toBe($report->id);
});

it('returns 403 when authenticated identity is not an Eloquent Model on show', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $identity = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): string
        {
            return 'non-model';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    $this->actingAs($identity)
        ->getJson(route('modman.reports.show', $report))
        ->assertStatus(403);
});

it('resolves a report with an authenticated and authorized actor', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), [
            'decision' => 'approve',
            'reason' => 'false positive',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'resolved_approved');
});

it('returns 401 when resolving without auth', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $this->postJson(route('modman.reports.resolve', $report), ['decision' => 'approve'])
        ->assertStatus(401);
});

it('returns 403 when default modman.resolve gate denies', function (): void {
    Gate::define('modman.resolve', fn (): bool => false);

    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), ['decision' => 'approve'])
        ->assertStatus(403);
});

it('returns 403 when default modman.reopen gate denies', function (): void {
    Gate::define('modman.reopen', fn (): bool => false);

    $report = Report::factory()->create(['state' => 'resolved_approved']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.reopen', $report))
        ->assertStatus(403);
});

it('returns 403 when authenticated identity is not an Eloquent Model', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $identity = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): string
        {
            return 'non-model';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    $this->actingAs($identity)
        ->postJson(route('modman.reports.resolve', $report), ['decision' => 'approve'])
        ->assertStatus(403);
});

it('honors a host override that allows the resolve gate', function (): void {
    Gate::define('modman.resolve', fn (Authenticatable $user, Report $report): bool => true);

    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.state', 'resolved_rejected');
});

it('returns 422 when resolve is missing the decision field', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);
});

it('returns 422 when resolve receives an invalid decision value', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), ['decision' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);
});

it('resolves with reject and writes a rejected state', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), [
            'decision' => 'reject',
            'reason' => 'violates policy',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'resolved_rejected');
});

it('reopens an authenticated and authorized resolved report', function (): void {
    $report = Report::factory()->create([
        'state' => 'resolved_approved',
        'resolved_at' => now(),
    ]);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.reopen', $report), ['reason' => 'appeal'])
        ->assertOk()
        ->assertJsonPath('data.state', 'needs_human')
        ->assertJsonPath('data.resolved_at', null);
});

it('returns 401 when reopening without auth', function (): void {
    $report = Report::factory()->create([
        'state' => 'resolved_approved',
        'resolved_at' => now(),
    ]);

    $this->postJson(route('modman.reports.reopen', $report))
        ->assertStatus(401);
});

it('returns 404 when fetching a non-existent report id', function (): void {
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->getJson(route('modman.reports.show', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))
        ->assertStatus(404);
});

it('persists a 1000-character reason without truncation', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);
    $longReason = str_repeat('a', 1000);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), [
            'decision' => 'approve',
            'reason' => $longReason,
        ])
        ->assertOk();

    $decision = $report->fresh()?->decisions()->first();
    expect($decision?->reason)->toBe($longReason);
    expect(strlen($decision?->reason ?? ''))->toBe(1000);
});

it('emits the morph alias for reportable_type when one is registered', function (): void {
    Relation::morphMap(['test_reportable' => TestReportable::class]);

    try {
        $reportable = TestReportable::create(['body' => 'thing']);
        $report = Report::factory()->create([
            'state' => 'needs_human',
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => (string) $reportable->getKey(),
        ]);
        $moderator = TestReportable::create(['body' => 'mod']);

        $this->actingAs($moderator)
            ->getJson(route('modman.reports.show', $report))
            ->assertOk()
            ->assertJsonPath('data.reportable.type', 'test_reportable');
    } finally {
        // Reset morph map to keep test isolation; passing an empty array clears it.
        Relation::morphMap([], false);
    }
});

it('falls back to the FQCN when no morph alias is registered', function (): void {
    Relation::morphMap([], false);

    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->getJson(route('modman.reports.show', $report))
        ->assertOk()
        ->assertJsonPath('data.reportable.type', $report->reportable_type);
});
