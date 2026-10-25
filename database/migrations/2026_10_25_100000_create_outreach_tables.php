<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts reaching out on their own (App\Services\Outreach).
     *
     * - user_contacts: when the user last talked with them (last_heard_at), how worried they are now (worry_stage,
     *   from the relationship's worry profile), the user's worry setting for them (normal | chill | off), and the
     *   relief owed on the user's return (relief_stage, relief_hours).
     * - users: away mode ("on vacation until Sunday"), so nobody worries.
     * - outreach_events: the schedule. Each row is "this contact texts / calls / leaves a voicemail at due_at". The
     *   words are written only when the user is there to read them (materialized_at), with the original time.
     * - calls: an incoming call rings before any assistant exists, so its prompt and length come later.
     */
    public function up(): void
    {
        Schema::table('user_contacts', function (Blueprint $table) {
            $table->timestamp('last_heard_at')->nullable();
            $table->unsignedTinyInteger('worry_stage')->default(0);
            $table->timestamp('worry_started_at')->nullable();
            $table->string('worry_setting', 10)->default('normal');
            $table->unsignedTinyInteger('relief_stage')->nullable();
            $table->unsignedInteger('relief_hours')->nullable();
        });

        // Silence counts from the last thing the user said to them, or from when they were added.
        DB::statement(<<<'SQL'
            UPDATE user_contacts SET last_heard_at = COALESCE(
                (SELECT MAX(messages.created_at) FROM messages WHERE messages.user_contact_id = user_contacts.id AND messages.sender = 'user'),
                user_contacts.added_at)
            SQL);

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('away_until')->nullable();
            $table->string('away_note', 200)->nullable();
        });

        Schema::create('outreach_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_contact_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                         // text | call | voicemail
            $table->unsignedTinyInteger('stage');               // the worry stage it came from
            $table->string('tone', 200);                        // what it's about, from the worry profile
            $table->timestamp('due_at');
            $table->string('status', 10)->default('planned');  // planned | done | skipped
            $table->timestamp('materialized_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_contact_id', 'kind', 'due_at']);
            $table->index(['status', 'due_at']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->char('prompt_hash', 64)->nullable()->change();
            $table->unsignedInteger('max_seconds')->default(0)->change();
        });
    }

    /** The calls columns stay nullable: incoming calls that never connected have no prompt. */
    public function down(): void
    {
        Schema::dropIfExists('outreach_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['away_until', 'away_note']);
        });
        Schema::table('user_contacts', function (Blueprint $table) {
            $table->dropColumn(['last_heard_at', 'worry_stage', 'worry_started_at', 'worry_setting', 'relief_stage', 'relief_hours']);
        });
    }
};
