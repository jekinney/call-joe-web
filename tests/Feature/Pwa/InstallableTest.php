<?php

use App\Models\User;

// The installable app: manifest, icons, service worker, offline page.

it('has a valid manifest that opens the app standalone', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray(['name' => 'Call Joe', 'start_url' => '/app', 'scope' => '/', 'display' => 'standalone'])
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('links the manifest and icons on every page', function () {
    $this->get('/login')
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest" />', false)
        ->assertSee('apple-touch-icon')
        ->assertSee('<meta name="theme-color" content="#059669" />', false);

    expect(file_exists(public_path('icons/apple-touch-icon.png')))->toBeTrue();
});

it('has a service worker that never caches personal pages', function () {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("const OFFLINE_URL = '/offline'")
        ->and($sw)->toContain("request.mode === 'navigate'")
        ->and($sw)->toContain("url.pathname.startsWith('/build/')")
        ->and($sw)->not->toContain('/app/');
});

it('shows a self-contained offline page to anyone', function () {
    $this->get(route('offline'))
        ->assertOk()
        ->assertSee('You&#039;re offline', false)
        ->assertSee('Try again')
        ->assertDontSee('/build/'); // no build files: it must work with no connection
});

it('offers an install button that stays hidden until the browser allows it', function () {
    $this->actingAs(User::factory()->create())->get('/app')->assertSeeHtml('data-install-app hidden');
});
