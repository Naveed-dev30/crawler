# Bid Feedback + Not-Qualified Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture and surface *why* each proposal qualified or was rejected by the negative-prompt gate, add an optional AI summary, and add a "Not Qualified" admin tab — leaving the bidding flow otherwise intact.

**Architecture:** The gate (`ProposalQualifier`) starts returning a reason alongside its boolean; `OpenAIJob` persists that on the already-existing `Proposal` and dispatches a gated `SummarizeReasonJob`. A new Filters "Summary Prompt" field controls the summary. A new page lists `qualified = false` proposals; bid detail shows the reason + summary.

**Tech Stack:** Laravel 10 (Sail), Eloquent, Blade, queued jobs, OpenAI `gpt-3.5-turbo` via `Http`, PHPUnit 10 feature tests (`RefreshDatabase`, `Http::fake`, `Bus::fake`). Run: `./vendor/bin/sail test`.

## Global Constraints

- New `proposals` columns are **nullable, no backfill**: `qualified` (boolean, default `null`), `qualify_reason` (longText), `qualify_summary` (longText). New `filters` column `summary_prompt` (longText, nullable).
- `qualified` semantics: `null` = gate didn't run, `true` = passed, `false` = rejected. The Not Qualified tab filters **strictly `qualified = false`** (excludes `null`).
- `ProposalQualifier::qualify()` returns `['qualified' => bool, 'reason' => string]`. Fail-closed: any error/unparseable reply → `['qualified' => false, 'reason' => '']`. Never throws.
- The summary is **gated**: `SummarizeReasonJob` does nothing unless `filters.summary_prompt` is non-empty and a reason exists. UI shows the literal string **"No summary available"** when `qualify_summary` is empty.
- Bidding flow, bid statuses, and Bids-section tabs are UNCHANGED. No skill-vs-failed split. Mobile API untouched.
- Old proposals (`qualified = null`) never appear in the Not Qualified tab and show no qualification block on bid detail.
- Never 500 on missing prompt/reason — guard and return early.

---

### Task 1: Migrations + Proposal model cast & scope

**Files:**
- Create: `database/migrations/2026_07_17_100000_add_qualification_to_proposals.php`
- Create: `database/migrations/2026_07_17_100100_add_summary_prompt_to_filters.php`
- Modify: `app/Models/Proposal.php`
- Test: `tests/Feature/ProposalQualificationSchemaTest.php`

**Interfaces:**
- Produces: `proposals.qualified` (bool cast), `proposals.qualify_reason`, `proposals.qualify_summary`, `filters.summary_prompt`; `Proposal::notQualified()` scope (`where('qualified', false)`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProposalQualificationSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalQualificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualified_is_cast_to_boolean(): void
    {
        $p = Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'matched crypto']);
        $this->assertIsBool($p->fresh()->qualified);
        $this->assertFalse($p->fresh()->qualified);
        $this->assertSame('matched crypto', $p->fresh()->qualify_reason);
    }

    public function test_not_qualified_scope_returns_only_false_not_null(): void
    {
        Proposal::factory()->create(['qualified' => false]);
        Proposal::factory()->create(['qualified' => true]);
        Proposal::factory()->create(['qualified' => null]);

        $ids = Proposal::notQualified()->pluck('qualified')->all();
        $this->assertCount(1, $ids);
        $this->assertFalse((bool) $ids[0]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=ProposalQualificationSchemaTest`
Expected: FAIL (unknown column `qualified` / no `notQualified` scope).

- [ ] **Step 3: Create the proposals migration**

`database/migrations/2026_07_17_100000_add_qualification_to_proposals.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->boolean('qualified')->nullable()->default(null)->after('review_label');
            $table->longText('qualify_reason')->nullable()->after('qualified');
            $table->longText('qualify_summary')->nullable()->after('qualify_reason');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['qualified', 'qualify_reason', 'qualify_summary']);
        });
    }
};
```

- [ ] **Step 4: Create the filters migration**

`database/migrations/2026_07_17_100100_add_summary_prompt_to_filters.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->longText('summary_prompt')->nullable()->after('negative_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->dropColumn('summary_prompt');
        });
    }
};
```

- [ ] **Step 5: Add cast + scope to `Proposal`**

In `app/Models/Proposal.php`, update the `$casts` array and add the scope. The class currently has `protected $casts = ['skills' => 'array'];` and a `scopeNeedsReview`. Change to:

```php
    protected $casts = [
        'skills' => 'array',
        'qualified' => 'boolean',
    ];
