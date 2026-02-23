<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscriber;
use Illuminate\Http\Request;

class SubscriberController extends BaseApiController
{
    public function lookup(Request $request)
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
        ]);

        $msisdn = $this->normalizeMsisdn($payload['msisdn']);
        if ($msisdn === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        $subscriber = Subscriber::query()
            ->whereIn('msisdn', [$msisdn, '+267'.$msisdn, '267'.$msisdn])
            ->first();

        $exists = (bool) $subscriber;
        $whitelisted = $exists ? (bool) $subscriber->is_whitelisted : false;
        $hasSeedData = Subscriber::query()->exists();

        // Local/dev fallback: allow if no subscriber seed data exists yet.
        $effectiveExists = $hasSeedData ? ($exists && $whitelisted) : true;

        return $this->ok([
            'msisdn' => $msisdn,
            'exists' => $effectiveExists,
            'details' => [
                'record_exists' => $exists,
                'is_whitelisted' => $whitelisted,
                'bypassed_due_to_empty_seed' => !$hasSeedData,
            ],
        ]);
    }

    public function upload(Request $request)
    {
        $payload = $request->validate([
            'phoneNumbers' => ['nullable', 'array', 'min:1'],
            'phoneNumbers.*' => ['nullable', 'string', 'max:30'],
            'msisdn' => ['nullable', 'array', 'min:1'],
            'msisdn.*' => ['nullable', 'string', 'max:30'],
        ]);

        $rawList = collect($payload['phoneNumbers'] ?? $payload['msisdn'] ?? []);

        if ($rawList->isEmpty()) {
            return $this->fail('Provide phoneNumbers (or msisdn) as a non-empty array.', 422);
        }

        $normalizedMap = $rawList->map(function ($value) {
            $normalized = $this->normalizeMsisdn((string) $value);

            return [
                'raw' => $value,
                'normalized' => $normalized,
            ];
        });

        $invalid = $normalizedMap
            ->filter(fn ($entry) => $entry['normalized'] === null)
            ->pluck('raw')
            ->values();

        $normalized = $normalizedMap
            ->pluck('normalized')
            ->filter()
            ->values();

        if ($normalized->isEmpty()) {
            return $this->fail('No valid phone numbers found after normalization.', 422);
        }

        $uniqueNormalized = $normalized->unique()->values();
        $duplicateCount = $normalized->count() - $uniqueNormalized->count();

        $existingMsisdn = Subscriber::query()
            ->whereIn('msisdn', $uniqueNormalized)
            ->pluck('msisdn');

        $newMsisdn = $uniqueNormalized->diff($existingMsisdn)->values();
        $updateMsisdn = $uniqueNormalized->intersect($existingMsisdn)->values();

        $timestamp = now();

        $records = $uniqueNormalized->map(fn ($msisdn) => [
            'msisdn' => $msisdn,
            'is_whitelisted' => true,
            'updated_at' => $timestamp,
            'created_at' => $timestamp,
        ])->all();

        Subscriber::query()->upsert(
            $records,
            ['msisdn'],
            ['is_whitelisted', 'updated_at']
        );

        return $this->ok([
            'summary' => [
                'received' => $rawList->count(),
                'normalized' => $normalized->count(),
                'unique' => $uniqueNormalized->count(),
                'inserted' => $newMsisdn->count(),
                'updated' => $updateMsisdn->count(),
                'duplicates_removed' => $duplicateCount,
                'invalid' => $invalid->count(),
            ],
            'msisdn' => [
                'inserted' => $newMsisdn,
                'updated' => $updateMsisdn,
                'invalid' => $invalid,
            ],
        ], 201);
    }

    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '267') && strlen($digits) >= 10) {
            $digits = substr($digits, 3);
        }

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);

        if ($length < 7 || $length > 12) {
            return null;
        }

        return $digits;
    }
}
