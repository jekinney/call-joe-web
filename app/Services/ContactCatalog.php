<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates and edits catalog contacts. A contact's identity (persona and looks) is versioned: every change to it is a
 * new numbered version, any earlier version can be restored (as a new version, so nothing is ever lost), and plain
 * details (name, tagline, plan) change without a new version.
 */
class ContactCatalog
{
    /** Fields that make up who a contact is. */
    private const IDENTITY = ['persona_md', 'looks'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $by = null): Contact
    {
        return DB::transaction(function () use ($data, $by) {
            $contact = Contact::create([...$data, 'slug' => $data['slug'] ?? $this->uniqueSlug((string) $data['name'])]);
            $this->recordVersion($contact, $by, 'Created');

            return $contact;
        });
    }

    /**
     * Saves changes; a new version only when the persona or looks changed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data, ?User $by = null, ?string $note = null): Contact
    {
        return DB::transaction(function () use ($contact, $data, $by, $note) {
            $contact->fill($data);
            $identityChanged = $contact->isDirty(self::IDENTITY);

            if ($identityChanged) {
                $contact->version++;
            }
            $contact->save();

            if ($identityChanged) {
                $this->recordVersion($contact, $by, $note);
            }

            return $contact;
        });
    }

    /**
     * Brings back an earlier version's persona and looks, saved as a new version.
     */
    public function restore(Contact $contact, int $version, ?User $by = null): Contact
    {
        $old = $contact->versions()->where('version', $version)->first()
            ?? throw new InvalidArgumentException("Version {$version} doesn't exist.");

        return $this->update($contact, ['persona_md' => $old->persona_md, 'looks' => $old->looks], $by, "Restored version {$version}");
    }

    /**
     * Replaces the contact's picture (the old file is removed).
     */
    public function setAvatar(Contact $contact, UploadedFile $file): Contact
    {
        $disk = Storage::disk(config('contacts.avatar_disk'));
        $old = $contact->avatar_path;

        $path = $file->storePubliclyAs("contacts/{$contact->id}", 'avatar-'.Str::random(12).'.'.$file->extension(), ['disk' => config('contacts.avatar_disk')]);
        $contact->forceFill(['avatar_path' => $path ?: null])->save();

        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        return $contact;
    }

    /**
     * Shows the contact in the catalog. Refuses while something they need is missing.
     */
    public function publish(Contact $contact): Contact
    {
        $missing = $contact->missingForPublish();
        if ($missing) {
            throw new InvalidArgumentException("{$contact->name} still needs ".implode(' and ', $missing).'.');
        }

        $contact->forceFill(['is_published' => true, 'published_at' => $contact->published_at ?? now()])->save();

        return $contact;
    }

    /** Hides the contact from the catalog (people who already added them keep them). */
    public function unpublish(Contact $contact): Contact
    {
        $contact->forceFill(['is_published' => false])->save();

        return $contact;
    }

    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'contact';
        $slug = $base;
        for ($n = 2; Contact::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function recordVersion(Contact $contact, ?User $by, ?string $note): void
    {
        ContactVersion::create([
            'contact_id' => $contact->id,
            'version' => $contact->version,
            'persona_md' => $contact->persona_md,
            'looks' => $contact->looks,
            'note' => $note,
            'created_by' => $by?->id,
        ]);
    }
}
