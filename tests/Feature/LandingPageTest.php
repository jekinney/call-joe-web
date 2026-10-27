<?php

use App\Models\Contact;
use App\Models\User;

// The front page: no Laravel leftovers, honest about AI and adults, and only shows what is really set up.

it('is the Call Joe front page, not the Laravel default', function () {
    $this->get('/')->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee('Friends who pick up when you call.')
        ->assertSee('Adults 18+')
        ->assertSee('Every friend here is an AI')
        ->assertDontSee('Laravel');
});

it('sends visitors to sign up and log in, and members to their phone', function () {
    $this->get('/')->assertSee(route('register'))->assertSee(route('login'));

    $this->actingAs(User::factory()->create())->get('/')->assertSee(route('dashboard'))->assertDontSee('Start free');
});

it('shows published friends only', function () {
    Contact::factory()->published()->create(['name' => 'Joe Tucker', 'tagline' => 'Kansas storyteller']);
    Contact::factory()->create(['name' => 'Hidden Draft', 'tagline' => 'Not ready']);

    $this->get('/')->assertSee('Joe Tucker')->assertSee('Kansas storyteller')->assertDontSee('Hidden Draft');
});

it('lists the plans with their real prices and promises nothing unbuilt', function () {
    $this->get('/')->assertSee('Free')->assertSee('Paid')->assertSee('$14.99')
        ->assertSee('Voicemail')
        ->assertDontSee('Rated R')
        ->assertDontSee('Short videos');
});

it('links the legal pages', function () {
    $this->get('/')->assertSee(route('legal.terms'))->assertSee(route('legal.privacy'))->assertSee(route('legal.ai'));
});
