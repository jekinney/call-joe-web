<?php

use App\Models\Contact;
use App\Models\Llm;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

// Bringing the desktop app's friends (friend.json, persona.md, face.png) into the catalog.

const PNG = "\x89PNG\r\n\x1a\n";

beforeEach(function () {
    Storage::fake('public');
    $this->folder = sys_get_temp_dir().'/desktop-contacts-'.uniqid();
    File::makeDirectory($this->folder);
});

afterEach(fn () => File::deleteDirectory($this->folder));

function desktopFriend(string $id, array $profile, string $persona = "# Who you are\nYou're Rusty.\n\n## Rusty\n- Loves cars.", bool $face = true): void
{
    $dir = test()->folder."/{$id}";
    File::makeDirectory($dir);
    File::put("{$dir}/friend.json", json_encode($profile));
    File::put("{$dir}/persona.md", $persona);
    if ($face) {
        File::put("{$dir}/face.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }
}

it('imports a friend with persona, picture, voice and a Grok brain, as a draft', function () {
    desktopFriend('coop', [
        'name' => 'Rusty Cooper', 'gender' => 'man', 'about' => 'Rusty "Coop" Cooper, a clingy 90s-stuck Iowa dad.',
        'looks' => 'A stocky white man in his forties.', 'support' => false,
    ]);

    $this->artisan('app:import-contacts', ['path' => $this->folder])
        ->expectsOutput('Rusty Cooper: draft')
        ->expectsOutput('Imported 1 friend.')
        ->assertSuccessful();

    $coop = Contact::where('name', 'Rusty Cooper')->sole();
    expect($coop->slug)->toBe('rusty-cooper')
        ->and($coop->gender)->toBe('man')
        ->and($coop->tagline)->toStartWith('Rusty "Coop" Cooper')
        ->and($coop->persona_md)->toContain("You're Rusty.")
        ->and($coop->looks)->toBe('A stocky white man in his forties.')
        ->and($coop->voice)->toBe('Puck')
        ->and($coop->brain->model_id)->toBe('grok-4.20-0309-non-reasoning')
        ->and($coop->is_published)->toBeFalse()
        ->and($coop->versions)->toHaveCount(1);
    Storage::disk('public')->assertExists($coop->avatar_path);
});

it('leaves out the desktop app\'s phone and safety boilerplate, which this app builds itself', function () {
    desktopFriend('coop', ['name' => 'Rusty Cooper', 'gender' => 'man'],
        "# Who you are\nYou're Rusty.\n\n## How you talk on the phone\n- Never use emojis.\n\n## Being a real friend\n- Call 988.");

    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();

    expect(Contact::sole()->persona_md)->toBe("# Who you are\nYou're Rusty.")->not->toContain('emojis')->not->toContain('988');
});

it('takes the "kept on this computer only" note out of the tagline and keeps it within 160 characters', function () {
    desktopFriend('savannah', ['name' => 'Savannah Montgomery', 'gender' => 'woman', 'about' => 'A Georgia attorney. Personal: kept on this computer only.']);
    desktopFriend('fallon', ['name' => 'Fallon', 'gender' => 'woman']);
    desktopFriend('long', ['name' => 'Long Winded', 'gender' => 'man', 'about' => str_repeat('word ', 60)]);

    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();

    expect(Contact::where('name', 'Savannah Montgomery')->sole()->tagline)->toBe('A Georgia attorney.')
        ->and(Contact::where('name', 'Savannah Montgomery')->sole()->voice)->toBe('Gacrux') // her chosen voice
        ->and(Contact::where('name', 'Fallon')->sole()->voice)->toBe('Kore') // no chosen voice: by gender
        ->and(mb_strlen(Contact::where('name', 'Long Winded')->sole()->tagline))->toBeLessThanOrEqual(160)
        ->and(Contact::where('name', 'Long Winded')->sole()->voice)->toBe('Fenrir');
});

it('skips support agents, rule folders and folders that are not friends', function () {
    desktopFriend('support-jordan', ['name' => 'Jordan (Support)', 'gender' => 'man', 'support' => true]);
    desktopFriend('broken', ['gender' => 'man']);
    File::makeDirectory($this->folder.'/_roles');
    File::makeDirectory($this->folder.'/empty');
    File::put($this->folder.'/_call-rules.md', 'x');
    File::put($this->folder.'/bad-json-persona.md', 'x');
    File::makeDirectory($this->folder.'/bad');
    File::put($this->folder.'/bad/persona.md', 'x');
    File::put($this->folder.'/bad/friend.json', '{not json');

    $this->artisan('app:import-contacts', ['path' => $this->folder])->expectsOutput('Imported 0 friends.')->assertSuccessful();

    expect(Contact::count())->toBe(0);
});

it('updates a friend that already exists instead of making a second one', function () {
    $existing = Contact::factory()->create(['slug' => 'joe-tucker', 'name' => 'Joe Tucker', 'persona_md' => 'old persona']);
    desktopFriend('joe', ['name' => 'Joe Tucker', 'gender' => 'man', 'about' => 'Joe Dirt'], "# Who you are\nYou're Joe Dirt.");

    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();

    expect(Contact::where('name', 'Joe Tucker')->count())->toBe(1)
        ->and($existing->refresh()->persona_md)->toBe("# Who you are\nYou're Joe Dirt.")
        ->and($existing->voice)->toBe('Charon')
        ->and($existing->version)->toBe(2);

    // Running it again changes nothing (no new version).
    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();
    expect($existing->refresh()->version)->toBe(2);
});

it('brings back a deleted friend of the same name', function () {
    $gone = Contact::factory()->create(['slug' => 'rusty-cooper', 'name' => 'Rusty Cooper']);
    $gone->delete();
    desktopFriend('coop', ['name' => 'Rusty Cooper', 'gender' => 'man']);

    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();

    expect(Contact::sole()->is($gone))->toBeTrue();
});

it('publishes the friends that are ready with --publish, and says what a draft is missing', function () {
    desktopFriend('coop', ['name' => 'Rusty Cooper', 'gender' => 'man']);
    desktopFriend('elena', ['name' => 'Elena Rodriguez', 'gender' => 'woman'], face: false);

    $this->artisan('app:import-contacts', ['path' => $this->folder, '--publish' => true])
        ->expectsOutput('Rusty Cooper: published')
        ->expectsOutput('Elena Rodriguez: draft (Elena Rodriguez still needs a picture.)')
        ->assertSuccessful();

    expect(Contact::where('name', 'Rusty Cooper')->sole()->is_published)->toBeTrue()
        ->and(Contact::where('name', 'Elena Rodriguez')->sole()->is_published)->toBeFalse();
});

it('can give them another brain', function () {
    desktopFriend('coop', ['name' => 'Rusty Cooper', 'gender' => 'man']);
    $haiku = Llm::where('purpose', 'chat')->where('model_id', 'claude-haiku-5-5')->firstOrFail();

    $this->artisan('app:import-contacts', ['path' => $this->folder, '--brain' => 'claude-haiku-5-5'])->assertSuccessful();

    expect(Contact::sole()->brain_id)->toBe($haiku->id);
});

it('stops when the folder or the brain does not exist', function () {
    $this->artisan('app:import-contacts', ['path' => $this->folder.'/nope'])->expectsOutputToContain('No folder at')->assertFailed();

    $this->artisan('app:import-contacts', ['path' => $this->folder, '--brain' => 'no-such-model'])
        ->expectsOutputToContain('No chat model called no-such-model')->assertFailed();
});

it('accepts a face.jpg picture too', function () {
    desktopFriend('fallon', ['name' => 'Fallon', 'gender' => 'woman'], face: false);
    File::copy(public_path('icons/icon-192.png'), $this->folder.'/fallon/face.jpg');

    $this->artisan('app:import-contacts', ['path' => $this->folder])->assertSuccessful();

    Storage::disk('public')->assertExists(Contact::sole()->avatar_path);
});
