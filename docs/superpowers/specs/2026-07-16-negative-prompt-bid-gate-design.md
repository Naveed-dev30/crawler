# Negative-Prompt Bid Gate — Design

**Date:** 2026-07-16
**Status:** Approved design, pending spec review

## Purpose

Let the operator define, in the Filters page, a free-text **Negative Prompt**
describing projects they do NOT want to bid on. Before a bid is generated for a
crawled proposal, an OpenAI call judges the proposal against that negative
prompt. If the proposal matches the unwanted criteria, it is skipped (no bid);
otherwise the normal bid flow proceeds.

This feature will be exercised end-to-end only in production (it needs a live
OpenAI key and the live crawl), so the design leans on isolated, faked-HTTP
automated tests as the correctness safety net.

## Semantics (the correctness crux)

- The Negative Prompt describes **unwanted** projects.
- The OpenAI qualify call returns exactly one word:
  - **`false`** when the proposal **MATCHES** the negative criteria → **skip** (no bid).
  - **`true`** when the proposal **does NOT match** → **proceed** with the bid.
- Mechanical rule everywhere: **`true` → proceed, `false` → skip.** The
  "negative" nature lives only in the prompt wording, not in the true/false rule.

## Decisions

- **Empty / null negative prompt** → gate disabled → bid proceeds exactly as
  today (no OpenAI qualify call).
- **On failure** (API error, timeout, or a reply that is not clearly `true`/`false`):
  **retry once**, then if still failing/ambiguous → **skip (fail-closed)** and log.
  Rationale: a placed bid is irreversible (money/reputation); skipping an
  occasional good proposal is cheap. The gate must never place a bid it did not
  approve.
- The qualify step **never throws into the queue job** — a failure results in a
  logged skip, not a crashed worker.
- Saving the Negative Prompt is **unconditional** (can be cleared to disable),
  unlike the existing `prompt` which only saves when non-empty.

## Scope

### In scope
- `filters.negative_prompt` column (nullable longText).
- Negative Prompt textarea in the Filters form + save in `FilterController`.
- `App\Services\ProposalQualifier` service.
- `OpenAIJob` gate call before bid creation.
- Automated tests (faked HTTP).

### Out of scope
- No change to the existing `prompt` (cover-letter) behavior.
- No change to crawling/filtering in `ProposalController`.
- No UI beyond the single textarea + helper text.
- No retry/queue-config changes to `OpenAIJob` itself beyond the gate.

## Data Model

Migration — add to `filters`:
- `negative_prompt` — `longText`, **nullable**, default `null`.

Revert = drop the column.

The `Filter` model uses direct property assignment (no `$fillable`), matching the
existing `prompt` usage. No model change strictly required; `negative_prompt` is
assigned directly like `prompt`.

## UI (Filters page)

File: `resources/views/content/pages/filters.blade.php`.

Add a Negative Prompt field mirroring the existing Prompt field (`filters.blade.php:123-125`):

```blade
<label class="form-label" for="formValidationNegativePrompt">Negative Prompt</label>
<textarea class="form-control" id="formValidationNegativePrompt"
          name="formValidationNegativePrompt" rows="3">{{ $filter->negative_prompt }}</textarea>
<small class="text-muted">Describe projects you DON'T want. A proposal matching this is skipped (no bid). Leave empty to disable.</small>
```

Placement: directly below the existing Prompt textarea block. No other markup changes.

## Save (FilterController)

File: `app/Http/Controllers/FilterController.php`, `update()` method.

Read the new field alongside the others (near `filters.blade.php` sibling reads,
`FilterController.php:94`):

```php
$negativePrompt = $request->formValidationNegativePrompt;
```

Persist it **unconditionally** (so it can be cleared) near where `prompt` is saved
(`FilterController.php:153-155`):

```php
$filter->negative_prompt = $negativePrompt ?? '';
```

Note the difference from `prompt`, which is guarded by `if ($prompt)`. The
negative prompt is always written so an empty submission disables the gate.

## ProposalQualifier Service

