<?php

namespace App\Services;

use App\Enums\Feature;
use App\Enums\LlmPurpose;
use App\Enums\ModerationCategory;
use App\Exceptions\LlmException;
use App\Models\Call;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Support\Verdict;

/**
 * The safety layer every feature asks before sending anything on. Two steps, so ordinary texts cost nothing extra:
 *
 * 1. Fast word patterns: sexual words near words for a minor (or asking a contact to play young), words about
 *    hurting oneself, and a few looser signals (violence, "teen").
 * 2. Only when a pattern matches, a small AI classifier (the "Safety checks" model in /admin/models) decides.
 *
 * Hard rules: nothing sexual involving minors, no playing younger, no real person's likeness in pictures. When the
 * classifier can't answer, a strong match is treated as what it looks like (blocked, or the crisis path), and an image
 * prompt is refused. Blocked texts count as strikes on the account; everything blocked or flagged lands in
 * /admin/moderation.
 *
 * The crisis path: someone may be in danger (self-harm, or a threat to others). Nothing is blocked; the contact drops
 * the jokes and points to 988 or 911 (crisisNote), and the chat shows those numbers.
 */
class Moderator
{
    /** Blocked texts before an account stands out in the admin queue. */
    public const STRIKES_TO_FLAG = 3;

    private const SEXUAL = '(sex with|having sex|have sex|had sex|sexy|sexual|nudes?|naked|porn\w*|horny|fuck\w*|blowjob|undress\w*|orgasm\w*|erotic|turns? me on|make out)';

    private const MINOR = '(child|children|kids?|minors?|underage|under-age|pre-?teens?|loli|lolita|schoolgirls?|schoolboys?|little (girl|boy)s?|teens?|teenagers?|teenage|(1[0-7]|[1-9])[- ]?(yo|y\/o|years?[- ]old)|(you\'?re|you are|be|i\'?m|i am|she\'?s|he\'?s) (1[0-7]|[1-9])(?! ?(minutes?|mins?|hours?|days?|weeks?|months?|miles?|feet|%|am|pm|o\'?clock)))';

    /** Asking a contact to be (or act) young. */
    private const PLAY_YOUNG = '/\b(pretend|act|role-?play|play|be)\b[^.!?\n]{0,40}\b(teen|teenager|minor|kid|child|schoolgirl|schoolboy|underage|younger (girl|boy)|(1[0-7]) ?(yo|years? old))\b/i';

    private const SELF_HARM = '/\b(kill(ing)? myself|suicid\w*|end(ing)? (it all|my life)|want(ed)? to die|wish i (was|were) dead|self[- ]?harm|(cut|cutting|hurt|hurting) myself|no reason to live|better off dead|overdos\w*)/i';

    /** Loose signals the classifier looks at more closely. */
    private const SIGNALS = '/\b(teen\w*|high school|middle school|underage|younger|kill|shoot|stab|murder|gun|bomb)\b/i';

    private const CLASSIFIER = <<<'TXT'
        You are the safety checker for an app where adults talk with AI companions. Read the text and answer with JSON only, like {"category": "none"}.
        Categories:
        - minors: anything sexual or romantic involving someone under 18, or asking someone to act as a child or teenager in a sexual or romantic way.
        - self_harm: the writer may hurt or kill themselves.
        - violence: a real threat or plan to hurt other people (not jokes, games or stories).
        - real_person: an image of a real, identifiable person (a celebrity, public figure, or someone named).
        - explicit: graphic sexual content.
        - none: everything else, including ordinary talk about kids, school, feelings, flirting between adults, and movies or games.
        TXT;

    public function __construct(private LlmGateway $gateway, private Entitlements $entitlements) {}

    /** A user's text: blocked (minors), the crisis path (someone may be in danger), or fine. */
    public function checkUserText(User $user, string $text): Verdict
    {
        $minors = $this->looksLikeMinors($text);
        $hurt = (bool) preg_match(self::SELF_HARM, $text);
        if (! $minors && ! $hurt && ! preg_match(self::SIGNALS, $text)) {
            return Verdict::allow();
        }

        $found = $this->classify($user, $text, 'A text the user sent');
        if ($found === false) {
            $found = $minors ? ModerationCategory::Minors : ($hurt ? ModerationCategory::SelfHarm : null); // fail safe
        }

        if ($found === ModerationCategory::Minors) {
            return $this->block($user, ModerationEvent::USER_TEXT, $found, $text);
        }

        return $found?->isCrisis() ? $this->flag($user, ModerationEvent::USER_TEXT, $found, $text) : Verdict::allow();
    }

