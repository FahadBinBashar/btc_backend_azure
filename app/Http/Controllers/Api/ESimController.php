<?php

namespace App\Http\Controllers\Api;

use App\Models\KycVerification;
use App\Models\ServiceRequest;
use App\Services\MatiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ESimController extends BaseApiController
{
    public function start(Request $request)
    {
        $serviceRequest = ServiceRequest::query()->create([
            'request_type' => 'esim_purchase',
            'status' => 'started',
            'current_step' => 'terms',
            'otp_skipped' => true,
            'metadata' => [
                'started_at' => now()->toISOString(),
            ],
        ]);

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'status' => $serviceRequest->status,
            'current_step' => $serviceRequest->current_step,
        ]);
    }
    public function acceptTerms(Request $request, string $requestId) { return $this->ok(['request_id' => $requestId]); }
    public function pay(Request $request, string $requestId) { return $this->ok(['request_id' => $requestId]); }
    public function numbers(Request $request, string $requestId) 
    { 
        $botswanaNumbers = [
            '73234567',
            '73456789',
            '73321654',
            '73888999',
            '73777888',
            '73555666',
        ];
    
        return $this->ok([
            'request_id' => $requestId, 
            'numbers'    => $botswanaNumbers
        ]); 
    }
    public function selectNumber(Request $request, string $requestId)
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
        ]);

        $serviceRequest = ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'esim_purchase')
            ->first();

        if (!$serviceRequest) {
            return $this->fail('eSIM request not found.', 404);
        }

        $serviceRequest->msisdn = $this->normalizeMsisdn($payload['msisdn']);
        $serviceRequest->status = 'number_selected';
        $serviceRequest->current_step = 'registration';
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'msisdn' => $serviceRequest->msisdn,
        ]);
    }
    public function registration(Request $request, string $requestId) { return $this->ok(['request_id' => $requestId]); }
    public function startKyc(Request $request, string $requestId)
    {
        $payload = $request->validate([
            'document_type' => ['required', Rule::in(['omang', 'passport'])],
            'session_id' => ['nullable', 'string', 'max:255'],
            'verification_id' => ['nullable', 'string', 'max:255'],
            'identity_id' => ['nullable', 'string', 'max:255'],
        ]);

        $serviceRequest = ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'esim_purchase')
            ->first();

        if (!$serviceRequest) {
            return $this->fail('eSIM request not found.', 404);
        }

        $verification = KycVerification::query()
            ->where('service_request_id', $serviceRequest->id)
            ->latest('id')
            ->first();

        if ($verification) {
            $verification->fill([
                'provider' => 'metamap',
                'session_id' => $payload['session_id'] ?? $verification->session_id,
                'verification_id' => $payload['verification_id'] ?? $verification->verification_id,
                'identity_id' => $payload['identity_id'] ?? $verification->identity_id,
                'status' => 'pending',
                'document_type' => $payload['document_type'],
            ]);
            $verification->save();
        } else {
            $verification = KycVerification::query()->create([
                'service_request_id' => $serviceRequest->id,
                'provider' => 'metamap',
                'session_id' => $payload['session_id'] ?? null,
                'verification_id' => $payload['verification_id'] ?? null,
                'identity_id' => $payload['identity_id'] ?? null,
                'status' => 'pending',
                'document_type' => $payload['document_type'],
                'raw_response' => null,
            ]);
        }

        $serviceRequest->status = 'kyc_pending';
        $serviceRequest->current_step = 'verification';
        $serviceRequest->metadata = array_merge(
            is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [],
            [
                'kyc_started_at' => now()->toISOString(),
                'kyc_verification_id' => $verification->id,
            ]
        );
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'kyc_verification_id' => (string) $verification->id,
            'status' => 'pending',
        ], 201);
    }
    public function kycStatus(Request $request, string $requestId)
    {
        $verification = null;

        $serviceRequest = ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'esim_purchase')
            ->first();

        if ($serviceRequest) {
            $verification = $this->resolveBestVerification($serviceRequest->id);
        }

        if (!$verification) {
            $verification = KycVerification::query()
                ->orWhere('verification_id', $requestId)
                ->orWhere('identity_id', $requestId)
                ->orWhere('session_id', $requestId)
                ->latest('id')
                ->first();
        }

        if ($verification) {
            if (!$serviceRequest && $verification->service_request_id) {
                $serviceRequest = ServiceRequest::query()->where('id', $verification->service_request_id)->first();
            }

            $verification = $this->refreshFromMetaMapIfNeeded($verification, $serviceRequest);
        }

        $status = $verification ? $this->mapWebhookStatus($verification) : 'pending';

        return $this->ok([
            'request_id' => $requestId,
            'status' => $status,
            'kyc_verification_id' => $verification ? (string) $verification->id : null,
            'verification_id' => $verification?->verification_id,
            'identity_id' => $verification?->identity_id,
        ]);
    }
    public function confirmKyc(Request $request, string $requestId) { return $this->ok(['request_id' => $requestId]); }
    public function activate(Request $request, string $requestId) { return $this->ok(['request_id' => $requestId]); }

    private function mapWebhookStatus(KycVerification $verification): string
    {
        $raw = is_array($verification->raw_response) ? $verification->raw_response : [];
        $rawStatus = strtolower((string) ($raw['status'] ?? $raw['identityStatus'] ?? ''));
        $eventName = strtolower((string) ($raw['eventName'] ?? ''));
        $stored = strtolower((string) $verification->status);

        $mappedFromStored = match ($stored) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            'manual_review' => 'manual_review',
            'expired' => 'timeout',
            'timeout' => 'timeout',
            default => null,
        };

        if ($mappedFromStored !== null) {
            return $mappedFromStored;
        }

        if (in_array($rawStatus, ['reviewneeded', 'review_needed', 'review', 'manual_review'], true)) {
            return 'manual_review';
        }

        if ($eventName === 'verification_expired' || $rawStatus === 'expired') {
            return 'timeout';
        }

        return 'pending';
    }

    private function resolveBestVerification(int $serviceRequestId): ?KycVerification
    {
        $preferred = KycVerification::query()
            ->where('service_request_id', $serviceRequestId)
            ->where(function ($query) {
                $query->whereNotNull('verification_id')
                    ->orWhereNotNull('identity_id')
                    ->orWhereNotNull('raw_response')
                    ->orWhereIn('status', ['verified', 'rejected', 'expired']);
            })
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if ($preferred) {
            return $preferred;
        }

        return KycVerification::query()
            ->where('service_request_id', $serviceRequestId)
            ->latest('id')
            ->first();
    }

    private function normalizeMsisdn(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '267') && strlen($digits) > 8) {
            $digits = substr($digits, -8);
        }
        return $digits !== '' ? $digits : $raw;
    }

    private function refreshFromMetaMapIfNeeded(KycVerification $verification, ?ServiceRequest $serviceRequest): KycVerification
    {
        if (strtolower((string) $verification->status) !== 'pending') {
            return $verification;
        }

        if (empty($verification->verification_id)) {
            return $verification;
        }

        $fullVerification = app(MatiService::class)->getVerification((string) $verification->verification_id);
        if (!is_array($fullVerification)) {
            return $verification;
        }

        $providerStatus = strtolower((string) ($fullVerification['identity']['status'] ?? ''));
        $mappedStatus = match ($providerStatus) {
            'verified' => 'verified',
            'rejected', 'reviewneeded', 'review_needed', 'review' => 'rejected',
            'expired' => 'expired',
            default => 'pending',
        };

        if ($mappedStatus === 'pending') {
            return $verification;
        }

        $verification->status = $mappedStatus;
        $verification->identity_id = (string) ($fullVerification['identity']['id'] ?? $verification->identity_id);
        $verification->raw_response = $fullVerification;
        if ($mappedStatus === 'rejected') {
            $verification->failure_reason = $verification->failure_reason ?? 'Verification rejected';
        }
        $verification->save();

        if ($serviceRequest) {
            $serviceRequest->status = $mappedStatus === 'verified' ? 'kyc_verified' : 'kyc_rejected';
            $serviceRequest->current_step = $mappedStatus === 'verified' ? 'complete' : 'verification';
            $serviceRequest->save();
        }

        return $verification->fresh() ?? $verification;
    }
}
