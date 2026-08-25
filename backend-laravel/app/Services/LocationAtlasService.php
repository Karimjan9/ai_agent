<?php

namespace App\Services;

use App\Models\LocationAtlasEntry;
use Illuminate\Support\Facades\Schema;

class LocationAtlasService
{
    public const PROTOCOL = 'xauusd_location_atlas_v1';

    /** @return array<string,mixed> */
    public function thesis(array $context = []): array
    {
        $type = (string) ($context['location_type'] ?? 'unresolved');
        $available = (bool) ($context['location_available'] ?? false);
        $expiresAt = $context['expires_at'] ?? null;
        $expired = $expiresAt !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($expiresAt));
        $invalidated = $context['invalidated_at'] ?? null;
        return ['protocol' => self::PROTOCOL, 'location_type' => $type, 'definition_version' => (string) ($context['definition_version'] ?? 'v1'), 'formed_at' => $context['formed_at'] ?? null, 'available_at' => $context['available_at'] ?? null, 'expires_at' => $expiresAt, 'invalidated_at' => $invalidated, 'invalidation_structure_id' => $context['invalidation_structure_id'] ?? null, 'strength' => (float) ($context['location_strength'] ?? 0), 'touch_count' => (int) ($context['touch_count'] ?? 0), 'freshness' => (float) ($context['freshness'] ?? 0), 'distance_in_atr' => isset($context['distance_in_atr']) ? (float) $context['distance_in_atr'] : null, 'trigger_admissible' => $available && $type !== 'unresolved' && ! $expired && $invalidated === null, 'rejection_reason' => $expired ? 'LOCATION_EXPIRED' : ($invalidated !== null ? 'LOCATION_INVALIDATED' : ($available && $type !== 'unresolved' ? null : 'LOCATION_UNRESOLVED')), 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function record(string $symbol, string $timeframe, array $context): array
    {
        $thesis = $this->thesis($context);
        if (! Schema::hasTable('location_atlas_entries')) return $thesis;
        $key = hash('sha256', implode('|', [$symbol, $timeframe, $thesis['location_type'], $thesis['available_at'] ?? 'none', $thesis['definition_version']]));
        $row = LocationAtlasEntry::query()->updateOrCreate(['atlas_key' => $key], ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'location_type' => $thesis['location_type'], 'definition_version' => $thesis['definition_version'], 'state' => $thesis['trigger_admissible'] ? 'active' : 'unresolved', 'formed_at' => $thesis['formed_at'] ?? now(), 'available_at' => $thesis['available_at'] ?? now(), 'expires_at' => $thesis['expires_at'], 'invalidated_at' => $thesis['invalidated_at'], 'strength' => $thesis['strength'], 'touch_count' => $thesis['touch_count'], 'freshness' => $thesis['freshness'], 'distance_in_atr' => $thesis['distance_in_atr'], 'contract' => $thesis]);
        return [...$thesis, 'location_atlas_entry_id' => $row->id];
    }
}
