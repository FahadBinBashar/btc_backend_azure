<?php

namespace App\Http\Controllers\Api;

use App\Models\KycVerification;
use App\Models\RegistrationProfile;
use App\Models\ServiceRequest;
use App\Models\Subscriber;
use App\Services\MatiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class KycComplianceController extends BaseApiController
{
    public function start(Request $request): JsonResponse
    {
        $serviceRequest = ServiceRequest::create([
            'request_type' => 'kyc_compliance',
            'status' => 'started',
            'current_step' => 'terms',
            'otp_skipped' => true,
            'metadata' => [
                'started_at' => now()->toISOString(),
            ],
        ]);

        return $this->ok([
            'message' => 'KYC compliance flow started',
            'request_id' => (string) $serviceRequest->id,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ], 201);
    }

    public function acceptTerms(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'accepted' => ['required', 'boolean'],
        ]);

        if ($payload['accepted'] !== true) {
            return $this->fail('Terms must be accepted to continue.', 422);
        }

        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        $serviceRequest->current_step = 'number';
        $serviceRequest->status = 'terms_accepted';
        $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
            'terms_accepted' => true,
            'terms_accepted_at' => now()->toISOString(),
        ]);
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function number(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'msisdn' => ['required', 'string', 'max:20'],
        ]);

        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        $normalized = $this->normalizeMsisdn($payload['msisdn']);
        if ($normalized === null) {
            return $this->fail('Invalid phone number format.', 422);
        }

        $exists = false;
        $whitelisted = false;
        $subscriber = Subscriber::query()
            ->whereIn('msisdn', [$normalized, '+267'.$normalized, '267'.$normalized])
            ->first();

        if ($subscriber) {
            $exists = true;
            $whitelisted = (bool) $subscriber->is_whitelisted;
        }

        if (!$exists || !$whitelisted) {
            $serviceRequest->status = 'number_not_verified';
            $serviceRequest->save();

            return $this->fail('Phone number is not eligible for this exercise.', 422);
        }

        $serviceRequest->msisdn = $normalized;
        $serviceRequest->current_step = 'registration';
        $serviceRequest->status = 'number_verified';
        $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
            'number_verified_at' => now()->toISOString(),
            'subscriber_lookup' => [
                'exists' => $exists,
                'whitelisted' => $whitelisted,
            ],
        ]);
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'msisdn' => $serviceRequest->msisdn,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function registration(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'plot_number' => ['nullable', 'string', 'max:50'],
            'ward' => ['nullable', 'string', 'max:50'],
            'village' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_address' => ['nullable', 'string', 'max:200'],
            'next_of_kin_name' => ['nullable', 'string', 'max:100'],
            'next_of_kin_relation' => ['nullable', 'string', 'max:50'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        RegistrationProfile::updateOrCreate(
            ['service_request_id' => $serviceRequest->id],
            Arr::only($payload, [
                'plot_number',
                'ward',
                'village',
                'city',
                'postal_address',
                'next_of_kin_name',
                'next_of_kin_relation',
                'next_of_kin_phone',
                'email',
            ])
        );

        $serviceRequest->current_step = 'verification';
        $serviceRequest->status = 'registration_completed';
        $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
            'registration_completed_at' => now()->toISOString(),
        ]);
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    public function startKyc(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'document_type' => ['required', Rule::in(['omang', 'passport'])],
            'session_id' => ['nullable', 'string', 'max:255'],
            'verification_id' => ['nullable', 'string', 'max:255'],
            'identity_id' => ['nullable', 'string', 'max:255'],
        ]);

        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        $verification = KycVerification::create([
            'service_request_id' => $serviceRequest->id,
            'provider' => 'metamap',
            'session_id' => $payload['session_id'] ?? null,
            'verification_id' => $payload['verification_id'] ?? null,
            'identity_id' => $payload['identity_id'] ?? null,
            'status' => 'pending',
            'document_type' => $payload['document_type'],
            'raw_response' => null,
        ]);

        $serviceRequest->current_step = 'verification';
        $serviceRequest->status = 'kyc_pending';
        $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
            'kyc_started_at' => now()->toISOString(),
            'kyc_verification_id' => $verification->id,
        ]);
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'kyc_verification_id' => (string) $verification->id,
            'status' => $verification->status,
        ], 201);
    }

    public function status(Request $request, string $requestId): JsonResponse
    {
        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        $verification = $this->resolveBestVerification($serviceRequest->id);
        if ($verification) {
            $verification = $this->refreshFromMetaMapIfNeeded($verification, $serviceRequest);
        }

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'request_status' => $serviceRequest->status,
            'current_step' => $serviceRequest->current_step,
            'status' => $verification ? $this->mapWebhookStatus($verification) : 'pending',
            'verification' => $verification ? [
                'id' => (string) $verification->id,
                'status' => $this->mapWebhookStatus($verification),
                'document_type' => $verification->document_type,
                'failure_reason' => $verification->failure_reason,
                'verification_id' => $verification->verification_id,
                'identity_id' => $verification->identity_id,
            ] : null,
        ]);
    }

    public function complete(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->validate([
            'verified' => ['required', 'boolean'],
            'kyc_verification_id' => ['nullable', 'integer'],
        ]);

        $serviceRequest = $this->findKycRequest($requestId);
        if (!$serviceRequest) {
            return $this->fail('KYC request not found.', 404);
        }

        if ($payload['verified'] !== true) {
            $serviceRequest->status = 'failed';
            $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
                'failed_at' => now()->toISOString(),
            ]);
            $serviceRequest->save();

            return $this->fail('KYC verification must be successful before completion.', 422);
        }

        $verification = null;
        if (!empty($payload['kyc_verification_id'])) {
            $verification = KycVerification::query()
                ->where('id', $payload['kyc_verification_id'])
                ->where('service_request_id', $serviceRequest->id)
                ->first();
        } else {
            $verification = KycVerification::query()
                ->where('service_request_id', $serviceRequest->id)
                ->latest('id')
                ->first();
        }

        if ($verification) {
            $verification->status = 'verified';
            $verification->save();
        }

        $serviceRequest->current_step = 'complete';
        $serviceRequest->status = 'completed';
        $serviceRequest->metadata = $this->mergeMetadata($serviceRequest, [
            'completed_at' => now()->toISOString(),
        ]);
        $serviceRequest->save();

        return $this->ok([
            'request_id' => (string) $serviceRequest->id,
            'current_step' => $serviceRequest->current_step,
            'status' => $serviceRequest->status,
        ]);
    }

    private function findKycRequest(string $requestId): ?ServiceRequest
    {
        return ServiceRequest::query()
            ->where('id', $requestId)
            ->where('request_type', 'kyc_compliance')
            ->first();
    }

    private function mergeMetadata(ServiceRequest $serviceRequest, array $next): array
    {
        $current = is_array($serviceRequest->metadata) ? $serviceRequest->metadata : [];
        return array_merge($current, $next);
    }

    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '267') && strlen($digits) === 11) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) !== 8) {
            return null;
        }

        return $digits;
    }

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

    private function refreshFromMetaMapIfNeeded(KycVerification $verification, ServiceRequest $serviceRequest): KycVerification
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

        $serviceRequest->status = $mappedStatus === 'verified' ? 'kyc_verified' : 'kyc_rejected';
        $serviceRequest->current_step = $mappedStatus === 'verified' ? 'complete' : 'verification';
        $serviceRequest->save();

        return $verification->fresh() ?? $verification;
    }
}
