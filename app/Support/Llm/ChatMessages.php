<?php

namespace App\Support\Llm;

/**
 * Turns the app's messages (system, user and assistant, with notes dropped in anywhere) into what Claude and Gemini
 * accept: one system prompt, then turns that alternate between the user and the assistant, starting and ending with
 * the user.
 *
 * - System messages at the start become the system prompt.
 * - Any later system message (a relief note, a crisis note, "you're texting first...") is already written as a
 *   parenthetical, so it's added to the user's turn.
 * - Neighbors with the same role are joined, so the turns alternate.
 */
final class ChatMessages
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{system: string, turns: list<array{role: 'user'|'assistant', content: string}>}
     */
    public static function split(array $messages): array
    {
        $system = [];
        /** @var list<array{role: 'user'|'assistant', content: string}> $turns */
        $turns = [];

        foreach ($messages as $message) {
            $content = trim($message['content']);
            if ($content === '') {
                continue;
            }

            if ($message['role'] === 'system' && $turns === []) {
                $system[] = $content;

                continue;
            }

            $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] .= "\n\n".$content;
            } else {
                $turns[] = ['role' => $role, 'content' => $content];
            }
        }

        if ($turns === [] || $turns[0]['role'] === 'assistant') {
            array_unshift($turns, ['role' => 'user', 'content' => $turns === [] ? '(Go ahead.)' : '(The conversation so far.)']);
        }
        if ($turns[array_key_last($turns)]['role'] === 'assistant') {
            $turns[] = ['role' => 'user', 'content' => '(Go on.)'];
        }

        return ['system' => implode("\n\n", $system), 'turns' => $turns];
    }
}
