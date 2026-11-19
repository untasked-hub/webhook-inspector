<?php

namespace App\Filesystem;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP adapter over UNTASKED_STORAGE_URL for object put/get/delete.
 * Prefer this disk for durable app objects; visitor uploads use the uploads intent flow.
 */
class UntaskedStorageAdapter
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function put(string $key, string $contents): void
    {
        $response = Http::withToken($this->token)
            ->withBody($contents, 'application/octet-stream')
            ->put(rtrim($this->baseUrl, '/').'/objects/'.ltrim($key, '/'));

        if (! $response->successful()) {
            throw new RuntimeException('Untasked storage put failed: '.$response->status());
        }
    }

    public function get(string $key): string
    {
        $response = Http::withToken($this->token)
            ->get(rtrim($this->baseUrl, '/').'/objects/'.ltrim($key, '/'));

        if (! $response->successful()) {
            throw new RuntimeException('Untasked storage get failed: '.$response->status());
        }

        return $response->body();
    }

    public function delete(string $key): void
    {
        Http::withToken($this->token)
            ->delete(rtrim($this->baseUrl, '/').'/objects/'.ltrim($key, '/'))
            ->throw();
    }
}
