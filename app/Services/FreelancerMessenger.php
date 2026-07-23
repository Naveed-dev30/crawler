<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Freelancer Messages API (api/messages/0.1).
 * Base URL comes from config('variables.flBase') so dev can point at the
 * Freelancer sandbox without code changes.
 */
class FreelancerMessenger
{
    private function base(): string
    {
        return rtrim(config('variables.flBase'), '/') . '/api/messages/0.1';
    }

    private function client(): PendingRequest
    {
        return Http::timeout(60)->withHeaders([
            'Freelancer-OAuth-V1' => config('variables.flKey'),
        ]);
    }

    /**
     * @return array<int, array> result.threads, [] on failure
     */
    public function fetchThreads(): array
    {
        try {
            $response = $this->client()->get($this->base() . '/threads/');

            if (!$response->successful()) {
                Log::warning('FreelancerMessenger threads: HTTP ' . $response->status());
                return [];
            }

            return $response->json('result.threads') ?? [];
        } catch (\Throwable $e) {
            Log::warning('FreelancerMessenger threads exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int, array> result.messages, [] on failure
     */
    public function fetchMessages(int $flThreadId, int $fromTime = 0): array
    {
        try {
            $params = ['threads[]' => $flThreadId, 'limit' => 200];
            if ($fromTime > 0) {
                $params['from_time'] = $fromTime;
            }

            $response = $this->client()->get($this->base() . '/messages/', $params);

            if (!$response->successful()) {
                Log::warning('FreelancerMessenger messages: HTTP ' . $response->status());
                return [];
            }

            return $response->json('result.messages') ?? [];
        } catch (\Throwable $e) {
            Log::warning('FreelancerMessenger messages exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Send a message (and/or attachments) to a thread.
     *
     * @param array<int, \Illuminate\Http\UploadedFile> $attachments
     * @return array|null decoded result message on success, null on failure
     */
    public function sendMessage(int $flThreadId, ?string $text, array $attachments = []): ?array
    {
        try {
            $request = $this->client();
            $url = $this->base() . "/threads/{$flThreadId}/messages/";

            if ($attachments !== []) {
                // Multipart: file parts plus one attachments[] name field per
                // file, mirroring the official SDK's post_attachment().
                foreach ($attachments as $file) {
                    // Stream, not string: an empty-string body would be dropped
                    // by the multipart builder and Guzzle rejects the part.
                    $request = $request->attach(
                        'files[]',
                        fopen($file->getRealPath(), 'r'),
                        $file->getClientOriginalName()
                    );
                    $request = $request->attach('attachments[]', $file->getClientOriginalName());
                }
                if ($text !== null && $text !== '') {
                    $request = $request->attach('message', $text);
                }
                $response = $request->post($url);
            } else {
                $response = $request->asForm()->post($url, ['message' => (string) $text]);
            }

            if (!$response->successful()) {
                Log::warning('FreelancerMessenger send: HTTP ' . $response->status() . ' ' . $response->body());
                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('FreelancerMessenger send exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark every message in a thread as read for our account.
     */
    public function markThreadRead(int $flThreadId): bool
    {
        try {
            $response = $this->client()->asForm()->put($this->base() . "/threads/{$flThreadId}/", [
                'action' => 'read',
            ]);

            if (!$response->successful()) {
                Log::warning('FreelancerMessenger markThreadRead: HTTP ' . $response->status());
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('FreelancerMessenger markThreadRead exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * URL a received attachment can be fetched from.
     */
    public function attachmentUrl(int $flMessageId, string $filename): string
    {
        return $this->base() . "/messages/{$flMessageId}/attachments/" . rawurlencode($filename);
    }
}
