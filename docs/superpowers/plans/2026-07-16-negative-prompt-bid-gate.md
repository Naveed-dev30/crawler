# Negative-Prompt Bid Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an operator-defined "Negative Prompt" filter that, before each bid, asks OpenAI whether a crawled proposal matches unwanted criteria — skipping the bid when it does.

**Architecture:** A nullable `filters.negative_prompt` column feeds a new `ProposalQualifier` service that calls OpenAI (temperature 0), parses a strict `true`/`false`, retries once, and fails closed (skip) on error/ambiguity. `OpenAIJob` calls the service before creating a Bid: `false` → log + return (no bid); `true` or empty prompt → normal flow. UI is one textarea on the Filters page, saved unconditionally so it can be cleared to disable the gate.

**Tech Stack:** Laravel 10, Eloquent, Laravel `Http` client + `Http::fake`, PHPUnit 10 Feature tests (RefreshDatabase), Blade, Sail (Docker).

## Global Constraints

- **Polarity:** the OpenAI qualify call returns `false` when the proposal MATCHES the unwanted (negative) criteria → **skip**; `true` when it does NOT match → **proceed**. Mechanical rule everywhere: **`true` → proceed with bid, `false` → skip.**
- **Empty/null `negative_prompt`** → gate disabled → bid proceeds exactly as today (NO qualify call).
- **Fail-closed:** any API error, timeout, or reply that is not exactly `true`/`false` → **retry once**, then return `false` (skip). The qualifier NEVER throws into `OpenAIJob`.
- `ProposalQualifier` constants: `MODEL = 'gpt-3.5-turbo'`, `MAX_ATTEMPTS = 2` (initial + 1 retry), `temperature => 0`.
- **Exact OpenAI system message** (verbatim): `You are a strict project filter. The user does NOT want to bid on projects matching these negative criteria: {negativePrompt}. Given the project description, reply with exactly one word — "false" if the project MATCHES the negative criteria (it should be skipped), or "true" if it does NOT match (safe to proceed). Reply only true or false, nothing else.`
- `negative_prompt` is saved **unconditionally** in `FilterController::update()` (unlike `prompt`, which is guarded by `if ($prompt)`), so an empty submission clears it.
- Do NOT change existing behavior: the cover-letter OpenAI call, bid creation, `FineTuneBidJob` dispatch, crawling, or the existing `prompt`.
- All commands run via Sail: `./vendor/bin/sail test --filter=<Name>`.
- Known pre-existing unrelated failure: `ExampleTest` (root `/` returns 302). Ignore it.

---

### Task 1: Migration — `filters.negative_prompt`

**Files:**
- Create: `database/migrations/2026_07_16_010000_add_negative_prompt_to_filters.php`
- Test: `tests/Feature/NegativePromptColumnTest.php`

