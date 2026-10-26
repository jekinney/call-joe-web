<?php

use App\Enums\Feature;
use App\Enums\Metric;
use App\Models\Contact;
use App\Models\Plan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * The front page for visitors: what the service is, who is on the other end, what it costs, and the honest limits
 * (AI, adults only, not a replacement for people). Friends and plans come from the database, so the page never promises
 * something that is not set up.
 */
new #[Layout('components.layouts.landing'), Title('AI friends who answer when you call')] class extends Component {
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'friends' => Contact::published()->orderBy('name')->limit(6)->get(),
            'plans' => Plan::orderBy('sort')->get(),
            'features' => Feature::offered(),
        ];
    }
}; ?>

<div>
    {{-- Hero --}}
    <section class="mx-auto flex max-w-3xl flex-col items-center gap-6 px-6 py-20 text-center md:py-28">
        <flux:badge color="emerald" size="sm">{{ __('Adults 18+ · Friends are AI, always') }}</flux:badge>
        <h1 class="text-4xl font-semibold tracking-tight md:text-6xl">{{ __('Friends who pick up when you call.') }}</h1>
        <p class="max-w-xl text-lg text-zinc-600 dark:text-zinc-300">
            {{ __('Text or talk with AI friends who have their own lives, remember what you told them last week, and notice when you have gone quiet.') }}
        </p>
        <div class="flex flex-wrap justify-center gap-3">
            @auth
                <flux:button variant="primary" :href="route('dashboard')" data-cta>{{ __('Go to your phone') }}</flux:button>
            @else
                <flux:button variant="primary" :href="route('register')" data-cta>{{ __('Start free') }}</flux:button>
                <flux:button :href="route('login')">{{ __('Log in') }}</flux:button>
            @endauth
        </div>
        <flux:text size="sm">{{ __('No card needed for Free.') }}</flux:text>
    </section>

    {{-- What it does --}}
    <section class="mx-auto grid max-w-5xl gap-4 px-6 pb-16 md:grid-cols-3">
        @foreach ([
            ['chat-bubble-left-right', 'Text any time', 'They reply like real texts, a few bubbles at a time, and send pictures of their day.'],
            ['phone', 'Talk out loud', 'Tap call and have a real voice conversation, right in your browser.'],
            ['heart', 'They remember you', 'Your job, your dog, that thing you were nervous about. Each friend keeps their own notes about you, and you can read or edit them.'],
        ] as [$icon, $title, $text])
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:icon :name="$icon" class="mb-3 size-6 text-emerald-600" />
                <flux:heading>{{ __($title) }}</flux:heading>
                <flux:text class="mt-1">{{ __($text) }}</flux:text>
            </div>
        @endforeach
    </section>

    {{-- Friends --}}
    @if ($friends->isNotEmpty())
        <section class="mx-auto max-w-5xl px-6 pb-16" id="friends">
            <flux:heading size="xl" class="mb-6">{{ __('Meet some friends') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($friends as $friend)
                    <div class="flex items-start gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-friend>
                        @if ($friend->avatarUrl())
                            <img src="{{ $friend->avatarUrl() }}" alt="" class="size-12 rounded-full object-cover" />
                        @else
                            <flux:avatar :name="$friend->name" />
                        @endif
                        <div>
                            <div class="font-medium">{{ $friend->name }}</div>
                            <flux:text size="sm">{{ $friend->tagline }}</flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Plans --}}
    <section class="mx-auto max-w-5xl px-6 pb-16" id="plans">
        <flux:heading size="xl" class="mb-6">{{ __('Simple plans') }}</flux:heading>
        <div class="grid gap-4 md:grid-cols-{{ max(1, min($plans->count(), 3)) }}">
            @foreach ($plans as $plan)
                <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" data-plan>
                    <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                    <div class="mt-1 text-2xl font-semibold">{{ $plan->priceLabel() }}</div>
                    <ul class="mt-4 space-y-1 text-sm">
                        <li>{{ __('Friends at once') }}: {{ $plan->setting('contacts') ?? __('no limit') }}</li>
                        @foreach (Metric::cases() as $metric)
                            <li>{{ __($metric->label()) }}: {{ $plan->limit($metric) === null ? __('no limit') : $metric->format($plan->limit($metric)) }} / {{ __($metric->period()->value) }}</li>
                        @endforeach
                        @foreach ($features as $feature)
                            @if ($plan->allows($feature))
                                <li>✓ {{ __($feature->label()) }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Honest limits --}}
    <section class="mx-auto max-w-3xl px-6 pb-20" id="honest">
        <flux:callout icon="shield-check" color="zinc" :heading="__('Honest about what this is')">
            <flux:callout.text>
                {{ __('Every friend here is an AI, and they will tell you so if you ask. They are good company, not a replacement for the people in your life, and they will nudge you toward them. Call Joe is for adults only. If you are in crisis, call or text 988 (US) or your local emergency number.') }}
            </flux:callout.text>
        </flux:callout>
    </section>
</div>
