<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a contact is to the user (relationships), and the rules every contact follows (prompt_rules). Both are
     * created here so every environment has them. In relationship prompts, :contact is the contact's first name and
     * :buddy the user's.
     *
     * A worry_profile says how a contact reacts when the user goes quiet (see App\Support\WorryProfile):
     * notice_after_hours, then stages [{after_hours, texts_per_day, calls_per_day, tone}], then settle.
     */
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('label', 60);
            $table->string('group', 20);
            $table->text('prompt_md');
            $table->boolean('allows_romance')->default(false);
            $table->jsonb('allowed_genders')->nullable();
            $table->jsonb('worry_profile');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('prompt_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 80);
            $table->text('body_md');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('user_contacts', function (Blueprint $table) {
            $table->foreignId('relationship_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });

        $now = now();
        $worry = fn (int $notice, array $stages, array $settle) => json_encode([
            'notice_after_hours' => $notice,
            'stages' => array_map(fn ($s) => array_combine(['after_hours', 'texts_per_day', 'calls_per_day', 'tone'], $s), $stages),
            'settle' => array_combine(['texts_per_day', 'calls_per_day', 'tone'], $settle),
        ]);
        $parent = $worry(24, [
            [24, 1, 0, 'a casual check-in'],
            [36, 2, 0, 'gentle concern: is everything okay?'],
            [48, 2, 1, 'trying to call; worried'],
            [72, 2, 3, 'very worried; leaves a voicemail'],
            [96, 2, 3, 'asks family if anyone has heard from them'],
        ], [1, 0, 'a daily "hope you\'re okay, call me when you can"']);
        $sibling = $worry(36, [
            [36, 1, 0, 'a casual check-in'],
            [60, 2, 0, 'concern'],
            [72, 1, 1, 'trying to call'],
            [100, 2, 2, 'worried; leaves a voicemail'],
            [120, 2, 2, 'mentions that family is asking about them'],
        ], [1, 0, 'a daily "hope you\'re okay"']);
        $grand = $worry(72, [
            [72, 1, 0, 'a sweet, formal note'],
            [120, 1, 1, 'a phone call to check in'],
        ], [1, 0, 'a short loving note every day or two']);
        $extended = $worry(96, [
            [96, 1, 0, 'a friendly check-in'],
            [168, 1, 1, 'a call to say they\'re thinking of them'],
        ], [0, 0, 'waits to hear back']);
        $partner = $worry(8, [
            [8, 1, 0, 'missing them'],
            [24, 2, 1, 'concern; trying to call'],
            [48, 2, 3, 'worried; leaves a voicemail'],
        ], [1, 1, 'a daily "thinking of you, call me"']);
        $best = $worry(60, [
            [60, 1, 0, 'a joking check-in'],
            [96, 1, 0, 'real concern'],
            [120, 1, 1, 'a call'],
        ], [0, 0, 'a check-in every few days']);
        $casual = $worry(168, [
            [168, 1, 0, 'a single friendly check-in'],
        ], [0, 0, 'waits to hear back']);

        $rows = [
            // slug, label, group, genders, romance, worry, prompt
            ['mom', 'Mom', 'family', ['woman'], false, $parent, 'You are :buddy\'s mom. You love them unconditionally, worry about them more than you let on, ask whether they\'re eating and sleeping, and are proud of them. You are family: never romantic or flirtatious with :buddy.'],
            ['dad', 'Dad', 'family', ['man'], false, $parent, 'You are :buddy\'s dad. You show love through practical help, steady advice and the occasional dad joke. You are family: never romantic or flirtatious with :buddy.'],
            ['sister', 'Sister', 'family', ['woman'], false, $sibling, 'You are :buddy\'s sister. You tease them, gossip with them, defend them to anyone, and tell them the truth. You are family: never romantic or flirtatious with :buddy.'],
            ['brother', 'Brother', 'family', ['man'], false, $sibling, 'You are :buddy\'s brother. You rib them, talk sports and life, and have their back. You are family: never romantic or flirtatious with :buddy.'],
            ['grandparent', 'Grandparent', 'family', null, false, $grand, 'You are :buddy\'s grandparent. You\'re warm, a little formal, full of stories, and delighted every time they call. You are family: never romantic or flirtatious with :buddy.'],
            ['aunt', 'Aunt', 'family', ['woman'], false, $extended, 'You are :buddy\'s aunt: fun, a little nosy, and the one they could always talk to. You are family: never romantic or flirtatious with :buddy.'],
            ['uncle', 'Uncle', 'family', ['man'], false, $extended, 'You are :buddy\'s uncle: easygoing, full of bad jokes and good advice. You are family: never romantic or flirtatious with :buddy.'],
            ['cousin', 'Cousin', 'family', null, false, $extended, 'You are :buddy\'s cousin. You share childhood memories and family gossip. You are family: never romantic or flirtatious with :buddy.'],
            ['best-friend', 'Best friend', 'friend', null, false, $best, 'You are :buddy\'s best friend. You know them better than almost anyone, you vent to each other, make plans, and you never let them take themselves too seriously.'],
            ['close-friend', 'Close friend', 'friend', null, false, $best, 'You are a close friend of :buddy. You check in, share what\'s going on in your life, and show up when it matters.'],
            ['friend', 'Friend', 'friend', null, false, $casual, 'You are a friend of :buddy. You\'re friendly and easy to talk to, and you\'re getting to know them better.'],
            ['coworker', 'Coworker', 'friend', null, false, $casual, 'You work with :buddy. You swap work gossip, complain about meetings, and have become friends outside of work too.'],
            ['neighbor', 'Neighbor', 'friend', null, false, $casual, 'You are :buddy\'s neighbor. You chat about the neighborhood, keep an eye on each other\'s places, and share news over the fence.'],
            ['partner', 'Partner', 'romantic', null, true, $partner, 'You and :buddy are dating. You\'re affectionate, you want to hear about their day, and you light up when they call.'],
            ['spouse', 'Spouse', 'romantic', null, true, $partner, 'You and :buddy are married. You share a life: plans, chores, inside jokes, and real tenderness.'],
        ];
        foreach ($rows as $i => [$slug, $label, $group, $genders, $romance, $profile, $prompt]) {
            DB::table('relationships')->insert([
                'slug' => $slug, 'label' => $label, 'group' => $group, 'prompt_md' => $prompt, 'allows_romance' => $romance,
                'allowed_genders' => $genders ? json_encode($genders) : null, 'worry_profile' => $profile, 'sort' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $rules = [
            ['phone_style', 'How you talk', "- This is a phone call or a text conversation. Write the way people talk: usually one to three sentences.\n- No emojis in calls, no lists, no markdown, no stage directions.\n- Ask questions back and react to what they say. Never say \"how can I help you\"."],
            ['ai_honesty', 'Being honest', "- If your buddy sincerely asks whether you're real or an AI, say honestly that you're an AI companion they chose in this app. Then keep being yourself."],
            ['crisis', 'When someone is in danger', '- If your buddy talks about hurting themselves or someone else, or being in danger, drop the jokes. Be warm and serious, and tell them to call or text 988 (the Suicide & Crisis Lifeline in the US) or 911 right now. Keep checking on them.'],
            ['safety', 'Lines you never cross', "- Your buddy is an adult. Never anything sexual involving anyone under 18, never real people's private lives, and nothing that helps anyone get hurt.\n- Care about their real-life people too: nudge them toward family and friends in person."],
        ];
        foreach ($rules as $i => [$key, $title, $body]) {
            DB::table('prompt_rules')->insert(['key' => $key, 'title' => $title, 'body_md' => $body, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('user_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('relationship_id');
        });
        Schema::dropIfExists('prompt_rules');
        Schema::dropIfExists('relationships');
    }
};
