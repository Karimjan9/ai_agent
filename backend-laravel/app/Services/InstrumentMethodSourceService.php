<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immutable, untrusted reference text. Source ingestion never evaluates its contents. */
class InstrumentMethodSourceService
{
    public const PROTOCOL = 'instrument_method_source_v1';
    private const MAX_BYTES = 1048576;

    public function registerSource(array $input): array
    {
        if (! Schema::hasTable('instrument_method_sources')) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        $kind = $input['kind'] ?? null;
        $content = $input['content'] ?? null;
        $metadata = $input['metadata'] ?? null;
        $reference = $input['reference'] ?? null;
        if (! in_array($kind, ['url', 'video', 'imported_text'], true)
            || ! is_string($content) || trim($content) === '' || strlen($content) > self::MAX_BYTES
            || ! preg_match('//u', $content) || ! is_array($metadata)
            || ! is_string($metadata['license'] ?? null) || trim($metadata['license']) === ''
            || ! in_array($metadata['access'] ?? null, ['public', 'licensed', 'user_supplied'], true)
            || ! is_array($metadata['exposure'] ?? null) || $metadata['exposure'] === []
            || $this->hasAuthorityClaim($metadata) || $this->hasAuthorityClaim(array_diff_key($input, array_flip(['content', 'metadata'])))
            || ($reference !== null && (! is_string($reference) || strlen($reference) > 2048))) {
            return $this->blocked('METHOD_SOURCE_CONTENT_AND_PROVENANCE_REQUIRED');
        }
        if (in_array($kind, ['url', 'video'], true) && ! $this->httpsReference($reference)) {
            return $this->blocked('METHOD_SOURCE_HTTPS_REFERENCE_REQUIRED');
        }
        if ($kind === 'video' && (! is_int($metadata['video_timestamp_seconds'] ?? null)
            || $metadata['video_timestamp_seconds'] < 0 || $metadata['video_timestamp_seconds'] > 604800)) {
            return $this->blocked('METHOD_VIDEO_TIMESTAMP_REQUIRED');
        }
        $contract = ['protocol' => self::PROTOCOL, 'kind' => $kind, 'reference' => $reference,
            'content_hash' => hash('sha256', $content), 'metadata' => $metadata,
            'untrusted_reference_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false];
        $key = $this->hash($contract);
        DB::table('instrument_method_sources')->insertOrIgnore(['source_key' => $key, 'kind' => $kind,
            'reference' => $reference, 'content_hash' => $contract['content_hash'], 'content' => $content,
            'metadata' => $this->encode($metadata), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $this->inspectSource($key);
    }

    public function inspectSource(string $sourceKey): array
    {
        if (! Schema::hasTable('instrument_method_sources')) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        $row = DB::table('instrument_method_sources')->where('source_key', $sourceKey)->first();
        if (! $row) return $this->blocked('METHOD_SOURCE_NOT_FOUND');
        try { $metadata = json_decode($row->metadata, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return $this->blocked('METHOD_SOURCE_IMMUTABLE_CONTENT_DRIFT'); }
        $contract = ['protocol' => self::PROTOCOL, 'kind' => $row->kind, 'reference' => $row->reference,
            'content_hash' => hash('sha256', $row->content), 'metadata' => $metadata,
            'untrusted_reference_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false];
        if (! hash_equals($row->content_hash, $contract['content_hash']) || ! hash_equals($sourceKey, $this->hash($contract))) {
            return $this->blocked('METHOD_SOURCE_IMMUTABLE_CONTENT_DRIFT');
        }

        return ['status' => 'registered_reference_only', 'source_key' => $sourceKey, 'contract' => $contract,
            'contract_json' => $this->encode($contract), 'content' => $row->content,
            'captured_at' => $row->captured_at, 'qualification_granted' => false, 'promotion_evidence' => false];
    }

    /** Explicit public HTTPS capture, DNS-pinned and bounded; no redirects, credentials or cookies. */
    public function fetchSource(array $input): array
    {
        $metadata = $input['metadata'] ?? null;
        if (! is_array($metadata) || ! is_string($metadata['license'] ?? null) || trim($metadata['license']) === ''
            || ! in_array($metadata['access'] ?? null, ['public', 'licensed', 'user_supplied'], true)
            || ! is_array($metadata['exposure'] ?? null) || $metadata['exposure'] === [] || $this->hasAuthorityClaim($input)) {
            return $this->blocked('METHOD_SOURCE_CONTENT_AND_PROVENANCE_REQUIRED');
        }
        $reference = $input['reference'] ?? null;
        if (! $this->httpsReference($reference) || ! extension_loaded('curl')) return $this->blocked('METHOD_SOURCE_FETCH_HTTPS_REQUIRED');
        $host = strtolower((string) parse_url($reference, PHP_URL_HOST));
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            || filter_var($host, FILTER_VALIDATE_IP)) return $this->blocked('METHOD_SOURCE_FETCH_PUBLIC_HOST_REQUIRED');
        $addresses = gethostbynamel($host);
        if (! is_array($addresses) || $addresses === []) return $this->blocked('METHOD_SOURCE_FETCH_DNS_UNAVAILABLE');
        foreach ($addresses as $address) if (! $this->publicIpv4($address)) return $this->blocked('METHOD_SOURCE_FETCH_PRIVATE_ADDRESS_REFUSED');
        $curl = curl_init($reference);
        $body = '';
        $tooLarge = false;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'InstrumentMethodSource/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/plain, text/html, application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);
        if ($tooLarge) return $this->blocked('METHOD_SOURCE_FETCH_SIZE_LIMIT');
        if ($ok === false || $code < 200 || $code >= 300 || ! preg_match('/^(text\/(plain|html)|application\/json)(?:;|$)/', $type)) {
            return $this->blocked('METHOD_SOURCE_FETCH_TEXT_OR_SUCCESS_REQUIRED');
        }
        $metadata['capture'] = ['mode' => 'dns_pinned_public_https', 'http_status' => $code,
            'content_type' => $type, 'automatic_video_caption_download' => false];

        return $this->registerSource(['kind' => 'url', 'reference' => $reference, 'content' => $body, 'metadata' => $metadata]);
    }

    public function hasAuthorityClaim(array $input): bool
    {
        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), ['confirmed', 'credit', 'economic_credit', 'promotion_evidence',
                'qualification_granted', 'independent_evidence', 'paper_authority', 'live_authority'], true)) return true;
            if (is_array($value) && $this->hasAuthorityClaim($value)) return true;
        }
        return false;
    }

    private function httpsReference(mixed $reference): bool
    {
        if (! is_string($reference) || strlen($reference) > 2048 || preg_match('/[\x00-\x20\\\\]/', $reference)) return false;
        $url = parse_url($reference);
        return is_array($url) && ($url['scheme'] ?? null) === 'https' && filled($url['host'] ?? null)
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment'])
            && (! isset($url['port']) || $url['port'] === 443);
    }

    private function publicIpv4(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $number = ip2long($ip);
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$base, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($number & $mask) === (ip2long($base) & $mask)) return false;
        }
        return true;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function encode(array $value): string { return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function hash(array $value): string { return hash('sha256', $this->encode($value)); }
    private function blocked(string $reason): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'qualification_granted' => false, 'promotion_evidence' => false]; }
}
