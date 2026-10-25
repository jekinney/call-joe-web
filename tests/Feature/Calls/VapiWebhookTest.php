<?php

use App\Models\Call;
use App\Models\Contact;
use App\Models\User;
use App\Services\Calling;
use App\Services\Phonebook;

// POST /webhooks/vapi: only Vapi (with our secret) may report on calls.

beforeEach(function () {
    config(['services.vapi.webhook_secret' => 'shh']);
    $user = User::factory()->create();
    $entry = app(Phonebook::class)->add($user, Contact::factory()->published()->create());
    $this->call = app(Calling::class)->start($user, $entry);
});

function vapiReports(array $message, ?string $secret = 'shh')
{
    return test()->postJson(route('webhooks.vapi'), ['message' => $message], $secret === null ? [] : ['X-Call-Joe-Secret' => $secret]);
}

it('refuses reports without the right secret', function (?string $secret) {
    vapiReports(['type' => 'end-of-call-report', 'call' => ['assistantId' => $this->call->vapi_assistant_id]], $secret)->assertUnauthorized();

    expect($this->call->refresh()->duration_seconds)->toBeNull();
})->with(['missing' => [null], 'wrong' => ['nope']]);

it('refuses everything while no secret is configured', function () {
    config(['services.vapi.webhook_secret' => '']);

    vapiReports(['type' => 'status-update'], '')->assertUnauthorized();
});

it('records an end-of-call report, without needing a CSRF token or a session', function () {
    vapiReports(['type' => 'end-of-call-report', 'call' => ['assistantId' => $this->call->vapi_assistant_id], 'durationSeconds' => 61])
        ->assertOk()->assertJson(['ok' => true, 'handled' => true]);

    expect($this->call->refresh()->duration_seconds)->toBe(61);
});

it('passes status updates on', function () {
    vapiReports(['type' => 'status-update', 'status' => 'in-progress', 'call' => ['assistantId' => $this->call->vapi_assistant_id]])->assertJson(['handled' => true]);

    expect($this->call->refresh()->status)->toBe(Call::ACTIVE);
});

it('accepts other kinds of messages without doing anything', function () {
    vapiReports(['type' => 'transcript'])->assertOk()->assertJson(['handled' => false]);
    vapiReports(['type' => 'end-of-call-report', 'call' => ['assistantId' => 'not-ours']])->assertJson(['handled' => false]);
});
