---
name: sharpapi-content-summarize
description: Summarize long text into a concise version with SharpAPI via `SharpAPI\ContentSummarize\ContentSummarizeService` (sharpapi/laravel-content-summarize). Use when generating summaries, excerpts, TL;DRs or meta descriptions from content, or touching `summarize()`, `fetchResults()` or `config/sharpapi-content-summarize.php`.
---

# SharpAPI Content Summarize

`sharpapi/laravel-content-summarize` wraps one SharpAPI endpoint (`POST /content/summarize`) to summarize long text into a short version. The work is async: `summarize()` submits a job and returns a status URL, then `fetchResults()` polls until the job finishes.

## When to use this skill

- Generating excerpts, TL;DRs or teaser text for posts, documents or tickets.
- Shortening long content to a target length or tone.
- Debugging empty or odd results from `summarize()` / `fetchResults()`.

## Install / wiring checklist

- `composer require sharpapi/laravel-content-summarize`. It pulls in `sharpapi/php-core`; this skill assumes php-core ≥ 1.4.1.
- `.env`: `SHARP_API_KEY=...` is required. If it is missing, constructing the service throws `InvalidArgumentException`.
- Optional env keys, shared by every SharpAPI wrapper:
  - `SHARP_API_BASE_URL` (default `https://sharpapi.com/api/v1`)
  - `SHARP_API_JOB_STATUS_POLLING_WAIT` (default `180`): the maximum seconds `fetchResults()` keeps polling.
  - `SHARP_API_JOB_STATUS_POLLING_INTERVAL` (default `10`): seconds between polls when the API sends no `Retry-After`.
  - `SHARP_API_JOB_STATUS_USE_POLLING_INTERVAL` (default `false`): when `true`, the fixed interval above replaces the server's `Retry-After`.
- The config file is optional. To publish it: `php artisan vendor:publish --tag=sharpapi-content-summarize` (creates `config/sharpapi-content-summarize.php`).
- The service provider is auto-discovered. There is **no facade and no container binding**. Type-hint `ContentSummarizeService` (the container builds it) or call `new ContentSummarizeService()`. The constructor takes no arguments and reads the config.

## API & config reference

```php
use SharpAPI\ContentSummarize\ContentSummarizeService;

public function summarize(
    string $text,
    ?string $language = null,
    ?int $maxLength = null,
    ?string $voiceTone = null,
    ?string $context = null
): string
```

- `$text` — the content to summarize. The only required parameter.
- `$language` — output language as a full English name (`"English"`, `"Spanish"`, `"Simplified Chinese"`), not an ISO code. `null` lets the API decide (it normally keeps the input language).
- `$maxLength` — target length in characters. The model treats it as a strong suggestion, not a hard cap, so truncate yourself if the limit is strict.
- `$voiceTone` — free-text tone such as `"neutral"`, `"formal"`, `"friendly"`, `"funny"`.
- `$context` — free-text extra instructions for the model (audience, "keep Markdown intact", and so on).

**Returns the status URL (a string), not the result.** Pass it to the inherited `fetchResults(string $statusUrl): SharpAPI\Core\DTO\SharpApiJob`, which blocks while it polls.

`SharpApiJob` has the public properties `id`, `type` (`"content_summarize"`), `status` (a string: `"success"` or `"failed"`) and `result` (`?stdClass`). It also has `getResultJson()`, `getResultArray()` (shallow), `getResultObject()` and `toArray()`.

Example `result` on success (shape from the SharpAPI response template; the values are illustrative):

```json
{
    "summary": "Max Verstappen thinks the Las Vegas Grand Prix is more showbiz than sport, while Lewis Hamilton and Fernando Alonso are soaking up the glitz.",
    "output_format": "text",
    "output_html_css": null
}
```

`summary` holds the summarized text.

Exceptions:
- `SharpAPI\Core\Exceptions\ApiException`: polling ran past `SHARP_API_JOB_STATUS_POLLING_WAIT`, or HTTP 429 retries ran out.
- `GuzzleHttp\Exception\ClientException` (4xx, e.g. 401 bad key, 422 validation) and other `GuzzleHttp\Exception\GuzzleException`s for transport or 5xx errors.