**Interfaces:**
- Produces: nullable `filters.negative_prompt` longText column; assignable as `$filter->negative_prompt`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NegativePromptColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Filter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NegativePromptColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_negative_prompt_is_persisted_and_nullable(): void
    {
        $filter = Filter::factory()->create();
        $this->assertNull($filter->fresh()->negative_prompt);

        $filter->negative_prompt = 'no crypto projects';
        $filter->save();
        $this->assertSame('no crypto projects', $filter->fresh()->negative_prompt);

        $filter->negative_prompt = '';
        $filter->save();
        $this->assertSame('', $filter->fresh()->negative_prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=NegativePromptColumnTest`
Expected: FAIL — column `negative_prompt` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_16_010000_add_negative_prompt_to_filters.php`:

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
            $table->longText('negative_prompt')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->dropColumn('negative_prompt');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=NegativePromptColumnTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_16_010000_add_negative_prompt_to_filters.php tests/Feature/NegativePromptColumnTest.php
git commit -m "feat: add nullable negative_prompt column to filters"
```

---

### Task 2: `ProposalQualifier` service

**Files:**
- Create: `app/Services/ProposalQualifier.php`
- Test: `tests/Feature/ProposalQualifierTest.php`

**Interfaces:**
- Produces: `App\Services\ProposalQualifier` with public method `qualify(string $negativePrompt, string $description): bool` — `true` = proceed, `false` = skip. Calls OpenAI at `https://api.openai.com/v1/chat/completions`, up to 2 attempts, fail-closed to `false`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProposalQualifierTest.php`:

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

    public function test_true_reply_returns_true_and_sends_one_request(): void
    {
        $this->fakeReply('true');
        $this->assertTrue($this->qualifier()->qualify('no crypto', 'A Laravel API project'));
        Http::assertSentCount(1);
    }

    public function test_false_reply_returns_false(): void
    {
        $this->fakeReply('false');
        $this->assertFalse($this->qualifier()->qualify('no crypto', 'A crypto trading bot'));
    }

    public function test_reply_is_normalized(): void
    {
        $this->fakeReply("TRUE\n");
        $this->assertTrue($this->qualifier()->qualify('x', 'y'));

        $this->fakeReply(' false. ');
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
    }

    public function test_ambiguous_reply_retries_then_fails_closed(): void
    {
        $this->fakeReply('maybe not sure');
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
        Http::assertSentCount(2); // initial + 1 retry
    }

    public function test_http_error_retries_then_fails_closed(): void
    {
        $this->fakeReply('', 500);
        $this->assertFalse($this->qualifier()->qualify('x', 'y'));
        Http::assertSentCount(2);
    }

    public function test_system_message_contains_negative_prompt_and_instruction(): void
    {
        $this->fakeReply('true');
        $this->qualifier()->qualify('no gambling sites', 'A poker app');

        Http::assertSent(function ($request) {
            $system = strtolower($request->data()['messages'][0]['content'] ?? '');
            return str_contains($system, 'no gambling sites')
                && str_contains($system, 'strict project filter')
                && str_contains($system, 'reply only true or false');
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=ProposalQualifierTest`
Expected: FAIL — class `App\Services\ProposalQualifier` not found.

- [ ] **Step 3: Write the service**

Create `app/Services/ProposalQualifier.php`:

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
     * returns false (skip). Never throws.
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

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=ProposalQualifierTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProposalQualifier.php tests/Feature/ProposalQualifierTest.php
git commit -m "feat: add ProposalQualifier service (fail-closed OpenAI bid gate)"
```

---

### Task 3: Filters UI + save

**Files:**
- Modify: `resources/views/content/pages/filters.blade.php` (add textarea after the Prompt block at `:123-125`)
- Modify: `app/Http/Controllers/FilterController.php` (`update()` — read + save `negative_prompt`)
- Test: `tests/Feature/FilterNegativePromptSaveTest.php`

**Interfaces:**
- Consumes: `filters.negative_prompt` column (Task 1).
- Produces: `POST /updateFilters` persists `formValidationNegativePrompt` to `filters.negative_prompt` unconditionally (empty clears it).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FilterNegativePromptSaveTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterNegativePromptSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // User factory defaults role => 'admin', so this passes EnsureAdmin.
        return User::factory()->create();
    }

    public function test_update_persists_negative_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationNegativePrompt' => 'no crypto or gambling'])
            ->assertRedirect('/filters');

        $this->assertSame('no crypto or gambling', Filter::find(1)->negative_prompt);
    }

    public function test_update_can_clear_negative_prompt(): void
    {
        $filter = Filter::factory()->create(['id' => 1, 'negative_prompt' => 'old value']);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationNegativePrompt' => ''])
            ->assertRedirect('/filters');

        $this->assertSame('', $filter->fresh()->negative_prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=FilterNegativePromptSaveTest`
Expected: FAIL — `negative_prompt` stays null (controller does not read/save it).

- [ ] **Step 3: Read the new field in `FilterController::update()`**

In `app/Http/Controllers/FilterController.php`, in `update()`, add the read next to the existing `$prompt` line (`FilterController.php:94`):

```php
            $prompt = $request->formValidationPrompt;
            $negativePrompt = $request->formValidationNegativePrompt;
```

- [ ] **Step 4: Save the field unconditionally**

In the same method, immediately after the existing prompt-save block (`FilterController.php:153-155`):

```php
            if ($prompt) {
                $filter->prompt = $prompt;
            }

            $filter->negative_prompt = $negativePrompt ?? '';
```

- [ ] **Step 5: Add the textarea to the Filters view**

In `resources/views/content/pages/filters.blade.php`, immediately after the existing Prompt block (the `<textarea ... name="formValidationPrompt" ...>` at `:123-125` and its closing wrapper), add:

```blade
                        <label class="form-label mt-3" for="formValidationNegativePrompt">Negative Prompt</label>
                        <textarea class="form-control" id="formValidationNegativePrompt"
                                  name="formValidationNegativePrompt" rows="3">{{ $filter->negative_prompt }}</textarea>
                        <small class="text-muted">Describe projects you DON'T want. A proposal matching this is skipped (no bid). Leave empty to disable.</small>
```

(Place it inside the same column/container as the Prompt textarea so layout matches. If the Prompt textarea sits in a wrapping `<div>...</div>`, add this block right after that closing `</div>` within the same parent.)

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=FilterNegativePromptSaveTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/FilterController.php resources/views/content/pages/filters.blade.php tests/Feature/FilterNegativePromptSaveTest.php
git commit -m "feat: add Negative Prompt field to filters form and save"
```

---

### Task 4: `OpenAIJob` gate integration

**Files:**
- Modify: `app/Jobs/OpenAIJob.php` (insert gate after the `crawler_on` guard, before cover-letter/bid at `:63`)
- Test: `tests/Feature/OpenAIJobNegativePromptTest.php`

**Interfaces:**
- Consumes: `Filter::negative_prompt` (Task 1); `App\Services\ProposalQualifier::qualify()` (Task 2).
- Produces: `OpenAIJob::handle()` skips bid creation (returns early, no Bid row) when the gate returns `false`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OpenAIJobNegativePromptTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\OpenAIJob;
use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OpenAIJobNegativePromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // prevent FineTuneBidJob from running real OpenAI calls
    }

    private function seedFilter(string $negativePrompt): void
    {
        Filter::factory()->create([
            'id' => 1,
            'crawler_on' => true,
            'prompt' => 'Write a cover letter.',
            'negative_prompt' => $negativePrompt,
        ]);
    }

    private function makeProposal(): Proposal
    {
        return Proposal::factory()->create([
            'description' => 'A Laravel API project',
            'max_budget' => 1000,
        ]);
    }

    /**
     * Fake OpenAI: the qualify call (system message contains "strict project filter")
     * returns $verdict; any other call (the cover letter) returns letter text.
     */
    private function fakeOpenAI(string $verdict, int $qualifyStatus = 200): void
    {
        Http::fake(function ($request) use ($verdict, $qualifyStatus) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            if (str_contains($system, 'strict project filter')) {
                return Http::response(['choices' => [['message' => ['content' => $verdict]]]], $qualifyStatus);
            }
            return Http::response(['choices' => [['message' => ['content' => 'Generated cover letter']]]], 200);
        });
    }

    public function test_empty_negative_prompt_creates_bid_without_qualify_call(): void
    {
        $this->seedFilter('');
        $this->fakeOpenAI('true');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(1, Bid::count());
        Http::assertSentCount(1); // only the cover-letter call, no qualify call
    }

    public function test_qualify_true_creates_bid(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('true');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(1, Bid::count());
        Http::assertSentCount(2); // qualify + cover letter
    }

    public function test_qualify_false_skips_bid(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('false');
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(0, Bid::count());
        Http::assertSentCount(1); // only the qualify call; cover letter never reached
    }

    public function test_qualify_error_skips_bid_fail_closed(): void
    {
        $this->seedFilter('no crypto');
        $this->fakeOpenAI('', 500); // qualify returns 500 on every attempt
        $proposal = $this->makeProposal();

        (new OpenAIJob($proposal))->handle();

        $this->assertSame(0, Bid::count());
        Http::assertSentCount(2); // 2 qualify attempts, then fail-closed skip
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=OpenAIJobNegativePromptTest`
Expected: FAIL — with a set negative prompt, no gate exists yet, so `test_qualify_false_skips_bid` and `test_qualify_error_skips_bid_fail_closed` create a Bid (count 1, not 0) and send the wrong request count.

- [ ] **Step 3: Insert the gate in `OpenAIJob::handle()`**

In `app/Jobs/OpenAIJob.php`, locate the `crawler_on` guard (ends around `:38`) and the line `$prompt = $filter->prompt;` (`:41`). Insert the gate between them:

```php
        if (!$filter->crawler_on) {
            \Log::info("Exit Generation. Crawler is not on: {$this->proposal}");
            return;
        }

        $negative = trim((string) $filter->negative_prompt);
        if ($negative !== '') {
            if (! app(\App\Services\ProposalQualifier::class)->qualify($negative, $this->proposal->description)) {
                \Log::info("Skip proposal (negative-prompt gate): {$this->proposal->project_id}");
                return;
            }
        }

        $prompt = $filter->prompt;
```

Everything below (`$data`, the cover-letter `Http::post`, `new Bid()`, `FineTuneBidJob::dispatch`) is unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=OpenAIJobNegativePromptTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full negative-prompt suite + broader check**

Run: `./vendor/bin/sail test --filter=NegativePrompt`
Expected: PASS — NegativePromptColumnTest + FilterNegativePromptSaveTest.

Run: `./vendor/bin/sail test`
Expected: all pass except the known pre-existing `ExampleTest` (root `/` 302).

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/OpenAIJob.php tests/Feature/OpenAIJobNegativePromptTest.php
git commit -m "feat: gate bid creation on negative-prompt qualifier in OpenAIJob"
```

---

## Manual Verification (production, after deploy)

1. `php artisan migrate` → `filters.negative_prompt` exists.
2. Filters page shows the Negative Prompt textarea; enter e.g. "Skip crypto, gambling, and adult projects"; Submit; reopen → value persisted.
3. Let the crawler run. In logs, confirm proposals matching the negative prompt log `Skip proposal (negative-prompt gate): <project_id>` and produce no Bid; non-matching proposals produce bids as normal.
4. Temporarily set an OpenAI-unreachable condition (or observe a real error) → confirm the proposal is skipped (fail-closed), not bid, and the queue worker keeps running.
5. Clear the Negative Prompt (empty) + Submit → gate disabled, bids resume for all proposals.

## Revert Plan

1. `php artisan migrate:rollback` (drops `filters.negative_prompt`).
2. Remove the gate block in `OpenAIJob::handle()`, delete `ProposalQualifier` + its test, remove the `negative_prompt` read/save in `FilterController`, remove the textarea in `filters.blade.php`, delete the three new test files.
Crawl, cover-letter, and bid flow return to prior behavior.

## Self-Review Notes

- **Spec coverage:** column (Task 1); UI textarea + helper (Task 3); unconditional save/clear (Task 3); `ProposalQualifier` with exact system message, temp 0, 2 attempts, strict parse, fail-closed (Task 2); OpenAIJob gate with early return + empty-prompt bypass (Task 4); all test scenarios from the spec (Tasks 2 & 4 & 3). ✓
- **Type consistency:** `qualify(string, string): bool`, `negative_prompt`, `formValidationNegativePrompt`, `MAX_ATTEMPTS=2`, `app(ProposalQualifier::class)`, `true`=proceed/`false`=skip are consistent across tasks. ✓
- **Placeholder scan:** none — every step has concrete code/commands. ✓
- **Fail-closed integrity:** Task 2 tests assert `false` on ambiguous + HTTP 500 with 2 requests; Task 4 asserts no Bid on `false` and on error. This is the money-critical guarantee. ✓
