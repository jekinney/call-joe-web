<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\Call;
use App\Models\Image;
use App\Models\ImageRequest;
use App\Models\Message;
use App\Models\ModerationEvent;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserContact;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * A person's rights over their data: a copy of everything the service holds about them, and erasing it.
 */
class DataRights
{
    public function __construct(private Billing $billing) {}

    /**
     * Everything we keep about a user, as a plain array (written out as JSON). Leaves out other people's data and our
     * internal AI cost records.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'date_of_birth' => $user->date_of_birth?->toDateString(),
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'plan' => $user->plan?->name,
                'plan_expires_at' => $user->plan_expires_at?->toIso8601String(),
                'away_until' => $user->away_until?->toIso8601String(),
                'away_note' => $user->away_note,
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'contacts' => $user->userContacts()->withTrashed()->with(['contact', 'relationship', 'memory'])->get()
                ->map(fn (UserContact $link) => [
                    'contact' => $link->contact->name,
                    'nickname' => $link->nickname,
                    'relationship' => $link->relationship?->label,
                    'added_at' => $link->added_at->toIso8601String(),
                    'removed_at' => $link->deleted_at?->toIso8601String(),
                    'memory_notes' => $link->memory?->notes_md,
                    'messages' => Message::where('user_contact_id', $link->id)->orderBy('id')->get()
                        ->map(fn (Message $m) => ['from' => $m->sender, 'at' => $m->created_at->toIso8601String(), 'text' => $m->body])->all(),
                    'calls' => Call::where('user_contact_id', $link->id)->orderBy('id')->get()
                        ->map(fn (Call $c) => [
                            'direction' => $c->direction,
                            'status' => $c->status,
                            'started_at' => $c->started_at?->toIso8601String(),
                            'seconds' => $c->duration_seconds,
                            'transcript' => $c->transcript,
                        ])->all(),
                    'pictures' => ImageRequest::where('user_contact_id', $link->id)->orderBy('id')->get()
                        ->map(fn (ImageRequest $p) => ['description' => $p->prompt, 'status' => $p->status, 'at' => $p->created_at->toIso8601String()])->all(),
                ])->all(),
            'safety_notices' => ModerationEvent::where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (ModerationEvent $e) => ['at' => $e->created_at->toIso8601String(), 'category' => $e->category->value, 'action' => $e->action, 'excerpt' => $e->excerpt])->all(),
            'payments' => Payment::where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (Payment $p) => ['at' => $p->occurred_at->toIso8601String(), 'amount_cents' => $p->amount_cents, 'status' => $p->status])->all(),
        ];
    }

    /**
     * Deletes the account and everything under it: contacts, texts, calls, memory, pictures and payment records. A
     * subscription that is still charging is canceled first, so nobody is billed for an account that is gone.
     *
     * @throws DomainException when they are the only admin.
     * @throws PaymentException when the subscription could not be canceled (nothing is deleted then).
     */
    public function erase(User $user): void
    {
        if ($user->is_admin && User::where('is_admin', true)->whereKeyNot($user->id)->doesntExist()) {
            throw new DomainException('You are the only admin; make someone else an admin first.');
        }

        if ($this->billing->current($user)?->isRenewing()) {
            $this->billing->cancel($user);
        }

        $user->delete();

        // Pictures made for them go too, unless someone else's chat still shows them or the shared cache reuses them.
        Image::whereDoesntHave('requests')->whereDoesntHave('cacheEntries')->each(function (Image $image) {
            Storage::disk($image->disk)->delete($image->storage_path);
            $image->delete();
        });
    }
}
