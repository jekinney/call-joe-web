<?php

use Illuminate\Support\Facades\Gate;
use App\Models\Contact;
use App\Models\ImageCacheEntry;
use App\Models\ImageRequest;
use App\Services\PictureCache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Pictures contacts send, and the shared cache (App\Services\PictureCache): how often a picture is reused instead of
 * made, the most reused scenes, and a way to start a contact's cache over.
 */
new #[Layout('components.layouts.admin'), Title('Pictures')] class extends Component {
    public string $purged = '';

    public function purge(int $contactId, PictureCache $cache): void
    {
        Gate::authorize('pictures.manage');
        $contact = Contact::withTrashed()->findOrFail($contactId);
        $count = $cache->purge($contact);
        $this->purged = trans_choice(':name: :count cached picture forgotten.|:name: :count cached pictures forgotten.', $count, ['name' => $contact->name, 'count' => $count]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $recent = ImageRequest::where('created_at', '>=', now()->subDays(30));
        $sent = (clone $recent)->where('status', ImageRequest::READY)->count();
        $reused = (clone $recent)->where('status', ImageRequest::READY)->where('from_cache', true)->count();

        return [
            'sent' => $sent,
            'reused' => $reused,
            'hitRate' => $sent > 0 ? (int) round($reused / $sent * 100) : 0,
            'stopped' => (clone $recent)->whereIn('status', [ImageRequest::BLOCKED, ImageRequest::FAILED])->count(),
            'spent' => (int) (clone $recent)->sum('cost_micros'),
            'top' => ImageCacheEntry::with('contact')
                ->selectRaw('cache_key, contact_id, MIN(scene_signature) AS scene_signature, COUNT(*) AS variants, SUM(hits) AS hits')
                ->groupBy('cache_key', 'contact_id')->orderByDesc('hits')->limit(10)->get(),
            'byContact' => ImageCacheEntry::with('contact')->selectRaw('contact_id, COUNT(*) AS pictures, SUM(hits) AS hits')
                ->groupBy('contact_id')->orderByDesc('pictures')->get(),
        ];
    }
}; ?>

<div class="flex w-full max-w-5xl flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Pictures') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The last 30 days. A reused picture comes from the shared cache instead of the provider.') }}</flux:text>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-5" data-stats>
        @foreach ([__('Sent') => $sent, __('Reused') => $reused, __('Reuse rate') => $hitRate.'%', __('Blocked or failed') => $stopped, __('Paid to Grok') => '$'.number_format($spent / 1_000_000, 2)] as $label => $value)
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text size="sm">{{ $label }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Most reused scenes') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Contact') }}</flux:table.column>
                <flux:table.column>{{ __('Scene') }}</flux:table.column>
                <flux:table.column>{{ __('Variants') }}</flux:table.column>
                <flux:table.column>{{ __('Reused') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($top as $row)
                    <flux:table.row :key="$row->cache_key">
                        <flux:table.cell><a href="{{ route('admin.pictures.contact', $row->contact_id) }}" class="underline-offset-2 hover:underline" wire:navigate>{{ $row->contact->name }}</a></flux:table.cell>
                        <flux:table.cell class="max-w-md whitespace-normal">
                            <div class="flex flex-wrap gap-1" data-scene>
                                @foreach ($row->sceneLabels() as $label => $value)
                                    <flux:badge size="sm" color="zinc"><span class="opacity-60">{{ __($label) }}:</span> {{ $value }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $row->variants }}</flux:table.cell>
                        <flux:table.cell>{{ $row->hits }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">{{ __('Nothing cached yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('By contact') }}</flux:heading>
        @if ($purged)
            <flux:callout icon="check" color="emerald" :heading="$purged" />
        @endif
        @foreach ($byContact as $row)
            <div class="flex items-center justify-between gap-4 border-b border-zinc-200 pb-2 dark:border-zinc-700" wire:key="c-{{ $row->contact_id }}">
                <flux:text>{{ $row->contact->name }} · {{ trans_choice(':count picture|:count pictures', $row->pictures, ['count' => $row->pictures]) }} · {{ __(':count reuses', ['count' => $row->hits]) }}</flux:text>
                <div class="flex gap-2">
                <flux:button size="sm" :href="route('admin.pictures.contact', $row->contact_id)" wire:navigate>{{ __('View pictures') }}</flux:button>
                <flux:button size="sm" variant="danger" wire:click="purge({{ $row->contact_id }})"
                    wire:confirm="{{ __('Forget :name\'s cached pictures? New ones are made next time. Pictures already sent stay in their threads.', ['name' => $row->contact->name]) }}">{{ __('Start over') }}</flux:button>
                </div>
            </div>
        @endforeach
    </section>
</div>
