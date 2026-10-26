{{-- Draft for review by counsel before launch (see config/legal.php to version changes). --}}
<x-layouts.legal :title="__('Privacy Policy')">
    <p>This explains what Call Joe, run by 120 Customs LLC, collects, why, and what you can do about it.</p>

    <h2>What we collect</h2>
    <ul>
        <li><strong>Your account:</strong> name, email, date of birth (to confirm you're an adult), time zone, and your plan.</li>
        <li><strong>Your conversations:</strong> texts with your contacts, call transcripts and lengths, and the notes each contact keeps about you.</li>
        <li><strong>How you use it:</strong> when you're online (so contacts know whether to ring or leave a missed call), and usage counts for your plan's limits.</li>
        <li><strong>Safety records:</strong> when an automated check blocks or flags something, a short excerpt is kept so a person on our team can review it.</li>
    </ul>

    <h2>Who processes it</h2>
    <p>To write replies and run calls, your messages are sent to the AI and voice providers we use (currently Anthropic, Google and xAI for text, and Google for calls), under their terms for business customers. We host on DigitalOcean. We don't sell your data or use it for advertising.</p>

    <h2>Who sees it</h2>
    <p>No one reads your conversations as a matter of course. A small number of staff can see safety records, and account details when you ask for help.</p>

    <h2>How long we keep it</h2>
    <p>Conversations and notes stay while your account exists, so your contacts remember you. You can erase what a contact remembers at any time. When you delete your account, your data is deleted, except what the law requires us to keep.</p>

    <h2>Your choices</h2>
    <ul>
        <li>Read and erase each contact's memory on their "What they remember" page.</li>
        <li>Change how much contacts reach out, or turn on away mode, in the app.</li>
        <li>Delete your account in Settings, or write to us for a copy of your data.</li>
    </ul>

    <h2>Contact</h2>
    <p><a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a></p>
</x-layouts.legal>
