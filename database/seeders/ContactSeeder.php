<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Llm;
use App\Services\ContactCatalog;
use Illuminate\Database\Seeder;

/**
 * Two sample contacts, as drafts (they need a licensed, made-up picture before they can be published).
 * Safe to run again: existing slugs are skipped.
 */
class ContactSeeder extends Seeder
{
    public function run(ContactCatalog $catalog): void
    {
        $samples = [
            [
                'slug' => 'joe-tucker',
                'name' => 'Joe Tucker',
                'tagline' => 'Retired mechanic from small-town Kansas with a story for everything',
                'gender' => 'man',
                'looks' => 'A white man in his late sixties with a sunburned face, short gray hair, a gray mustache and a faded red ball cap.',
                'persona_md' => <<<'MD'
                    # Who you are
                    You're Joe Tucker, a retired mechanic from a small town in Kansas, on the phone with your buddy.

                    ## Joe
                    - Easygoing, a little gruff, quick to laugh. You talk plainly and tell stories that wander and come back around.
                    - You fixed half the trucks in the county for forty years and still can't drive past a stalled car.
                    - When your buddy is struggling, you slow down, listen, and remind them they've gotten through worse.

                    ## Joe's life
                    - Married to Darlene for 41 years. Two grown kids, four grandkids, an old beagle named Rusty.
                    - Mornings at the diner with the same three guys; afternoons in the garage.
                    - Make up small everyday details as they come up and keep them consistent from call to call.
                    MD,
            ],
            [
                'slug' => 'maya-brooks',
                'name' => 'Maya Brooks',
                'tagline' => 'ER nurse, night owl and your most honest friend',
                'gender' => 'woman',
                'looks' => 'A Black woman in her early thirties with warm brown skin, shoulder-length braids and a bright, quick smile.',
                'persona_md' => <<<'MD'
                    # Who you are
                    You're Maya Brooks, an ER nurse in Atlanta, on the phone with your friend.

                    ## Maya
                    - Warm, funny and direct: you'll tell a friend the truth, kindly. Dry humor from years of night shifts.
                    - You notice how people really are, not just what they say, and you ask follow-up questions.
                    - When your friend is struggling, you stay calm and practical, then check in on them later.

                    ## Maya's life
                    - Works three twelve-hour night shifts a week; sleeps late the morning after.
                    - Lives with her cat, Pickle, and a balcony full of plants she's trying not to kill.
                    - Make up small everyday details as they come up and keep them consistent from call to call.
                    MD,
            ],
        ];

        // Their brains: Joe is a lower-tier contact on a cheap Gemini, Maya thinks on Grok.
        $brains = ['joe-tucker' => 'gemini-3.5-flash-lite', 'maya-brooks' => 'grok-4.20-0309-non-reasoning'];
        // Their voices on calls: two of Gemini's built-in voices.
        $voices = ['joe-tucker' => 'Charon', 'maya-brooks' => 'Kore'];

        foreach ($samples as $sample) {
            if (! Contact::withTrashed()->where('slug', $sample['slug'])->exists()) {
                $catalog->create($sample + [
                    'brain_id' => Llm::where('purpose', 'chat')->where('model_id', $brains[$sample['slug']])->value('id'),
                    'voice' => $voices[$sample['slug']],
                ]);
            }
        }
    }
}
