<?php

namespace App\Services\Fake;

use App\Models\Proposal;
use App\Services\FreelancerMessenger;
use Illuminate\Support\Facades\Storage;

/**
 * Development stand-in for the Freelancer Messages API (FL_FAKE=true).
 *
 * Fabricates one client thread per existing proposal (up to a handful) and
 * drips a new client message onto the stalest thread once a minute, so the
 * whole pipeline — 10s sync, AI assignment, FCM, escalation, mobile chat —
 * runs realistically with zero network traffic. sendMessage() succeeds
 * without calling Freelancer. Response shapes mirror the real API exactly.
 *
 * State lives in storage/app/fake-freelancer.json; delete it to reset.
 */
class FakeFreelancerMessenger extends FreelancerMessenger
{
    private const STATE_FILE = 'fake-freelancer.json';
    private const MAX_THREADS = 5;
    private const NEW_MESSAGE_EVERY_SECONDS = 60;
    private const CLIENT_FL_USER_BASE = 111000;

    private const SAMPLE_MESSAGES = [
        'Hi, I saw your bid on my project. Are you available to start this week?',
        'Can you share some examples of similar work you have done?',
        'What is your estimated timeline for the first milestone?',
        'Thanks for the proposal. Could you clarify what is included in the price?',
        'Are you comfortable with weekly progress calls?',
        'I have attached the requirements document. Please take a look.',
        'Any update on this? I would like to move forward soon.',
        'Sounds good. What do you need from me to get started?',
    ];

    public function fetchThreads(): array
    {
        $state = $this->loadState();

        // One fake thread per proposal we bid on (capped).
        $proposals = Proposal::orderBy('id')->limit(self::MAX_THREADS)->get();

        foreach ($proposals as $index => $proposal) {
            $flThreadId = (string) (900000 + $proposal->id);
            if (isset($state['threads'][$flThreadId])) {
                continue;
            }

            $now = now()->timestamp;
            $state['threads'][$flThreadId] = [
                'project_id' => (int) $proposal->project_id,
                'client_fl_user_id' => self::CLIENT_FL_USER_BASE + $index,
                'time_created' => $now - 120,
                'time_updated' => $now,
                'messages' => [
                    $this->fabricateMessage($state, (int) $flThreadId, self::CLIENT_FL_USER_BASE + $index, $now - 120),
                    $this->fabricateMessage($state, (int) $flThreadId, self::CLIENT_FL_USER_BASE + $index, $now - 60),
                ],
            ];
        }

        // Drip: the stalest thread gets a fresh client message once a minute,
        // so incremental sync, re-notification, and escalation stay exercised.
        $this->maybeAddNewMessage($state);

        $this->saveState($state);

        return collect($state['threads'])->map(function (array $t, string $flThreadId) {
            return [
                'id' => (int) $flThreadId,
                'thread' => [
                    'members' => [(int) config('variables.flUserId'), $t['client_fl_user_id']],
                    'thread_type' => 'private_chat',
                    'context' => ['type' => 'project', 'id' => $t['project_id']],
                    'time_created' => $t['time_created'],
                ],
                'time_updated' => $t['time_updated'],
            ];
        })->values()->all();
    }

    public function fetchMessages(int $flThreadId, int $fromTime = 0): array
    {
        $state = $this->loadState();
        $thread = $state['threads'][(string) $flThreadId] ?? null;

        if (! $thread) {
            return [];
        }

        return array_values(array_filter(
            $thread['messages'],
            fn (array $m) => $m['time_created'] > $fromTime
        ));
    }

    public function markThreadRead(int $flThreadId): bool
    {
        return true; // nothing to mark in the fake
    }

    public function sendMessage(int $flThreadId, ?string $text, array $attachments = []): ?array
    {
        $state = $this->loadState();
        $id = $state['next_message_id']++;

        // Record our outbound message in fake state for realism; the syncer
        // ignores it anyway (own fl user id).
        if (isset($state['threads'][(string) $flThreadId])) {
            $state['threads'][(string) $flThreadId]['messages'][] = [
                'id' => $id,
                'thread_id' => $flThreadId,
                'from_user' => (int) config('variables.flUserId'),
                'message' => $text,
                'time_created' => now()->timestamp,
                'attachments' => [],
            ];
        }

        $this->saveState($state);

        return ['id' => $id, 'thread_id' => $flThreadId];
    }

    private function maybeAddNewMessage(array &$state): void
    {
        if (empty($state['threads'])) {
            return;
        }

        $stalest = collect($state['threads'])->sortBy('time_updated');
        $flThreadId = $stalest->keys()->first();
        $thread = $stalest->first();

        if (now()->timestamp - $thread['time_updated'] < self::NEW_MESSAGE_EVERY_SECONDS) {
            return;
        }

        $now = now()->timestamp;
        $state['threads'][$flThreadId]['messages'][] =
            $this->fabricateMessage($state, (int) $flThreadId, $thread['client_fl_user_id'], $now);
        $state['threads'][$flThreadId]['time_updated'] = $now;
    }

    private function fabricateMessage(array &$state, int $flThreadId, int $fromUser, int $time): array
    {
        $id = $state['next_message_id']++;

        $message = [
            'id' => $id,
            'thread_id' => $flThreadId,
            'from_user' => $fromUser,
            'message' => self::SAMPLE_MESSAGES[$id % count(self::SAMPLE_MESSAGES)],
            'time_created' => $time,
            'attachments' => [],
        ];

        // Every third message carries an attachment so that UI path is testable.
        if ($id % 3 === 0) {
            $message['attachments'][] = [
                'id' => $id * 10,
                'filename' => "requirements-{$id}.pdf",
                'url' => "https://fake-freelancer.local/attachments/requirements-{$id}.pdf",
                'mime_type' => 'application/pdf',
                'size' => 24576,
            ];
        }

        return $message;
    }

    private function loadState(): array
    {
        $raw = Storage::disk('local')->exists(self::STATE_FILE)
            ? json_decode(Storage::disk('local')->get(self::STATE_FILE), true)
            : null;

        return is_array($raw) ? $raw : ['threads' => [], 'next_message_id' => 1];
    }

    private function saveState(array $state): void
    {
        Storage::disk('local')->put(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    }
}