```

And add this method inside the class (next to `scopeNeedsReview`):

```php
    public function scopeNotQualified($query)
    {
        return $query->where('qualified', false);
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=ProposalQualificationSchemaTest`
Expected: PASS (2 passed).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_17_100000_add_qualification_to_proposals.php database/migrations/2026_07_17_100100_add_summary_prompt_to_filters.php app/Models/Proposal.php tests/Feature/ProposalQualificationSchemaTest.php
git commit -m "feat: add qualification columns + notQualified scope"
```

---

### Task 2: ProposalQualifier returns reason

**Files:**
- Modify: `app/Services/ProposalQualifier.php`
- Test: `tests/Feature/ProposalQualifierTest.php` (rewrite for new return shape)

**Interfaces:**
- Consumes: OpenAI chat API (faked in tests).
- Produces: `ProposalQualifier::qualify(string $negativePrompt, string $description): array` returning `['qualified' => bool, 'reason' => string]`.

- [ ] **Step 1: Rewrite the test for the new contract**

Replace the entire contents of `tests/Feature/ProposalQualifierTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Services\ProposalQualifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProposalQualifierTest extends TestCase
{
    private function fakeReply(string $content, int $status = 200): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]],
                $status
            ),
        ]);
    }

    private function qualifier(): ProposalQualifier
    {
        return new ProposalQualifier();
    }

    public function test_true_reply_returns_qualified_true_with_reason(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "No crypto or gambling; safe web project."}');
        $result = $this->qualifier()->qualify('no crypto', 'A Laravel API project');

        $this->assertTrue($result['qualified']);
        $this->assertStringContainsString('safe web project', $result['reason']);
        Http::assertSentCount(1);
    }

    public function test_false_reply_returns_qualified_false_with_reason(): void
    {
        $this->fakeReply('{"qualified": false, "reason": "Matches negative criteria: crypto trading."}');
        $result = $this->qualifier()->qualify('no crypto', 'A crypto trading bot');

        $this->assertFalse($result['qualified']);
        $this->assertStringContainsString('crypto', $result['reason']);
    }

    public function test_reply_wrapped_in_markdown_fence_is_parsed(): void
    {
        $this->fakeReply("```json\n{\"qualified\": false, \"reason\": \"gambling\"}\n```");
        $result = $this->qualifier()->qualify('no gambling', 'A poker app');

        $this->assertFalse($result['qualified']);
        $this->assertSame('gambling', $result['reason']);
    }

    public function test_http_error_retries_then_fails_closed(): void
    {
        $this->fakeReply('', 500);
        $result = $this->qualifier()->qualify('x', 'y');

        $this->assertFalse($result['qualified']);
        $this->assertSame('', $result['reason']);
        Http::assertSentCount(2); // initial + 1 retry
    }

    public function test_unparseable_reply_fails_closed(): void
    {
        $this->fakeReply('maybe, not sure');
        $result = $this->qualifier()->qualify('x', 'y');

        $this->assertFalse($result['qualified']);
        $this->assertSame('', $result['reason']);
    }

    public function test_system_message_contains_negative_prompt_and_json_instruction(): void
    {
        $this->fakeReply('{"qualified": true, "reason": "ok"}');
        $this->qualifier()->qualify('no gambling sites', 'A poker app');

        Http::assertSent(function ($request) {
            $system = strtolower($request->data()['messages'][0]['content'] ?? '');
            return str_contains($system, 'no gambling sites')
                && str_contains($system, 'json')
                && str_contains($system, 'qualified')
                && str_contains($system, 'reason');
        });
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=ProposalQualifierTest`
Expected: FAIL (`qualify()` returns bool, not array; `$result['qualified']` errors).

- [ ] **Step 3: Rewrite `ProposalQualifier`**

Replace the entire contents of `app/Services/ProposalQualifier.php` with:

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
     * negative prompt, and capture the model's reason.
     *
     * @return array{qualified: bool, reason: string}
     *
     * Fail-closed: any API error, timeout, or unparseable reply after retries
     * returns ['qualified' => false, 'reason' => '']. Never throws.
     */
    public function qualify(string $negativePrompt, string $description): array
    {
        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $system = 'You are a strict project filter. The user does NOT want to bid on '
            . 'projects matching these negative criteria: ' . $negativePrompt . '. '
            . 'Given the project description, decide whether to skip it. Reply with ONLY a '
            . 'JSON object of the form {"qualified": <true|false>, "reason": "<short reason>"}. '
            . 'Set "qualified" to false if the project MATCHES the negative criteria (skip it), '
            . 'or true if it does NOT match (safe to proceed). "reason" is a short phrase '
            . 'naming the criteria matched or why it is safe. Output nothing but the JSON.';

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
                    $parsed = $this->parse($response->json('choices.0.message.content'));
                    if ($parsed !== null) {
                        return $parsed;
                    }
                    Log::warning('ProposalQualifier: unparseable reply (attempt ' . $attempt . ')');
                } else {
                    Log::warning('ProposalQualifier: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ProposalQualifier: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        Log::info('ProposalQualifier: no clear verdict after retries → skipping proposal (fail-closed)');

        return ['qualified' => false, 'reason' => ''];
    }

    /**
     * Parse a model reply (a JSON object, possibly wrapped in a markdown fence)
     * into ['qualified' => bool, 'reason' => string]. Returns null when the reply
     * has no usable boolean "qualified".
     *
     * @return array{qualified: bool, reason: string}|null
     */
    private function parse(?string $raw): ?array
    {
        $text = trim((string) $raw);

        // Strip a leading/trailing markdown code fence if present.
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (! is_array($data) || ! array_key_exists('qualified', $data) || ! is_bool($data['qualified'])) {
            return null;
        }

        return [
            'qualified' => $data['qualified'],
            'reason' => is_string($data['reason'] ?? null) ? trim($data['reason']) : '',
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=ProposalQualifierTest`
Expected: PASS (6 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProposalQualifier.php tests/Feature/ProposalQualifierTest.php
git commit -m "feat: ProposalQualifier returns qualified + reason"
```

---

### Task 3: SummarizeReasonJob (gated OpenAI summary)

**Files:**
- Create: `app/Jobs/SummarizeReasonJob.php`
- Test: `tests/Feature/SummarizeReasonJobTest.php`

**Interfaces:**
- Consumes: `Filter::find(1)->summary_prompt` (from Task 1), `Proposal::qualify_reason`.
- Produces: `SummarizeReasonJob` (constructed with a `Proposal`); on `handle()` writes `proposal.qualify_summary` when gated conditions are met.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SummarizeReasonJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SummarizeReasonJob;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SummarizeReasonJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSummary(string $content): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]],
                200
            ),
        ]);
    }

    public function test_writes_summary_when_prompt_and_reason_present(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'Summarize the reason in two lines.']);
        $p = Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'Matches crypto criteria', 'qualify_summary' => null]);
        $this->fakeSummary("Line one.\nLine two.");

        (new SummarizeReasonJob($p))->handle();

        $this->assertSame("Line one.\nLine two.", $p->fresh()->qualify_summary);
        Http::assertSentCount(1);
    }

    public function test_does_nothing_without_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => '']);
        $p = Proposal::factory()->create(['qualify_reason' => 'Matches crypto criteria', 'qualify_summary' => null]);
        Http::fake();

        (new SummarizeReasonJob($p))->handle();

        $this->assertNull($p->fresh()->qualify_summary);
        Http::assertNothingSent();
    }

    public function test_does_nothing_without_reason(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'Summarize.']);
        $p = Proposal::factory()->create(['qualify_reason' => null, 'qualify_summary' => null]);
        Http::fake();

        (new SummarizeReasonJob($p))->handle();

        $this->assertNull($p->fresh()->qualify_summary);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=SummarizeReasonJobTest`
Expected: FAIL (class `SummarizeReasonJob` not found).

- [ ] **Step 3: Create the job**

`app/Jobs/SummarizeReasonJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SummarizeReasonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Proposal $proposal;

    public function __construct(Proposal $proposal)
    {
        $this->proposal = $proposal;
    }

    public function handle(): void
    {
        $filter = Filter::find(1);
        $summaryPrompt = trim((string) ($filter->summary_prompt ?? ''));
        if ($summaryPrompt === '') {
            return; // gated: no summary prompt configured
        }

        $reason = trim((string) $this->proposal->qualify_reason);
        if ($reason === '') {
            return; // nothing to summarize
        }

        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-3.5-turbo',
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $summaryPrompt],
                ['role' => 'user', 'content' => $reason],
            ],
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Authorization' => $bearer])
                ->post($url, $data);

            if ($response->successful()) {
                $summary = trim((string) $response->json('choices.0.message.content'));
                if ($summary !== '') {
                    $this->proposal->qualify_summary = $summary;
                    $this->proposal->save();
                }
            } else {
                Log::warning('SummarizeReasonJob: HTTP ' . $response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('SummarizeReasonJob: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=SummarizeReasonJobTest`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SummarizeReasonJob.php tests/Feature/SummarizeReasonJobTest.php
git commit -m "feat: add gated SummarizeReasonJob"
```

---

### Task 4: OpenAIJob persists flag + dispatches summary

**Files:**
- Modify: `app/Jobs/OpenAIJob.php`
- Test: `tests/Feature/OpenAIJobGateTest.php`

**Interfaces:**
- Consumes: `ProposalQualifier::qualify()` (array, Task 2); `SummarizeReasonJob` (Task 3); `proposals` columns (Task 1).
- Produces: after the gate runs, `proposal.qualified` + `proposal.qualify_reason` are set; `SummarizeReasonJob` dispatched when `summary_prompt` set and reason non-empty; bid skipped when `qualified` is false.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OpenAIJobGateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\OpenAIJob;
use App\Jobs\SummarizeReasonJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIJobGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_reject_flags_proposal_and_skips_bid(): void
    {
        Bus::fake([SummarizeReasonJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'summary_prompt' => 'Summarize.', 'prompt' => 'Write a cover letter.']);
        $proposal = Proposal::factory()->create(['description' => 'A crypto trading bot', 'qualified' => null]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => '{"qualified": false, "reason": "crypto trading"}']]]],
                200
            ),
        ]);

        (new OpenAIJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertFalse($fresh->qualified);
        $this->assertSame('crypto trading', $fresh->qualify_reason);
        $this->assertSame(0, Bid::where('proposal_id', $proposal->id)->count());
        Bus::assertDispatched(SummarizeReasonJob::class);
    }

    public function test_gate_pass_flags_qualified_and_creates_bid(): void
    {
        Bus::fake([SummarizeReasonJob::class, \App\Jobs\FineTuneBidJob::class]);
        Filter::factory()->create(['id' => 1, 'crawler_on' => true, 'negative_prompt' => 'no crypto', 'summary_prompt' => '', 'prompt' => 'Write a cover letter.']);
        $proposal = Proposal::factory()->create(['description' => 'A Laravel API', 'max_budget' => 500, 'qualified' => null]);

        // First call = qualifier (JSON), second call = cover letter.
        Http::fakeSequence('https://api.openai.com/*')
            ->push(['choices' => [['message' => ['content' => '{"qualified": true, "reason": "safe web project"}']]]], 200)
            ->push(['choices' => [['message' => ['content' => 'Dear client, ...']]]], 200);

        (new OpenAIJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertTrue($fresh->qualified);
        $this->assertSame('safe web project', $fresh->qualify_reason);
        $this->assertSame(1, Bid::where('proposal_id', $proposal->id)->count());
        // summary_prompt empty → summary job NOT dispatched
        Bus::assertNotDispatched(SummarizeReasonJob::class);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=OpenAIJobGateTest`
Expected: FAIL (proposal not flagged; `qualify()` treated as bool; summary job never dispatched).

- [ ] **Step 3: Update `OpenAIJob`**

In `app/Jobs/OpenAIJob.php`, replace the negative-prompt block (currently lines 41–47):

```php
        $negative = trim((string) $filter->negative_prompt);
        if ($negative !== '') {
            if (! app(\App\Services\ProposalQualifier::class)->qualify($negative, $this->proposal->description)) {
                \Log::info("Skip proposal (negative-prompt gate): {$this->proposal->project_id}");
                return;
            }
        }
```

with:

```php
        $negative = trim((string) $filter->negative_prompt);
        if ($negative !== '') {
            $verdict = app(\App\Services\ProposalQualifier::class)->qualify($negative, $this->proposal->description);

            $this->proposal->qualified = $verdict['qualified'];
            $this->proposal->qualify_reason = $verdict['reason'];
            $this->proposal->save();

            $summaryPrompt = trim((string) ($filter->summary_prompt ?? ''));
            if ($summaryPrompt !== '' && $verdict['reason'] !== '') {
                \App\Jobs\SummarizeReasonJob::dispatch($this->proposal);
            }

            if (! $verdict['qualified']) {
                \Log::info("Skip proposal (negative-prompt gate): {$this->proposal->project_id}");
                return;
            }
        }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=OpenAIJobGateTest`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/OpenAIJob.php tests/Feature/OpenAIJobGateTest.php
git commit -m "feat: OpenAIJob flags proposal qualification + dispatches summary"
```

---

### Task 5: Filters Summary Prompt input

**Files:**
- Modify: `resources/views/content/pages/filters.blade.php`
- Modify: `app/Http/Controllers/FilterController.php`
- Test: `tests/Feature/FilterSummaryPromptSaveTest.php`

**Interfaces:**
- Consumes: `filters.summary_prompt` column (Task 1).
- Produces: `/updateFilters` persists `summary_prompt` from `formValidationSummaryPrompt`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FilterSummaryPromptSaveTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterSummaryPromptSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_update_persists_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationSummaryPrompt' => 'Summarize the reason in two lines.'])
            ->assertRedirect('/filters');

        $this->assertSame('Summarize the reason in two lines.', Filter::find(1)->summary_prompt);
    }

    public function test_update_can_clear_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'old value']);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationSummaryPrompt' => ''])
            ->assertRedirect('/filters');

        $this->assertSame('', Filter::find(1)->fresh()->summary_prompt);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=FilterSummaryPromptSaveTest`
Expected: FAIL (`summary_prompt` never set → null, not the posted value).

- [ ] **Step 3: Handle the field in `FilterController::update`**

In `app/Http/Controllers/FilterController.php`, inside `update()`, just after the line
`$filter->negative_prompt = $negativePrompt ?? '';` add:

```php
            $filter->summary_prompt = $request->formValidationSummaryPrompt ?? '';
```

- [ ] **Step 4: Add the Summary Prompt textarea to the Filters view**

In `resources/views/content/pages/filters.blade.php`, right after the Negative Prompt textarea (the block ending at the `</textarea>` on the `formValidationNegativePrompt` field), add:

```blade
                        <label class="form-label mt-3" for="formValidationSummaryPrompt">Summary Prompt</label>
                        <textarea class="form-control" id="formValidationSummaryPrompt"
                                  name="formValidationSummaryPrompt" rows="3">{{ $filter->summary_prompt }}</textarea>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=FilterSummaryPromptSaveTest`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add resources/views/content/pages/filters.blade.php app/Http/Controllers/FilterController.php tests/Feature/FilterSummaryPromptSaveTest.php
git commit -m "feat: add Summary Prompt field to filters"
```

---

### Task 6: Not Qualified admin page

**Files:**
- Create: `app/Http/Controllers/NotQualifiedController.php`
- Create: `resources/views/content/pages/not-qualified.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/menu/verticalMenu.json`
- Test: `tests/Feature/NotQualifiedPageTest.php`

**Interfaces:**
- Consumes: `Proposal::notQualified()` scope (Task 1).
- Produces: `GET /not-qualified` (name `not-qualified`) → `NotQualifiedController@index`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NotQualifiedPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotQualifiedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/not-qualified')->assertRedirect('/login');
    }

    public function test_lists_only_not_qualified_proposals(): void
    {
        $rejected = Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'crypto match', 'title' => 'Rejected One']);
        Proposal::factory()->create(['qualified' => true, 'title' => 'Passed One']);
        Proposal::factory()->create(['qualified' => null, 'title' => 'Ungated One']);

        $res = $this->actingAs(User::factory()->create())->get('/not-qualified')->assertOk();
        $res->assertSee('Rejected One');
        $res->assertSee('crypto match');
        $res->assertDontSee('Passed One');
        $res->assertDontSee('Ungated One');
    }

    public function test_shows_no_summary_available_when_summary_empty(): void
    {
        Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'r', 'qualify_summary' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/not-qualified')->assertOk()
            ->assertSee('No summary available');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=NotQualifiedPageTest`
Expected: FAIL (route `/not-qualified` returns 404).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/NotQualifiedController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Proposal;

class NotQualifiedController extends Controller
{
    public function index()
    {
        $proposals = Proposal::notQualified()
            ->latest()
            ->paginate(50);

        return view('content.pages.not-qualified', ['proposals' => $proposals]);
    }
}
```

- [ ] **Step 4: Create the view**

`resources/views/content/pages/not-qualified.blade.php`:

```blade
@extends('layouts.layoutMaster')

@section('title', 'Not Qualified')

@section('content')
    <h4 class="page-title">Not Qualified</h4>

    <div class="card">
        <div class="table-responsive text-nowrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Title</th>
                        <th>Reason</th>
                        <th>Summary</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposals as $proposal)
                        <tr>
                            <td>{{ $proposal->project_id }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($proposal->title, 40) }}</td>
                            <td><span class="fw-bold">{{ $proposal->qualify_reason }}</span></td>
                            <td>
                                @if (trim((string) $proposal->qualify_summary) !== '')
                                    <span class="fw-light">{{ $proposal->qualify_summary }}</span>
                                @else
                                    <span class="text-muted fst-italic">No summary available</span>
                                @endif
                            </td>
                            <td>{{ $proposal->created_at->diffForHumans(null, true) }}</td>
                            <td>
                                <a href="https://www.freelancer.com/projects/{{ $proposal->project_id }}" target="_blank" class="btn btn-sm btn-label-primary">
                                    <i class="fa fa-external-link me-1"></i> View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No not-qualified proposals yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $proposals->links('vendor.pagination.bootstrap-5') }}
    </div>
