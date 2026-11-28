<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enige plek waar de winkel met Claude (Anthropic API) praat. De API-sleutel staat alleen in .env op de server en komt nooit in de browser.
 * Bewaakt: sleutel aanwezig, dagelijkse limieten (totaal en per medewerker), tokengebruik wordt vastgelegd (nooit de tekst zelf),
 * en het antwoord moet geldige JSON volgens het schema zijn (structured outputs). Er wordt nooit iets automatisch verstuurd of opgeslagen.
 */
class ClaudeService
{
    public function enabled(): bool
    {
        return strlen((string) config('shop.ai.key')) >= 20;
    }

    public function model(): string
    {
        return (string) config('shop.ai.model');
    }

    /** @return array{calls: int, input: int, output: int} gebruik vandaag (alle medewerkers, of één) */
    public function usageToday(?int $userId = null): array
    {
        $q = DB::table('ai_usage')->where('created_at', '>=', now()->startOfDay());
        if ($userId) {
            $q->where('user_id', $userId);
        }
        $r = $q->selectRaw('count(*) as calls, coalesce(sum(input_tokens),0) as i, coalesce(sum(output_tokens),0) as o')->first();

        return ['calls' => (int) $r->calls, 'input' => (int) $r->i, 'output' => (int) $r->o];
    }

    /** Aantal aanroepen en tokens in de laatste 30 dagen. */
    public function usageMonth(): array
    {
        $r = DB::table('ai_usage')->where('created_at', '>=', now()->subDays(30))->selectRaw('count(*) as calls, coalesce(sum(input_tokens),0) as i, coalesce(sum(output_tokens),0) as o')->first();

        return ['calls' => (int) $r->calls, 'input' => (int) $r->i, 'output' => (int) $r->o];
    }

    private function guard(?int $userId): void
    {
        if (! $this->enabled()) {
            throw new AiException('ai_unavailable', 'AI is nog niet ingesteld: zet ANTHROPIC_API_KEY in het .env-bestand van de server.', 503);
        }
        if ($this->usageToday()['calls'] >= (int) config('shop.ai.daily_limit')) {
            throw new AiException('ai_limit', 'De dagelijkse AI-limiet van de winkel is bereikt. Morgen kan het weer (of verhoog AI_DAILY_LIMIT).', 429);
        }
        if ($userId && $this->usageToday($userId)['calls'] >= (int) config('shop.ai.user_daily_limit')) {
            throw new AiException('ai_limit', 'U heeft vandaag de AI-limiet per medewerker bereikt.', 429);
        }
    }

    /**
     * Eén opdracht aan Claude met een JSON-schema voor het antwoord.
     *
     * @param  array<string, mixed>  $schema  JSON Schema (type object, additionalProperties false)
     * @return array<string, mixed> het gevalideerde antwoord
     */
    public function complete(string $feature, string $system, string $user, array $schema, ?int $userId = null, int $maxTokens = 3000): array
    {
        $this->guard($userId);
        $model = $this->model();
        $ok = false;
        $in = $out = 0;
        try {
            $res = $this->call($model, $system, $user, $schema, $maxTokens);
            $in = $res['input'];
            $out = $res['output'];
            if ($res['stop'] === 'refusal') {
                throw new AiException('ai_refused', 'De AI wilde hier niet op antwoorden. Pas de invoer aan en probeer het opnieuw.', 422);
            }
            if ($res['stop'] === 'max_tokens') {
                throw new AiException('ai_failed', 'Het antwoord werd te lang en is afgekapt. Probeer een kortere invoer.', 502);
            }
            $data = json_decode($res['text'], true);
            if (! is_array($data) || array_diff($schema['required'] ?? [], array_keys($data))) {
                throw new AiException('ai_failed', 'De AI gaf een onbruikbaar antwoord. Probeer het opnieuw.', 502);
            }
            $ok = true;

            return $data;
        } catch (AiException $e) {
            throw $e;
        } catch (RateLimitException) {
            throw new AiException('ai_busy', 'De AI-dienst is op dit moment druk. Probeer het over een minuut opnieuw.', 429);
        } catch (APIStatusException $e) {
            Log::error('Claude-aanvraag mislukt', ['feature' => $feature, 'status' => $e->status ?? null, 'type' => $e->type?->value ?? null]);
            throw new AiException('ai_failed', ($e->status ?? 0) === 401 ? 'De AI-sleutel is ongeldig. Controleer ANTHROPIC_API_KEY.' : 'De AI-dienst gaf een fout. Probeer het later opnieuw.', 502);
        } catch (APIConnectionException) {
            throw new AiException('ai_failed', 'De AI-dienst is niet bereikbaar. Probeer het later opnieuw.', 502);
        } catch (Throwable $e) {
            Log::error('Claude-aanvraag mislukt', ['feature' => $feature, 'error' => get_class($e)]); // nooit de inhoud loggen
            throw new AiException('ai_failed', 'De AI-aanvraag is mislukt. Probeer het opnieuw.', 502);
        } finally {
            DB::table('ai_usage')->insert(['user_id' => $userId, 'feature' => $feature, 'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out, 'ok' => $ok, 'created_at' => now()]);
        }
    }

    /**
     * De echte API-aanroep (in tests vervangen). Structured outputs: het antwoord is JSON volgens het schema.
     * Er worden bewust geen temperature/top_p gezet (niet meer toegestaan) en de denkdiepte volgt `output_config.effort`.
     *
     * @return array{text: string, stop: ?string, input: int, output: int}
     */
    protected function call(string $model, string $system, string $user, array $schema, int $maxTokens): array
    {
        $client = new Client(apiKey: (string) config('shop.ai.key'), requestOptions: ['timeout' => 120.0, 'maxRetries' => 1]);
        $message = $client->messages->create(
            model: $model,
            maxTokens: $maxTokens,
            system: $system,
            messages: [['role' => 'user', 'content' => $user]],
            outputConfig: ['effort' => (string) config('shop.ai.effort'), 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        );
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return ['text' => $text, 'stop' => $message->stopReason, 'input' => (int) $message->usage->inputTokens, 'output' => (int) $message->usage->outputTokens];
    }
}
