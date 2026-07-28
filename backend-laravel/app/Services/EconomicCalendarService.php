<?php

namespace App\Services;

use App\Models\EconomicEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class EconomicCalendarService
{
    public function sync(?string $requestedProvider = null): array
    {
        $provider = $requestedProvider ?: (string) config('services.economic_calendar.provider', 'financial_modeling_prep');
        $apiKey = $this->apiKey($provider);
        if (! $this->providerEnabled($provider) || ! $apiKey) {
            return ['status' => 'not_configured', 'synced' => 0];
        }

        $params = $provider === 'alpha_vantage_news'
            ? [
                'function' => 'NEWS_SENTIMENT', 'apikey' => $apiKey,
                'topics' => 'economy_macro,economy_monetary,financial_markets',
                'sort' => 'LATEST', 'limit' => 200,
            ]
            : ($provider === 'currents_api_news'
                ? [
                    'apiKey' => $apiKey,
                    'language' => 'en',
                    'category' => 'business',
                    'page_size' => (int) config('services.currents_api.page_size', 100),
                ]
                : ($provider === 'financial_modeling_prep'
            ? [
                'apikey' => $apiKey,
                'from' => now('UTC')->subDay()->toDateString(),
                'to' => now('UTC')->addDays(14)->toDateString(),
            ]
            : ['c' => $apiKey]));
        $response = Http::timeout((int) config('services.economic_calendar.timeout_seconds', 30))
            ->acceptJson()->get($this->endpoint($provider), $params);
        if ($response->failed()) {
            return ['status' => 'failed', 'synced' => 0, 'reason' => 'Economic calendar provider returned HTTP '.$response->status()];
        }

        $synced = 0;
        $rows = match ($provider) {
            'alpha_vantage_news' => (array) $response->json('feed', []),
            'currents_api_news' => (array) ($response->json('news') ?? $response->json('data') ?? []),
            default => (array) $response->json(),
        };
        foreach ($rows as $row) {
            if ($provider === 'alpha_vantage_news') {
                $this->storeAlphaVantageHeadline($row, $provider);
                $synced++;
                continue;
            }
            if ($provider === 'currents_api_news') {
                $this->storeCurrentsHeadline($row, $provider);
                $synced++;
                continue;
            }
            $at = data_get($row, 'Date') ?? data_get($row, 'date') ?? data_get($row, 'scheduled_at');
            $title = (string) (data_get($row, 'Event') ?? data_get($row, 'event') ?? 'Economic event');
            $country = data_get($row, 'Country') ?? data_get($row, 'country');
            $currency = strtoupper((string) (data_get($row, 'Currency') ?? data_get($row, 'currency') ?? $this->currencyFromCountry((string) $country))) ?: null;
            // FMP calendar rows do not expose a durable public event id.  This
            // deterministic identity survives provider refreshes while still
            // allowing actual/estimate values to be updated in place.
            $id = data_get($row, 'CalendarId') ?? data_get($row, 'id')
                ?? sha1(implode('|', [$at, $title, $country, $currency]));
            if (! $at || ! $id) continue;
            EconomicEvent::updateOrCreate([
                'source' => $provider,
                'external_id' => (string) $id,
            ], [
                'title' => $title,
                'country' => $country,
                'currency' => $currency,
                'impact' => $this->impact(data_get($row, 'Importance') ?? data_get($row, 'importance') ?? data_get($row, 'impact')),
                'scheduled_at' => Carbon::parse($at)->utc(),
                'actual' => $this->string(data_get($row, 'Actual') ?? data_get($row, 'actual')),
                'forecast' => $this->string(data_get($row, 'Forecast') ?? data_get($row, 'forecast') ?? data_get($row, 'Estimate') ?? data_get($row, 'estimate')),
                'previous' => $this->string(data_get($row, 'Previous') ?? data_get($row, 'previous')),
                'payload' => $row,
            ]);
            $synced++;
        }
        return ['status' => 'ok', 'synced' => $synced];
    }

    public function veto(string $symbol, ?Carbon $at = null): array
    {
        $provider = (string) config('services.economic_calendar.provider', 'financial_modeling_prep');
        $calendarEnabled = $this->providerEnabled($provider) && $this->apiKey($provider);
        $headlineSources = collect(['alpha_vantage_news', 'currents_api_news'])
            ->filter(fn (string $source) => $this->providerEnabled($source) && $this->apiKey($source))
            ->values();
        if (! $calendarEnabled && $headlineSources->isEmpty()) {
            return ['active' => false, 'status' => 'not_configured'];
        }
        $at = ($at ?: now())->utc();
        $currencies = str_starts_with(strtoupper($symbol), 'XAU') ? ['USD'] : [substr(strtoupper($symbol), 0, 3), 'USD'];
        $event = null;
        if ($calendarEnabled && ! in_array($provider, ['alpha_vantage_news', 'currents_api_news'], true)) {
            $event = EconomicEvent::query()->where('source', $provider)->whereIn('currency', $currencies)
                ->where('impact', 'high')->whereBetween('scheduled_at', [
                    $at->copy()->subMinutes((int) config('services.economic_calendar.pre_event_minutes', 30)),
                    $at->copy()->addMinutes((int) config('services.economic_calendar.post_event_minutes', 30)),
                ])->orderBy('scheduled_at')->first();
        }
        if (! $event && $headlineSources->isNotEmpty()) {
            $window = max((int) config('services.alpha_vantage.headline_window_minutes', 60), (int) config('services.currents_api.headline_window_minutes', 60));
            $event = EconomicEvent::query()->whereIn('source', $headlineSources->all())->whereIn('currency', $currencies)
                ->where('impact', 'high')->whereBetween('scheduled_at', [$at->copy()->subMinutes($window), $at])
                ->latest('scheduled_at')->first();
        }
        return $event
            ? ['active' => true, 'status' => 'veto', 'event' => ['id' => $event->id, 'title' => $event->title, 'currency' => $event->currency, 'scheduled_at' => $event->scheduled_at->toIso8601String()]]
            : ['active' => false, 'status' => 'clear'];
    }

    private function impact(mixed $value): string
    {
        $value = strtolower((string) $value);
        return str_contains($value, 'high') || $value === '3' ? 'high' : (str_contains($value, 'medium') || $value === '2' ? 'medium' : 'low');
    }
    private function currencyFromCountry(string $country): ?string
    {
        return match (strtolower($country)) { 'united states' => 'USD', 'united kingdom' => 'GBP', 'euro area', 'european union' => 'EUR', default => null };
    }
    private function string(mixed $value): ?string { return $value === null ? null : (string) $value; }

    private function apiKey(string $provider): ?string
    {
        return match ($provider) {
            'alpha_vantage_news' => config('services.alpha_vantage.api_key'),
            'currents_api_news' => config('services.currents_api.api_key'),
            default => config('services.economic_calendar.api_key'),
        };
    }

    private function providerEnabled(string $provider): bool
    {
        return match ($provider) {
            'alpha_vantage_news', 'currents_api_news' => true,
            default => (bool) config('services.economic_calendar.enabled'),
        };
    }

    private function endpoint(string $provider): string
    {
        return match ($provider) {
            'alpha_vantage_news' => (string) config('services.alpha_vantage.endpoint'),
            'currents_api_news' => (string) config('services.currents_api.endpoint'),
            default => (string) config('services.economic_calendar.endpoint'),
        };
    }

    private function storeAlphaVantageHeadline(array $row, string $provider): void
    {
        $title = (string) data_get($row, 'title', 'Market headline');
        $summary = (string) data_get($row, 'summary', '');
        $text = strtolower($title.' '.$summary);
        // Alpha Vantage is headline/sentiment data, not a scheduled economic
        // calendar. Only macro-release language becomes a high-impact veto.
        $macro = preg_match('/fomc|federal reserve|interest rate|central bank|cpi|inflation|nonfarm|payroll|nfp|gdp|unemployment|ecb|bank of england|boe/', $text) === 1;
        $currency = $this->headlineCurrency($text);
        $published = data_get($row, 'time_published');
        if (! $published) return;
        $at = Carbon::createFromFormat('Ymd\\THis', (string) $published, 'UTC');
        $id = sha1((string) (data_get($row, 'url') ?: $published.'|'.$title));
        EconomicEvent::updateOrCreate(['source' => $provider, 'external_id' => $id], [
            'title' => $title, 'country' => null, 'currency' => $currency,
            'impact' => $macro && $currency ? 'high' : 'low', 'scheduled_at' => $at,
            'actual' => null, 'forecast' => null, 'previous' => null,
            'payload' => $row,
        ]);
    }

    private function storeCurrentsHeadline(array $row, string $provider): void
    {
        $title = (string) data_get($row, 'title', 'Market headline');
        $summary = (string) (data_get($row, 'description') ?? data_get($row, 'snippet') ?? '');
        $text = strtolower($title.' '.$summary);
        $macro = preg_match('/fomc|federal reserve|interest rate|central bank|cpi|inflation|nonfarm|payroll|nfp|gdp|unemployment|ecb|bank of england|boe/', $text) === 1;
        $currency = $this->headlineCurrency($text);
        $published = data_get($row, 'published') ?? data_get($row, 'published_at');
        if (! $published) return;
        $at = Carbon::parse((string) $published)->utc();
        $id = (string) (data_get($row, 'id') ?: sha1((string) (data_get($row, 'url') ?: $published.'|'.$title)));
        EconomicEvent::updateOrCreate(['source' => $provider, 'external_id' => $id], [
            'title' => $title, 'country' => null, 'currency' => $currency,
            'impact' => $macro && $currency ? 'high' : 'low', 'scheduled_at' => $at,
            'actual' => null, 'forecast' => null, 'previous' => null,
            'payload' => $row,
        ]);
    }

    private function headlineCurrency(string $text): ?string
    {
        if (preg_match('/\beur\b|euro|ecb|european central bank/', $text)) return 'EUR';
        if (preg_match('/\bgbp\b|sterling|bank of england|\bboe\b|united kingdom/', $text)) return 'GBP';
        if (preg_match('/\busd\b|dollar|federal reserve|fomc|nonfarm|payroll|\bnfp\b|united states/', $text)) return 'USD';
        return null;
    }
}