@endsection
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `Route::middleware(['auth'])->group(...)` block (next to the `/review` routes), add:

```php
    Route::get('/not-qualified', [\App\Http\Controllers\NotQualifiedController::class, 'index'])->name('not-qualified');
```

- [ ] **Step 6: Add the sidebar entry**

In `resources/menu/verticalMenu.json`, add this object to the `menu` array, right after the `review` entry:

```json
    {
      "url": "/not-qualified",
      "name": "Not Qualified",
      "icon": "menu-icon tf-icons bx bx-block",
      "slug": "not-qualified"
    },
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=NotQualifiedPageTest`
Expected: PASS (3 passed).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/NotQualifiedController.php resources/views/content/pages/not-qualified.blade.php routes/web.php resources/menu/verticalMenu.json tests/Feature/NotQualifiedPageTest.php
git commit -m "feat: add Not Qualified admin page + sidebar"
```

---

### Task 7: Bid detail shows reason + summary

**Files:**
- Modify: `resources/views/_partials/bid-detail.blade.php`
- Test: `tests/Feature/BidDetailQualificationTest.php`

**Interfaces:**
- Consumes: `proposals.qualify_reason` / `qualify_summary` via `$bid->proposal`.
- Produces: a "Qualification" block on the bid detail offcanvas, shown only when `qualify_reason` is present.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidDetailQualificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidDetailQualificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_shows_reason_and_no_summary_placeholder(): void
    {
        $proposal = Proposal::factory()->create(['qualified' => true, 'qualify_reason' => 'safe web project', 'qualify_summary' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs(User::factory()->create())
            ->get("/bids/{$bid->id}/detail")->assertOk()
            ->assertSee('safe web project')
            ->assertSee('No summary available');
    }

    public function test_detail_hides_block_when_no_reason(): void
    {
        $proposal = Proposal::factory()->create(['qualified' => null, 'qualify_reason' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs(User::factory()->create())
            ->get("/bids/{$bid->id}/detail")->assertOk()
            ->assertDontSee('Qualification');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=BidDetailQualificationTest`
