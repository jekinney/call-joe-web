<?php

use App\Models\Contact;
use App\Models\Llm;
use App\Models\Plan;
use App\Services\ContactCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.admin'), Title('Contact')] class extends Component {
    use WithFileUploads;

    public ?Contact $contact = null;

    public string $name = '';
    public string $slug = '';
    public string $tagline = '';
    public string $gender = 'woman';
    public string $requiredPlanId = '';
    /** Who writes their texts: chosen when the contact is created, and changed only here (never by users). */
    public string $brainId = '';
    public string $persona_md = '';
    public string $looks = '';
    public string $voiceProvider = '';
    public string $voiceId = '';
    public string $note = '';
    public bool $preview = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $avatar = null;

    public function mount(?Contact $contact = null): void
    {
        if ($contact?->exists) {
            $this->contact = $contact;
            $this->fill($contact->only(['name', 'slug', 'gender', 'persona_md']));
            $this->tagline = $contact->tagline ?? '';
            $this->looks = $contact->looks ?? '';
            $this->voiceProvider = (string) ($contact->voice_settings['provider'] ?? '');
            $this->voiceId = (string) $contact->voice_provider_id;
            $this->requiredPlanId = (string) ($contact->required_plan_id ?? '');
            $this->brainId = (string) ($contact->brain_id ?? '');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'alpha_dash', 'max:60', Rule::unique('contacts', 'slug')->ignore($this->contact?->id)],
            'tagline' => ['nullable', 'string', 'max:160'],
            'gender' => ['required', Rule::in(config('contacts.genders'))],
            'requiredPlanId' => ['nullable', 'exists:plans,id'],
            'brainId' => ['required', Rule::exists('llm_models', 'id')->where('purpose', 'chat')],
            'persona_md' => ['required', 'string', 'max:20000'],
            'looks' => ['nullable', 'string', 'max:2000'],
            'voiceProvider' => ['nullable', 'required_with:voiceId', Rule::in(config('contacts.voice_providers'))],
            'voiceId' => ['nullable', 'required_with:voiceProvider', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function save(ContactCatalog $catalog): void
    {
        Gate::authorize('admin'); // creating a contact, and choosing or changing its brain, is for admins only
        $this->validate();

        $data = [
            'name' => $this->name,
            'slug' => $this->slug !== '' ? $this->slug : $catalog->uniqueSlug($this->name, $this->contact?->id),
            'tagline' => $this->tagline ?: null,
            'gender' => $this->gender,
            'required_plan_id' => $this->requiredPlanId !== '' ? (int) $this->requiredPlanId : null,
            'brain_id' => (int) $this->brainId,
            'persona_md' => $this->persona_md,
            'looks' => $this->looks ?: null,
            'voice_provider_id' => $this->voiceId !== '' ? $this->voiceId : null,
            'voice_settings' => $this->voiceProvider !== '' ? ['provider' => $this->voiceProvider] : null,
        ];

        if (! $this->contact) {
            $contact = $catalog->create($data, auth()->user());
            session()->flash('status', __(':name was created. Add a picture, then publish.', ['name' => $contact->name]));
            $this->redirectRoute('admin.contacts.edit', $contact, navigate: true);

            return;
        }

        $catalog->update($this->contact, $data, auth()->user(), $this->note ?: null);
        $this->slug = $this->contact->slug;
        $this->note = '';
        $this->dispatch('contact-saved');
    }

    public function updatedAvatar(ContactCatalog $catalog): void
    {
        $this->validate(['avatar' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=256,min_height=256']]);

        if ($this->contact) {
            $catalog->setAvatar($this->contact, $this->avatar);
            $this->avatar = null;
            $this->dispatch('avatar-saved');
        }
    }

    public function publish(ContactCatalog $catalog): void
    {
        try {
            $catalog->publish($this->contact);
        } catch (InvalidArgumentException $e) {
            $this->addError('publish', $e->getMessage());
        }
    }

    public function unpublish(ContactCatalog $catalog): void
    {
        $catalog->unpublish($this->contact);
    }

    public function restore(int $version, ContactCatalog $catalog): void
    {
        $catalog->restore($this->contact, $version, auth()->user());
        $this->persona_md = $this->contact->persona_md;
        $this->looks = $this->contact->looks ?? '';
        $this->dispatch('contact-saved');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'plans' => Plan::orderBy('sort')->get(),
            'brains' => Llm::where('purpose', 'chat')->where('is_active', true)->orderBy('sort')->get(),
            'versions' => $this->contact?->versions()->with('author')->limit(30)->get() ?? collect(),
            'rendered' => Str::markdown($this->persona_md, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ];
    }
}; ?>

<div class="flex w-full max-w-4xl flex-col gap-8">
    <div>
        <a href="{{ route('admin.contacts.index') }}" class="text-sm underline-offset-2 hover:underline" wire:navigate>← {{ __('Contacts') }}</a>
        <div class="mt-2 flex items-center gap-3">
            <flux:heading size="xl">{{ $contact ? $contact->name : __('New contact') }}</flux:heading>
            @if ($contact)
                @if ($contact->is_published)
                    <flux:badge color="emerald" size="sm">{{ __('Published') }}</flux:badge>
                @else
                    <flux:badge size="sm">{{ __('Draft') }}</flux:badge>
                @endif
                <flux:badge size="sm">v{{ $contact->version }}</flux:badge>
            @endif
        </div>
        @if (session('status'))
            <flux:text class="mt-2 text-emerald-600">{{ session('status') }}</flux:text>
        @endif
    </div>

    @if ($contact)
        <section class="flex items-center gap-6">
            <flux:avatar size="xl" :src="$contact->avatarUrl()" :name="$contact->name" />
            <div class="flex flex-col gap-2">
                <flux:input type="file" wire:model="avatar" :label="__('Picture')" accept="image/png,image/jpeg,image/webp" :description="__('A made-up face (never a real person without their consent), at least 256×256.')" />
                <x-action-message on="avatar-saved">{{ __('Picture saved.') }}</x-action-message>
            </div>
        </section>
    @endif

    <form wire:submit="save" class="flex flex-col gap-5">
        <div class="grid gap-5 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="slug" :label="__('Slug (web address)')" :placeholder="__('Made from the name')" />
            <flux:input wire:model="tagline" :label="__('Tagline')" :placeholder="__('Retired mechanic from Kansas who loves a good story')" class="sm:col-span-2" />
            <flux:select wire:model="gender" :label="__('Gender')">
                @foreach (config('contacts.genders') as $option)
                    <flux:select.option :value="$option">{{ __(ucfirst($option)) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="requiredPlanId" :label="__('Plan needed to add them')">
                <flux:select.option value="">{{ __('Any plan') }}</flux:select.option>
                @foreach ($plans as $plan)
                    <flux:select.option :value="$plan->id">{{ $plan->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="sm:col-span-2">
                <flux:select wire:model="brainId" :label="__('Brain')" :placeholder="__('Choose who thinks for them')" :description="__('The model that writes their texts, and what it costs per million tokens. Chosen when the contact is created; people who add them can\'t change it. Cheaper Gemini models suit lower-tier contacts.')">
                    @foreach ($brains as $brain)
                        <flux:select.option :value="$brain->id">{{ $brain->label }} · {{ $brain->provider->shortName() }} · ${{ number_format((float) $brain->input_cents_per_mtok / 100, 2) }} in / ${{ number_format((float) $brain->output_cents_per_mtok / 100, 2) }} out</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="brainId" />
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <flux:label>{{ __('Persona (who they are, in Markdown)') }}</flux:label>
                <flux:switch wire:model.live="preview" :label="__('Preview')" />
            </div>
            @if ($preview)
                <div class="persona-preview rounded-lg border border-zinc-200 p-4 text-sm leading-relaxed dark:border-zinc-700 [&_h1]:mb-2 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:mt-4 [&_h2]:mb-1 [&_h2]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_p]:mb-2">
                    {!! $rendered !!}
                </div>
            @else
                <flux:textarea wire:model="persona_md" rows="18" class="font-mono text-sm" />
            @endif
            <flux:error name="persona_md" />
        </div>

        <flux:textarea wire:model="looks" rows="3" :label="__('Looks')" :description="__('What they look like, always with skin tone and hair, so every picture of them matches.')" />

        <div class="grid gap-5 sm:grid-cols-2">
            <flux:select wire:model="voiceProvider" :label="__('Voice provider')">
                <flux:select.option value="">{{ __('None yet') }}</flux:select.option>
                @foreach (config('contacts.voice_providers') as $provider)
                    <flux:select.option :value="$provider">{{ $provider }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="voiceId" :label="__('Voice id')" :description="__('Only voices you have the rights to use: never clone a real person without their consent.')" />
        </div>

        @if ($contact)
            <flux:input wire:model="note" :label="__('What changed (optional, kept with the version)')" :placeholder="__('Softened the sarcasm')" />
        @endif

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ $contact ? __('Save') : __('Create contact') }}</flux:button>
            <x-action-message on="contact-saved">{{ __('Saved.') }}</x-action-message>
        </div>
    </form>

    @if ($contact)
        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Catalog') }}</flux:heading>
            @if ($contact->is_published)
                <flux:text>{{ __('Published :when. Hiding them keeps them for people who already added them.', ['when' => $contact->published_at?->diffForHumans()]) }}</flux:text>
                <div><flux:button wire:click="unpublish">{{ __('Hide from catalog') }}</flux:button></div>
            @else
                <flux:text>{{ __('Not in the catalog yet.') }}</flux:text>
                <div><flux:button variant="primary" wire:click="publish">{{ __('Publish') }}</flux:button></div>
                <flux:error name="publish" />
            @endif
        </section>

        <section class="flex flex-col gap-2">
            <flux:heading size="lg">{{ __('Version history') }}</flux:heading>
            @foreach ($versions as $version)
                <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2 text-sm dark:border-zinc-700" wire:key="version-{{ $version->id }}">
                    <span>
                        <strong>v{{ $version->version }}</strong>
                        · {{ $version->created_at->toDayDateTimeString() }}
                        · {{ $version->author?->name ?? __('system') }}
                        @if ($version->note) · {{ $version->note }} @endif
                    </span>
                    @if ($version->version !== $contact->version)
                        <flux:button size="sm" wire:click="restore({{ $version->version }})" wire:confirm="{{ __('Bring back version :v? It becomes a new version; nothing is lost.', ['v' => $version->version]) }}">{{ __('Restore') }}</flux:button>
                    @else
                        <flux:badge size="sm" color="emerald">{{ __('Current') }}</flux:badge>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
</div>
