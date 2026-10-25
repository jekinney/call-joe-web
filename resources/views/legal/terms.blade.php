{{-- Draft for review by counsel before launch (see config/legal.php to version changes). --}}
<x-layouts.legal :title="__('Terms of Service')">
    <p>These terms are an agreement between you and 120 Customs LLC ("we"), which runs Call Joe. By creating an account you agree to them, to our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>, and to the <a href="{{ route('legal.ai') }}">AI disclosure</a>.</p>

    <h2>Who can use Call Joe</h2>
    <ul>
        <li>You must be 18 or older. We ask for your date of birth and close accounts that belong to anyone younger.</li>
        <li>One person per account. Keep your password to yourself; you're responsible for what happens on your account.</li>
    </ul>

    <h2>What Call Joe is</h2>
    <p>Call Joe lets you text and talk with AI companions. They are not real people, and what they say isn't professional advice. See <a href="{{ route('legal.ai') }}">About AI contacts</a>.</p>

    <h2>Your plan</h2>
    <ul>
        <li>The Free plan has daily and monthly limits. Paid plans raise them and add features, as shown on the Plan page.</li>
        <li>Paid plans renew until you cancel. When billing launches, prices, renewals and refunds will be shown before you pay.</li>
    </ul>

    <h2>What's not allowed</h2>
    <ul>
        <li>Anything sexual involving minors, or asking a contact to play a minor or a teenager. We block it, keep a record, and may close the account and report it as the law requires.</li>
        <li>Using Call Joe to harass, threaten or harm anyone, or to make pictures of real people.</li>
        <li>Trying to break, overload or copy the service, or get around its limits or safety checks.</li>
    </ul>
    <p>Automated safety checks review texts, replies, picture prompts and call transcripts for these things. We may suspend an account that breaks these rules.</p>

    <h2>Your content</h2>
    <p>You own what you write. You let us store and process it to run the service, as described in the Privacy Policy. You can delete what a contact remembers about you at any time.</p>

    <h2>No warranty</h2>
    <p>Call Joe is provided as is. AI can be wrong or unavailable, and we don't promise the service will always work. To the extent the law allows, we aren't liable for indirect or consequential losses, and our total liability is limited to what you paid us in the last 12 months.</p>

    <h2>Changes and contact</h2>
    <p>If we change these terms in a meaningful way, we'll ask you to accept the new version before you keep using the app. Questions: <a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>.</p>
</x-layouts.legal>