Expected: FAIL (no Qualification block rendered).

- [ ] **Step 3: Add the Qualification block to the bid detail**

In `resources/views/_partials/bid-detail.blade.php`, add this block just before the
`<div class="divider divider-primary"><div class="divider-text">Bid</div></div>` line:

```blade
    @if (trim((string) $bid->proposal->qualify_reason) !== '')
        <div class="divider divider-primary"><div class="divider-text">Qualification</div></div>
        <h6>Reason:</h6>
        <span class="fw-bold">{{ $bid->proposal->qualify_reason }}</span>
        <h6 class="mt-3">Summary:</h6>
        @if (trim((string) $bid->proposal->qualify_summary) !== '')
            <span class="fw-light">{{ $bid->proposal->qualify_summary }}</span>
        @else
            <span class="text-muted fst-italic">No summary available</span>
        @endif
    @endif
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=BidDetailQualificationTest`
Expected: PASS (2 passed).

- [ ] **Step 5: Run the full suite to confirm nothing regressed**

Run: `./vendor/bin/sail test`
Expected: PASS (all green, including the new tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/_partials/bid-detail.blade.php tests/Feature/BidDetailQualificationTest.php
git commit -m "feat: show qualification reason + summary on bid detail"
```

---

## Notes for the implementer

- The `Filter` factory must support `summary_prompt`, `crawler_on`, `negative_prompt`, `prompt`, and `id` overrides. Check `database/factories/FilterFactory.php`; if a column is missing from the factory it is fine as long as `create([...])` sets it explicitly (the columns exist after Task 1's migration).
- `Http::fakeSequence('https://api.openai.com/*')` requires the two calls in Task 4's pass-case to hit the same host — both the qualifier and the cover-letter call use `https://api.openai.com/v1/chat/completions`, so the sequence order is qualifier-first, cover-letter-second.
- Do not alter bid statuses, the Bids tabs, or the mobile API in any task.
