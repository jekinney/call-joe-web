<?php

use App\Models\User;

// The release number: the VERSION file, matching the git tag (v + VERSION).

it('has a numeric dotted version', function () {
    expect(config('app.version'))->toMatch('/^\d+(\.\d+){2,3}$/')
        ->and(trim(file_get_contents(base_path('VERSION'))))->toBe(config('app.version'));
});

it('shows the version in the admin sidebar', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.home'))
        ->assertOk()->assertSee('Version '.config('app.version'));
});