## Recipes

### Queued job (the default pattern)

```php
namespace App\Jobs;

use App\Models\Post;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable; // Laravel 10: Dispatchable, InteractsWithQueue, Queueable, SerializesModels
use Illuminate\Support\Facades\Log;
use SharpAPI\Core\Enums\SharpApiJobStatusEnum;
use SharpAPI\Core\Exceptions\ApiException;
use SharpAPI\ContentSummarize\ContentSummarizeService;

class SummarizePost implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240; // must exceed SHARP_API_JOB_STATUS_POLLING_WAIT (180)

    public int $tries = 1;     // every retry re-submits the text and is billed again

    public function __construct(public Post $post) {}

    public function handle(ContentSummarizeService $service): void
    {
        try {
            $statusUrl = $service->summarize($this->post->body, null, 300);
            $job = $service->fetchResults($statusUrl); // blocks and polls; no loop needed
        } catch (ApiException|GuzzleException $e) {
            Log::warning('SharpAPI summarize failed: '.$e->getMessage());

            return;
        }

        if ($job->status !== SharpApiJobStatusEnum::SUCCESS->value) {
            Log::warning('SharpAPI summarize job did not succeed', $job->toArray());

            return;
        }

        $summary = $job->result->summary ?? null;
        $this->post->update(['excerpt' => $summary]);
    }
}
```

Resolve the service in `handle()`, as above, and never store it on a job property. It holds a Guzzle client, which cannot be serialized onto the queue.

## Gotchas

php-core is a transitive dependency, so these rules are repeated here:

1. **`fetchResults()` already polls.** It sleeps between polls (honouring `Retry-After` and rate-limit headers) until the job succeeds, fails or `SHARP_API_JOB_STATUS_POLLING_WAIT` runs out. Never write your own `while ($status === 'pending')` loop, and never call `summarize()` again to "retry": each call is a new billed job.
2. **A failed job does not throw.** Always compare `$job->status` with `SharpApiJobStatusEnum::SUCCESS->value` (`SharpAPI\Core\Enums\SharpApiJobStatusEnum`). On failure `result` can be an empty `stdClass`, so reading `$job->result->field` without `?? null` raises an "Undefined property" `ErrorException` in Laravel.
3. **Never call `fetchResults()` inside an HTTP request.** It can block for up to 180 s. Use a queued job whose `$timeout` exceeds the polling wait, keep `$tries` low, and make the worker/Horizon supervisor `timeout` at least the job timeout, with the queue connection's `retry_after` above it. Artisan commands are fine to run inline.
4. **For arrays, decode the JSON:** `json_decode($job->getResultJson(), true)`. `getResultArray()` only converts the top level, so nested objects stay `stdClass`, and list results arrive as objects with numeric keys.
5. **Language and voice tone are free text.** Pass full English names (`"English"`, `"Spanish"`, `"Simplified Chinese"`), not ISO codes (`"es"`, `"zh"`). Map your locale enum to a label before calling.
6. Options are positional arguments, not an options array: `summarize($text, 'English', 300)`. An older README example passed `['max_length' => ..., 'language' => 'en']`, which is a TypeError.

## Testing

- Mock the service. It must reach your code through the container (constructor/`handle()` injection or `app(ContentSummarizeService::class)`); `new ContentSummarizeService()` bypasses the mock.

```php
use SharpAPI\Core\DTO\SharpApiJob;
use SharpAPI\ContentSummarize\ContentSummarizeService;

$this->mock(ContentSummarizeService::class, function ($mock) {
    $mock->shouldReceive('summarize')->once()->andReturn('https://sharpapi.com/api/v1/job/status/fake-id');
    $mock->shouldReceive('fetchResults')->once()->andReturn(new SharpApiJob(
        id: 'fake-id',
        type: 'content_summarize',
        status: 'success',
        result: (object) ['summary' => 'Short version.'],
    ));
});
```

- Test the failure path too: return `status: 'failed'` with `result: new \stdClass`.
- `Http::fake()` does **not** intercept these calls, because php-core sends them through its own Guzzle client. Mock the service instead. Without a mock, a test with no `SHARP_API_KEY` throws `InvalidArgumentException` as soon as the service is built.