File: `app/Services/ProposalQualifier.php`.

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProposalQualifier
{
    private const MODEL = 'gpt-3.5-turbo';
    private const MAX_ATTEMPTS = 2; // initial try + 1 retry

    /**
     * Decide whether to proceed with a bid for a proposal, given the operator's
     * negative prompt. Returns true = proceed, false = skip.
     *
     * Fail-closed: any API error, timeout, or non-true/false reply after retries
     * returns false (skip).
     */
    public function qualify(string $negativePrompt, string $description): bool
    {
        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $system = 'You are a strict project filter. The user does NOT want to bid on '
            . 'projects matching these negative criteria: ' . $negativePrompt . '. '
            . 'Given the project description, reply with exactly one word — "false" if '
            . 'the project MATCHES the negative criteria (it should be skipped), or "true" '
            . 'if it does NOT match (safe to proceed). Reply only true or false, nothing else.';

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $description],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Authorization' => $bearer])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $raw = $response->json('choices.0.message.content');
                    $verdict = $this->parse($raw);
                    if ($verdict !== null) {
                        return $verdict;
                    }
                    Log::warning("ProposalQualifier: unparseable reply '" . trim((string) $raw) . "' (attempt {$attempt})");
                } else {
                    Log::warning('ProposalQualifier: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ProposalQualifier: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        // Fail-closed: could not get a clear verdict → skip.
        Log::info('ProposalQualifier: no clear verdict after retries → skipping proposal (fail-closed)');
        return false;
    }

    /**
     * Parse a model reply to a strict boolean. Returns null when the reply is not
     * unambiguously true or false.
     */
    private function parse(?string $raw): ?bool
    {
        $t = strtolower(trim((string) $raw));
        // Strip surrounding punctuation/quotes/periods the model may add.
        $t = trim($t, " \t\n\r\0\x0B.\"'`");

        if ($t === 'true') {
            return true;
        }
        if ($t === 'false') {
            return false;
        }
        return null;
    }
}
```

Design notes:
- `temperature => 0` for deterministic verdicts.
- `parse()` accepts only exact `true`/`false` (after trimming punctuation/quotes);
  anything else is `null` → ambiguous → retry → fail-closed. This deliberately
  refuses to guess on hedged replies.
- The service is stateless and injectable via `app(ProposalQualifier::class)`.

## OpenAIJob Integration

File: `app/Jobs/OpenAIJob.php`.

Insert the gate after the existing `crawler_on` guard (around `OpenAIJob.php:34-38`)
and before the cover-letter generation / bid creation (before `OpenAIJob.php:63`):

```php
$negative = trim((string) $filter->negative_prompt);
if ($negative !== '') {
    if (! app(\App\Services\ProposalQualifier::class)->qualify($negative, $this->proposal->description)) {
        \Log::info("Skip proposal (negative-prompt gate): {$this->proposal->project_id}");
        return; // no bid; job ends cleanly
    }
}
```

Everything below (cover-letter OpenAI call, `new Bid()`, save, `FineTuneBidJob::dispatch`)
is unchanged. A `false` verdict returns early → no Bid row is ever created for that
proposal.

`$filter` is already loaded in `handle()` (`Filter::find(1)`), so no extra query.

## Data Flow

1. Crawl saves a Proposal → dispatches `OpenAIJob($proposal)` (unchanged).
2. `OpenAIJob::handle()`:
   a. `crawler_on` false → return (unchanged).
   b. `negative_prompt` empty → skip gate, continue.
   c. `negative_prompt` set → `ProposalQualifier::qualify(negative, description)`:
      - `true` → continue to cover-letter + bid.
      - `false` (match, error, or ambiguous-after-retry) → log + return (no bid).
3. Continue: generate cover letter → create Bid → `FineTuneBidJob` (unchanged).

## Error Handling

- Qualify API error/timeout → caught, retried once, then fail-closed skip.
- Ambiguous reply → retried once, then fail-closed skip.
- All skips/errors logged with `project_id` (job) and reason (service).
- The service returns a bool; it never throws into `OpenAIJob`, so the queue
  worker cannot crash on a qualify failure.

## Testing

**ProposalQualifier (unit, `Http::fake`):**
- Reply `"true"` → returns `true`; asserts one request sent.
- Reply `"false"` → returns `false`.
- Reply `"TRUE\n"` / `" false. "` → normalized correctly (`true` / `false`).
- Garbage reply on both attempts → returns `false`; asserts 2 requests (retry).
- HTTP 500 on both attempts → returns `false` (fail-closed); asserts 2 requests.
- The system message sent contains the negative prompt text and the "true or
  false" instruction (assert via `Http::assertSent`).

**OpenAIJob (feature, `Http::fake`, `RefreshDatabase`):**
Seed a `Filter` (id 1, `crawler_on = true`) and a `Proposal`. Fake OpenAI so the
qualify call and the cover-letter call are distinguishable by request-body content
(qualify system message contains "strict project filter"; cover-letter uses the
filter's `prompt`).
- `negative_prompt` empty → no qualify call happens, Bid IS created.
- `negative_prompt` set, qualify returns `"true"` → Bid IS created.
- `negative_prompt` set, qualify returns `"false"` → NO Bid created (asserts
  `bids` count 0), skip logged.
- `negative_prompt` set, qualify errors (HTTP 500 both attempts) → NO Bid created
  (fail-closed).

**FilterController (feature):**
- Submitting the form with `formValidationNegativePrompt` set persists it on the
  filter.
- Submitting with the field empty clears `negative_prompt` (disables the gate).

Note: `OpenAIJob` currently reads `response['choices'][0]['message']['content']`
without a success check for the cover letter; the tests fake a successful
cover-letter response so that existing path is exercised unchanged. This design
does not modify that existing behavior.

## Revert Plan

1. Drop `filters.negative_prompt` (migration down).
2. Delete `ProposalQualifier`, its tests, the gate block in `OpenAIJob`, the
   textarea in `filters.blade.php`, and the `negative_prompt` read/save in
   `FilterController`.
Everything else (crawl, cover letter, bid flow) is unchanged.

## Future (not built now)
- Cache/dedupe qualify verdicts per project to save API calls.
- Surface skip reasons in the UI (e.g. a "skipped by gate" log/report).
- Make the qualify model/temperature configurable via the Filter.
