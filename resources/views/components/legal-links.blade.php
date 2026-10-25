<p {{ $attributes->merge(['class' => 'text-xs text-zinc-500 dark:text-zinc-400']) }}>
    <a href="{{ route('legal.terms') }}" class="underline" target="_blank">{{ __('Terms') }}</a> ·
    <a href="{{ route('legal.privacy') }}" class="underline" target="_blank">{{ __('Privacy Policy') }}</a> ·
    <a href="{{ route('legal.ai') }}" class="underline" target="_blank">{{ __('About AI contacts') }}</a>
</p>
