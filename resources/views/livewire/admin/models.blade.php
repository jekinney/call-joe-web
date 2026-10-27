<?php

use Illuminate\Support\Facades\Gate;
use App\Enums\LlmProvider;
use App\Enums\LlmPurpose;
use App\Exceptions\LlmException;
use App\Models\Llm;
use App\Models\LlmCall;
use App\Services\LlmGateway;
use App\Services\LlmProviders;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin'), Title('AI models')] class extends Component {
    /** The model being edited; null while adding a new one. */
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $provider = 'anthropic';
    public string $model_id = '';
    public string $label = '';
    public string $purpose = 'chat';
    public string $plan_tier = '';
    public string $fallback_id = '';
    public bool $fast_thinking = false;
    public string $input_cents_per_mtok = '0';
    public string $output_cents_per_mtok = '0';
    public int $max_tokens = 1024;
    public int $sort = 0;

    /** @var array<int, array{ok: bool, text: string}> Test results, by model id. */
    public array $tests = [];

    public function create(): void
    {
        Gate::authorize('models.manage');
        $this->reset(['editingId', 'model_id', 'label', 'plan_tier', 'fallback_id', 'fast_thinking', 'input_cents_per_mtok', 'output_cents_per_mtok', 'max_tokens', 'sort']);
        $this->purpose = 'chat';
        $this->provider = 'anthropic';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('models.manage');
        $model = Llm::findOrFail($id);
        $this->editingId = $model->id;
        $this->provider = $model->provider->value;
        $this->model_id = $model->model_id;
        $this->label = $model->label;
        $this->purpose = $model->purpose->value;
        $this->plan_tier = (string) $model->plan_tier;
        $this->fallback_id = (string) $model->fallback_id;
        $this->fast_thinking = $model->fast_thinking;
        $this->input_cents_per_mtok = (string) $model->input_cents_per_mtok;
        $this->output_cents_per_mtok = (string) $model->output_cents_per_mtok;
        $this->max_tokens = $model->max_tokens;
        $this->sort = $model->sort;
        $this->showForm = true;
    }

    public function save(LlmGateway $gateway): void
    {
        Gate::authorize('models.manage');
        $data = $this->validate([
            'provider' => ['required', Rule::enum(LlmProvider::class)],
            'model_id' => ['required', 'string', 'max:120'],
            'label' => ['required', 'string', 'max:80'],
            'purpose' => ['required', Rule::enum(LlmPurpose::class)],
            'plan_tier' => ['nullable', Rule::in(['small', 'large', Llm::ASSIGNED])],
            'fallback_id' => ['nullable', 'exists:llm_models,id', Rule::notIn([(string) $this->editingId])],
            'fast_thinking' => ['boolean'],
            'input_cents_per_mtok' => ['required', 'numeric', 'min:0', 'max:100000'],
            'output_cents_per_mtok' => ['required', 'numeric', 'min:0', 'max:100000'],
            'max_tokens' => ['required', 'integer', 'min:16', 'max:32000'],
            'sort' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
        $data['plan_tier'] = $data['plan_tier'] ?: null;
        $data['fallback_id'] = $data['fallback_id'] ?: null;

        Llm::updateOrCreate(['id' => $this->editingId], $data);
        $gateway->forgetModels();
        $this->showForm = false;
        $this->dispatch('model-saved');
    }

    public function toggle(int $id, LlmGateway $gateway): void
    {
        Gate::authorize('models.manage');
        $model = Llm::findOrFail($id);
        $model->update(['is_active' => ! $model->is_active]);
        $gateway->forgetModels();
    }

    /** Sends a short test message to this model alone (no fallback, so a problem shows), and shows the answer. */
    public function test(int $id, LlmGateway $gateway): void
    {
        Gate::authorize('models.manage');
        $model = Llm::findOrFail($id);
        $started = microtime(true);

        try {
            $reply = $gateway->ask($model, $model->purpose, [['role' => 'user', 'content' => 'Say hello in one short, friendly sentence.']], auth()->user(), withFallback: false);
            $this->tests[$id] = ['ok' => true, 'text' => '“'.$reply->text.'” from '.$reply->model.' in '.round((microtime(true) - $started) * 1000).' ms'];
        } catch (LlmException $e) {
            $this->tests[$id] = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LlmProviders $providers): array
    {
        $usage = LlmCall::where('created_at', '>=', now()->subDays(7))
            ->groupBy('llm_model_id')
            ->select('llm_model_id', DB::raw('count(*) as calls'), DB::raw('sum(prompt_tokens + completion_tokens) as tokens'),
                DB::raw('sum(cost_micros) as cost'), DB::raw("sum(case when status = 'error' then 1 else 0 end) as errors"))
            ->get()->keyBy('llm_model_id');
        $chosen = LlmProvider::tryFrom($this->provider) ?? LlmProvider::Anthropic;

        return [
            'groups' => Llm::with('fallback')->orderBy('sort')->get()->groupBy(fn (Llm $m) => $m->purpose->value),
            'all' => Llm::orderBy('sort')->get(),
            'usage' => $usage,
            'purposes' => LlmPurpose::cases(),
            'providerList' => LlmProvider::cases(),
            'chosen' => $chosen,
            'suggestions' => $this->showForm ? $providers->modelIds($chosen) : [],
        ];
    }
}; ?>

<div class="flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('AI models') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Which model does each job. A contact\'s texts use the brain chosen for them in /admin/contacts (any model under Texting); "Any plan" models are the default for contacts without one, and "Assigned" models are only used when a contact is given one or another model fails. Changes apply within a minute.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add model') }}</flux:button>
    </div>

    <div class="flex flex-wrap gap-2" data-providers>
        @foreach ($providerList as $p)
            <flux:badge :color="$p->hasKey() ? 'emerald' : 'zinc'" title="{{ $p->envKey() }}">
                {{ $p->label() }}: {{ $p->hasKey() ? __('key set') : __('no key (:name)', ['name' => $p->envKey()]) }}
            </flux:badge>
        @endforeach
    </div>

    @if ($showForm)
        <form wire:submit="save" class="grid gap-4 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 dark:border-zinc-700">
            <flux:heading class="sm:col-span-2">{{ $editingId ? __('Edit model') : __('New model') }}</flux:heading>
            <flux:select wire:model.live="provider" :label="__('Provider')">
                @foreach ($providerList as $p)
                    <flux:select.option :value="$p->value">{{ $p->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div>
                <flux:input wire:model="model_id" list="model-suggestions" :label="__('Model id')" :placeholder="$chosen->exampleModel()" />
                <datalist id="model-suggestions">
                    @foreach ($suggestions as $suggestion)
                        <option value="{{ $suggestion }}"></option>
                    @endforeach
                </datalist>
                @if ($suggestions !== [])
                    <flux:text size="sm" class="mt-1">{{ __(':count models to pick from: start typing.', ['count' => count($suggestions)]) }}</flux:text>
                @endif
            </div>
            <flux:input wire:model="label" :label="__('Name shown here')" />
            <flux:select wire:model="purpose" :label="__('Used for')">
                @foreach ($purposes as $p)
                    <flux:select.option :value="$p->value">{{ __($p->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="plan_tier" :label="__('Plan tier')">
                <flux:select.option value="">{{ __('Any plan') }}</flux:select.option>
                <flux:select.option value="small">{{ __('Small (Free)') }}</flux:select.option>
                <flux:select.option value="large">{{ __('Large (Paid)') }}</flux:select.option>
                <flux:select.option value="assigned">{{ __('Assigned (a contact\'s brain, or a fallback)') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model="fallback_id" :label="__('If it fails, use')">
                <flux:select.option value="">{{ __('Nothing (show an error)') }}</flux:select.option>
                @foreach ($all as $option)
                    @if ($option->id !== $editingId)
                        <flux:select.option :value="$option->id">{{ $option->label }} ({{ $option->model_id }})</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:input wire:model="max_tokens" type="number" :label="__('Longest answer (tokens)')" />
            <flux:input wire:model="sort" type="number" :label="__('Order')" />
            <flux:input wire:model="input_cents_per_mtok" type="number" step="0.01" :label="__('Input price (cents per million tokens)')" />
            <flux:input wire:model="output_cents_per_mtok" type="number" step="0.01" :label="__('Output price (cents per million tokens)')" />
            <div class="sm:col-span-2">
                <flux:checkbox wire:model="fast_thinking" :label="__('Answer fast')"
                    :description="__('Claude: low effort. Gemini: no thinking (a few models refuse that, and say so when you Test). Leave off for slow, careful jobs like memory.')" />
            </div>
            <div class="flex items-end justify-end gap-2 sm:col-span-2">
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @endif

    @foreach ($purposes as $p)
        <section class="flex flex-col gap-2" wire:key="purpose-{{ $p->value }}">
            <flux:heading size="lg">{{ __($p->label()) }}</flux:heading>
            @forelse ($groups[$p->value] ?? [] as $model)
                @php($u = $usage[$model->id] ?? null)
                <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="model-{{ $model->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <span class="font-medium">{{ $model->label }}</span>
                            <span class="font-mono text-xs text-zinc-500">{{ $model->model_id }}</span>
                            <flux:badge size="sm">{{ $model->provider->shortName() }}</flux:badge>
                            <flux:badge size="sm">{{ $model->plan_tier ? __(ucfirst($model->plan_tier)) : __('Any plan') }}</flux:badge>
                            @if ($model->fast_thinking)
                                <flux:badge size="sm" color="sky">{{ __('Fast') }}</flux:badge>
                            @endif
                            @if (! $model->is_active)
                                <flux:badge size="sm" color="red">{{ __('Off') }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <flux:button size="sm" wire:click="test({{ $model->id }})">{{ __('Test') }}</flux:button>
                            <flux:button size="sm" wire:click="edit({{ $model->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" wire:click="toggle({{ $model->id }})">{{ $model->is_active ? __('Turn off') : __('Turn on') }}</flux:button>
                        </div>
                    </div>
                    <flux:text size="sm">
                        {{ __('If it fails: :f', ['f' => $model->fallback?->label ?? __('nothing')]) }}
                        · {{ __('Last 7 days: :calls calls, :tokens tokens, $:cost, :errors errors', [
                            'calls' => $u->calls ?? 0, 'tokens' => number_format((int) ($u->tokens ?? 0)),
                            'cost' => number_format(((int) ($u->cost ?? 0)) / 1_000_000, 4), 'errors' => $u->errors ?? 0,
                        ]) }}
                    </flux:text>
                    @isset($tests[$model->id])
                        <flux:text size="sm" @class(['text-emerald-600' => $tests[$model->id]['ok'], 'text-red-600' => ! $tests[$model->id]['ok']])>{{ $tests[$model->id]['text'] }}</flux:text>
                    @endisset
                </div>
            @empty
                <flux:text size="sm">{{ __('No models yet.') }}</flux:text>
            @endforelse
        </section>
    @endforeach
</div>