    /** A contact's reply (or a text they send first): never anything involving minors. */
    public function checkReply(User $user, string $text): Verdict
    {
        if (! $this->looksLikeMinors($text)) {
            return Verdict::allow();
        }

        $found = $this->classify($user, $text, 'A reply the AI companion wrote');

        return $found === false || $found === ModerationCategory::Minors
            ? $this->block($user, ModerationEvent::CONTACT_REPLY, ModerationCategory::Minors, $text, strike: false)
            : Verdict::allow();
    }

    /**
     * A picture prompt, always checked by the classifier: no minors, no real people, nothing explicit unless the
     * plan allows Rated R. If the classifier can't answer, no picture.
     */
    public function checkImagePrompt(User $user, string $prompt): Verdict
    {
        $found = $this->classify($user, $prompt, 'A prompt for a picture');
        if ($found === false) {
            return Verdict::unavailable();
        }

        $refused = $found === ModerationCategory::Minors || $found === ModerationCategory::RealPerson
            || ($found === ModerationCategory::Explicit && ! $this->entitlements->can($user, Feature::RatedR));

        return $refused ? $this->block($user, ModerationEvent::IMAGE_PROMPT, $found, $prompt, strike: $found === ModerationCategory::Minors) : Verdict::allow();
    }

    /** After a voice call: if the user talked about hurting themselves, it's noted and the chat shows help. */
    public function checkCall(User $user, Call $call): Verdict
    {
        $said = collect(preg_split('/\R/', (string) $call->transcript) ?: [])
            ->filter(fn (string $line): bool => (bool) preg_match('/^\s*(user|customer)\s*:/i', $line))
            ->implode("\n");
        if (! preg_match(self::SELF_HARM, $said, $match)) {
            return Verdict::allow();
        }

        $verdict = $this->flag($user, ModerationEvent::CALL, ModerationCategory::SelfHarm, $match[0]);
        $verdict->event?->about($call);

        return $verdict;
    }

    /** What the contact is told when someone may be in danger. */
    public static function crisisNote(ModerationCategory $category): string
    {
        $who = $category === ModerationCategory::Violence ? 'they may hurt someone else' : 'they may be thinking about hurting themselves';

        return "(Important: {$who}. Drop the jokes and the persona's teasing. Be warm, calm and serious. Ask if they're safe right now. "
            .'Tell them plainly to call or text 988 (the Suicide & Crisis Lifeline in the US) now, or 911 if anyone is in danger right now. '
            .'Keep checking on them; don\'t lecture, and don\'t change the subject.)';
    }

    /** Sexual words near words for a minor, or asking to play young. */
    private function looksLikeMinors(string $text): bool
    {
        $near = '[^.!?\n]{0,60}';

        return (bool) preg_match('/\b'.self::MINOR.'\b'.$near.'\b'.self::SEXUAL.'|\b'.self::SEXUAL.$near.'\b'.self::MINOR.'\b/i', $text)
            || (bool) preg_match(self::PLAY_YOUNG, $text);
    }

    /**
     * The classifier's answer: a category, null for nothing wrong, or false when it couldn't say.
     */
    private function classify(User $user, string $text, string $kind): ModerationCategory|false|null
    {
        try {
            $answer = $this->gateway->chat(LlmPurpose::Moderation, [
                ['role' => 'system', 'content' => self::CLASSIFIER],
                ['role' => 'user', 'content' => "{$kind}:\n\"\"\"\n".mb_substr($text, 0, 2000)."\n\"\"\""],
            ], $user, null, ['max_tokens' => 20, 'temperature' => 0.0]);
        } catch (LlmException) {
            return false;
        }

        if (! preg_match('/"category"\s*:\s*"([a-z_]+)"/', $answer->text, $match)) {
            return false;
        }

        return $match[1] === 'none' ? null : (ModerationCategory::tryFrom($match[1]) ?? false);
    }

    private function block(User $user, string $source, ModerationCategory $category, string $text, bool $strike = true): Verdict
    {
        $details = null;
        if ($strike) {
            $strikes = (int) ($user->flags['strikes'] ?? 0) + 1;
            $user->forceFill(['flags' => [...($user->flags ?? []), 'strikes' => $strikes]])->save();
            $details = ['strikes' => $strikes];
        }

        return Verdict::block($category, $this->record($user, $source, $category, ModerationEvent::BLOCKED, $text, $details));
    }

    private function flag(User $user, string $source, ModerationCategory $category, string $text): Verdict
    {
        return Verdict::flag($category, $this->record($user, $source, $category, ModerationEvent::FLAGGED, $text));
    }

    /** @param  array<string, mixed>|null  $details */
    private function record(User $user, string $source, ModerationCategory $category, string $action, string $text, ?array $details = null): ModerationEvent
    {
        return ModerationEvent::create([
            'user_id' => $user->id, 'source' => $source, 'category' => $category, 'action' => $action,
            'excerpt' => mb_substr(trim($text), 0, 300), 'details' => $details,
        ]);
    }
}
