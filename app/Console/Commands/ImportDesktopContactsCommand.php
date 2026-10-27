<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Llm;
use App\Services\ContactCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Brings the friends built in the desktop app (a folder per friend: friend.json, persona.md, face.png or face.jpg) into the catalog.
 * Run it where the files are (never commit them): `php artisan app:import-contacts /path/to/contacts`.
 *
 * Each friend gets their persona (without the desktop app's phone and safety boilerplate: this app adds its own rules),
 * their picture, looks, a Gemini voice and a brain. They arrive as drafts unless --publish is given. Safe to run again:
 * a friend that already exists (same name) is updated, which saves a new version when the persona changed. Memory and
 * call history are never touched or copied: every user starts fresh with each friend.
 */
class ImportDesktopContactsCommand extends Command
{
    protected $signature = 'app:import-contacts {path : The desktop app\'s contacts folder} {--brain=grok-4.20-0309-non-reasoning : The chat model that writes their texts} {--publish : Show them in the catalog when they are ready}';

    protected $description = 'Import the desktop app\'s friends (persona, picture, looks, voice and brain) into the catalog';

    /** Where the desktop persona's own phone and safety rules start (this app builds those itself). */
    private const BOILERPLATE_FROM = "\n## How you talk on the phone";

    /** The Gemini voice each desktop friend speaks with on calls; anyone else gets one by gender. */
    private const VOICES = [
        'joe' => 'Charon', 'coop' => 'Puck', 'george' => 'Orus', 'elena' => 'Sulafat', 'rosa' => 'Vindemiatrix', 'savannah' => 'Gacrux',
    ];

    public function handle(ContactCatalog $catalog): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        if (! File::isDirectory($path)) {
            $this->error("No folder at {$path}.");

            return self::FAILURE;
        }

        $brain = Llm::where('purpose', 'chat')->where('model_id', $this->option('brain'))->first();
        if (! $brain) {
            $this->error("No chat model called {$this->option('brain')} (see /admin/models).");

            return self::FAILURE;
        }

        $imported = 0;
        foreach (File::directories($path) as $folder) {
            $id = basename($folder);
            $profile = $this->profile($folder);

            if ($profile === null || ($profile['support'] ?? false)) {
                continue; // not a friend (the underscore rule folders, or the desktop app's support agents)
            }

            $contact = $this->import($catalog, $id, $folder, $profile, $brain);
            $imported++;

            $note = $this->option('publish') ? $this->publish($catalog, $contact) : 'draft';
            $this->line("{$contact->name}: {$note}");
        }

        $this->info("Imported {$imported} ".Str::plural('friend', $imported).'.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function profile(string $folder): ?array
    {
        if (! File::exists("{$folder}/persona.md") || ! File::exists("{$folder}/friend.json")) {
            return null;
        }

        $profile = json_decode((string) File::get("{$folder}/friend.json"), true);

        return is_array($profile) && ($profile['name'] ?? '') !== '' ? $profile : null;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function import(ContactCatalog $catalog, string $id, string $folder, array $profile, Llm $brain): Contact
    {
        $name = (string) $profile['name'];
        $gender = in_array($profile['gender'] ?? '', config('contacts.genders'), true) ? $profile['gender'] : 'man';

        $data = [
            'name' => $name,
            'tagline' => $this->tagline((string) ($profile['about'] ?? '')),
            'gender' => $gender,
            'persona_md' => $this->persona((string) File::get("{$folder}/persona.md")),
            'voice' => self::VOICES[$id] ?? ($gender === 'woman' ? 'Kore' : 'Fenrir'),
            'brain_id' => $brain->id,
        ];
        if (is_string($profile['looks'] ?? null) && trim($profile['looks']) !== '') {
            $data['looks'] = trim($profile['looks']);
        }

        $contact = Contact::withTrashed()->where('slug', Str::slug($name))->first();
        if ($contact) {
            $contact->restore();
            $catalog->update($contact, $data, note: 'Imported from the desktop app');
        } else {
            $contact = $catalog->create($data + ['slug' => $catalog->uniqueSlug($name)]);
        }

        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'] as $extension => $mime) {
            if (File::exists("{$folder}/face.{$extension}")) {
                $catalog->setAvatar($contact, new UploadedFile("{$folder}/face.{$extension}", "face.{$extension}", $mime, null, true));

                break;
            }
        }

        return $contact->refresh();
    }

    /** The desktop persona without its trailing phone and safety rules. */
    private function persona(string $markdown): string
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $cut = strpos($markdown, self::BOILERPLATE_FROM);

        return trim($cut === false ? $markdown : substr($markdown, 0, $cut));
    }

    /** A catalog tagline (at most 160 characters) from the desktop "about" line. */
    private function tagline(string $about): string
    {
        $about = trim(str_replace('Personal: kept on this computer only.', '', $about));

        return Str::limit($about, 160, '…');
    }

    private function publish(ContactCatalog $catalog, Contact $contact): string
    {
        try {
            $catalog->publish($contact);

            return 'published';
        } catch (InvalidArgumentException $e) {
            return 'draft ('.$e->getMessage().')';
        }
    }
}
