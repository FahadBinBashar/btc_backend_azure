<?php

namespace App\Http\Controllers\Api;

use App\Models\KycVerification;
use App\Models\MetaMapWebhookEvent;
use App\Models\ServiceRequest;
use App\Services\MatiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MetaMapController extends BaseApiController
{
    public function config(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'document_type' => ['required', 'in:omang,passport'],
        ]);

        $clientId = (string) config('services.metamap.client_id', '');
        $citizenFlowId = (string) config('services.metamap.citizen_flow_id', '');
        $nonCitizenFlowId = (string) config('services.metamap.non_citizen_flow_id', '');

        if ($clientId === '') {
            return $this->fail('MetaMap client is not configured.', 500);
        }

        $flowId = $payload['document_type'] === 'omang' ? $citizenFlowId : $nonCitizenFlowId;
        if ($flowId === '') {
            return $this->fail('MetaMap flow is not configured for this document type.', 500);
        }

        return $this->ok([
            'client_id' => $clientId,
            'flow_id' => $flowId,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $secret = (string) config('services.metamap.webhook_secret', '');
        $signature = (string) (
            $request->header('x-signature')
            ?? $request->header('x-webhook-signature')
            ?? $request->header('x-metamap-signature')
            ?? ''
        );
        $signatureValid = null;
        $eventSaved = false;

        if ($secret !== '') {
            $signatureValid = $this->isValidWebhookSignature($rawBody, $signature, $secret);
            if (!$signatureValid) {
                $eventSaved = $this->storeWebhookEvent($rawBody, null, $signature, false);
                Log::warning('MetaMap webhook signature invalid');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                    'event_saved' => $eventSaved,
                ], 401);
            }
        }

        $payload = json_decode($rawBody, true);
        $eventSaved = $this->storeWebhookEvent($rawBody, is_array($payload) ? $payload : null, $signature, $signatureValid);

        if (!is_array($payload)) {
            return $this->fail('Invalid JSON payload.', 422);
        }

        $eventName = (string) ($payload['eventName'] ?? '');
        $step = is_array($payload['step'] ?? null) ? $payload['step'] : [];
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $verificationId = $this->stringOrNull($payload['verificationId'] ?? null)
            ?? $this->extractVerificationIdFromResource($this->stringOrNull($payload['resource'] ?? null));

        if ($verificationId !== null) {
            $payload['verificationId'] = $verificationId;
        }

        $fullVerificationData = null;
        if (strtolower($eventName) === 'verification_completed' && $verificationId !== null) {
            $fullVerificationData = app(MatiService::class)->getVerification($verificationId);
            if (is_array($fullVerificationData)) {
                $payload['full_verification'] = $fullVerificationData;
                $payload['verificationId'] = $this->stringOrNull($fullVerificationData['id'] ?? null) ?? $verificationId;
                $payload['identityId'] = $this->stringOrNull($fullVerificationData['identity']['id'] ?? null)
                    ?? $this->stringOrNull($payload['identityId'] ?? null);
            }
        }

        $identityStatus = $this->stringOrNull($payload['identityStatus'] ?? null);
        $webhookStatus = $this->stringOrNull($payload['status'] ?? null);
        $actualStatus = $webhookStatus
            ?? $identityStatus
            ?? $this->stringOrNull($fullVerificationData['identity']['status'] ?? null)
            ?? $this->stringOrNull($step['status'] ?? null);

        $verification = $this->resolveVerification($payload, $metadata);
        $serviceRequest = $verification
            ? ServiceRequest::query()->where('id', $verification->service_request_id)->first()
            : $this->resolveServiceRequestFromMetadata($metadata);

        if (!$serviceRequest) {
            $serviceRequest = $this->resolveServiceRequestFromMsisdn($payload, $metadata);
        }

        if (!$verification && !$serviceRequest) {
            $serviceRequest = $this->bootstrapServiceRequestFromWebhook($payload, $metadata);
        }

        if (!$verification && $serviceRequest) {
            $verification = KycVerification::create([
                'service_request_id' => $serviceRequest->id,
                'provider' => 'metamap',
                'verification_id' => $this->stringOrNull($payload['verificationId'] ?? null),
                'identity_id' => $this->stringOrNull($payload['identityId'] ?? null),
                'status' => 'pending',
                'document_type' => $this->resolveDocumentType($payload, $metadata),
                'raw_response' => $payload,
            ]);
        }

        if (!$verification) {
            Log::warning('MetaMap webhook received but no matching KYC record', [
                'event' => $eventName,
                'verification_id' => $payload['verificationId'] ?? null,
                'identity_id' => $payload['identityId'] ?? null,
            ]);

            return $this->ok([
                'message' => 'Webhook received, no matching KYC record found',
                'event_name' => $eventName,
                'event_saved' => $eventSaved,
            ]);
        }

        $fields = $this->extractFields($payload);
        $status = $this->mapMetaMapStatus($actualStatus);
        $media = $this->extractMediaUrls($payload);

        $verification->fill(array_filter([
            'verification_id' => $this->stringOrNull($payload['verificationId'] ?? null) ?? $verification->verification_id,
            'identity_id' => $this->stringOrNull($payload['identityId'] ?? null) ?? $verification->identity_id,
            'status' => $status,
            'document_type' => $this->resolveDocumentType($payload, $metadata) ?? $verification->document_type,
            'full_name' => $fields['full_name'] ?? null,
            'first_name' => $fields['first_name'] ?? null,
            'surname' => $fields['surname'] ?? null,
            'date_of_birth' => $fields['date_of_birth'] ?? null,
            'sex' => $fields['sex'] ?? null,
            'country' => $fields['country'] ?? null,
            'document_number' => $fields['document_number'] ?? null,
            'expiry_date' => $fields['expiry_date'] ?? null,
            'failure_reason' => $status === 'rejected'
                ? ($this->stringOrNull($details['reason'] ?? null) ?? 'Verification rejected')
                : null,
            'selfie_url' => $media['selfie_url'],
            'document_photo_urls' => $media['document_photo_urls'],
            'raw_response' => $payload,
        ], static fn ($value) => $value !== null));
        $verification->save();

        if ($serviceRequest) {
            $serviceRequest->status = $status === 'verified'
                ? 'kyc_verified'
                : ($status === 'rejected' ? 'kyc_rejected' : 'kyc_pending');
            $serviceRequest->current_step = $status === 'verified' ? 'complete' : 'verification';
            $serviceRequest->save();
        }

        return $this->ok([
            'message' => 'MetaMap webhook processed',
            'event_name' => $eventName,
            'verification_id' => (string) $verification->id,
            'status' => $verification->status,
            'event_saved' => $eventSaved,
        ]);
    }

    private function resolveVerification(array $payload, array $metadata): ?KycVerification
    {
        $recordId = $metadata['recordId'] ?? $metadata['kyc_verification_id'] ?? null;
        if (is_numeric($recordId)) {
            $byId = KycVerification::query()->where('id', (int) $recordId)->first();
            if ($byId) {
                return $byId;
            }
        }

        $verificationId = $this->stringOrNull($payload['verificationId'] ?? null);
        if ($verificationId) {
            $byVerification = KycVerification::query()->where('verification_id', $verificationId)->latest('id')->first();
            if ($byVerification) {
                return $byVerification;
            }
        }

        $identityId = $this->stringOrNull($payload['identityId'] ?? null);
        if ($identityId) {
            $byIdentity = KycVerification::query()->where('identity_id', $identityId)->latest('id')->first();
            if ($byIdentity) {
                return $byIdentity;
            }
        }

        $sessionId = $this->stringOrNull($metadata['sessionId'] ?? $metadata['session_id'] ?? null);
        if ($sessionId) {
            $bySession = KycVerification::query()->where('session_id', $sessionId)->latest('id')->first();
            if ($bySession) {
                return $bySession;
            }
        }

        $serviceRequest = $this->resolveServiceRequestFromMetadata($metadata);
        if ($serviceRequest) {
            return KycVerification::query()
                ->where('service_request_id', $serviceRequest->id)
                ->latest('id')
                ->first();
        }

        $serviceRequestByMsisdn = $this->resolveServiceRequestFromMsisdn($payload, $metadata);
        if ($serviceRequestByMsisdn) {
            return KycVerification::query()
                ->where('service_request_id', $serviceRequestByMsisdn->id)
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function resolveServiceRequestFromMetadata(array $metadata): ?ServiceRequest
    {
        $requestId = $metadata['requestId'] ?? $metadata['serviceRequestId'] ?? $metadata['service_request_id'] ?? null;

        if (!is_numeric($requestId)) {
            return null;
        }

        return ServiceRequest::query()->where('id', (int) $requestId)->first();
    }

    private function resolveDocumentType(array $payload, array $metadata): ?string
    {
        $type = $this->stringOrNull($metadata['documentType'] ?? null);
        if ($type) {
            return strtolower($type);
        }

        $flowId = $this->stringOrNull($payload['flowId'] ?? null);
        $citizenFlowId = (string) config('services.metamap.citizen_flow_id', '');
        $nonCitizenFlowId = (string) config('services.metamap.non_citizen_flow_id', '');

        if ($flowId && $flowId === $nonCitizenFlowId) {
            return 'passport';
        }

        if ($flowId && $flowId === $citizenFlowId) {
            return 'omang';
        }

        return null;
    }

    private function extractFields(array $payload): array
    {
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $documentData = is_array($details['document']['data'] ?? null) ? $details['document']['data'] : [];
        $stepData = is_array($payload['step']['data'] ?? null) ? $payload['step']['data'] : [];
        $extractedData = is_array($details['extractedData'] ?? null) ? $details['extractedData'] : [];
        $fullVerification = is_array($payload['full_verification'] ?? null) ? $payload['full_verification'] : [];
        $docFields = [];
        if (is_array($fullVerification['documents'] ?? null) && isset($fullVerification['documents'][0]['fields']) && is_array($fullVerification['documents'][0]['fields'])) {
            $docFields = $fullVerification['documents'][0]['fields'];
        }
        $candidate = array_merge($docFields, $extractedData, $documentData, $stepData);

        $fullName = $this->pickString($candidate, ['fullName', 'full_name', 'name', 'completeName']);
        $firstName = $this->pickString($candidate, ['firstName', 'first_name', 'givenName', 'forenames', 'givenNames']);
        $surname = $this->pickString($candidate, ['lastName', 'surname', 'familyName', 'last_name']);

        if (!$fullName && $firstName && $surname) {
            $fullName = trim($firstName.' '.$surname);
        }

        return array_filter([
            'full_name' => $fullName,
            'first_name' => $firstName,
            'surname' => $surname,
            'date_of_birth' => $this->normalizeDate($this->pickString($candidate, ['dateOfBirth', 'dob', 'birthDate', 'date_of_birth'])),
            'sex' => $this->pickString($candidate, ['sex', 'gender', 'Gender']),
            'country' => $this->pickString($candidate, ['country', 'nationality', 'countryOfOrigin', 'issuingCountry']),
            'document_number' => $this->pickString($candidate, ['documentNumber', 'idNumber', 'passportNumber', 'omangNumber', 'nationalId']),
            'expiry_date' => $this->normalizeDate($this->pickString($candidate, ['expiryDate', 'expiry_date', 'expirationDate', 'validUntil', 'dateOfExpiry'])),
        ], static fn ($value) => $value !== null);
    }

    private function extractMediaUrls(array $payload): array
    {
        $fullVerification = is_array($payload['full_verification'] ?? null) ? $payload['full_verification'] : [];
        $steps = is_array($fullVerification['steps'] ?? null) ? $fullVerification['steps'] : [];

        $selfieUrl = null;
        foreach ($steps as $step) {
            if (!is_array($step) || !is_array($step['data'] ?? null)) {
                continue;
            }

            $candidate = $this->stringOrNull(
                $step['data']['selfieUrl']
                ?? $step['data']['selfiePhotoUrl']
                ?? $step['data']['selfie']
                ?? null
            );

            if ($candidate !== null) {
                $selfieUrl = $candidate;
                break;
            }
        }

        $documents = is_array($fullVerification['documents'] ?? null) ? $fullVerification['documents'] : [];
        $documentPhotoUrls = [];

        foreach ($documents as $document) {
            if (!is_array($document) || !is_array($document['photos'] ?? null)) {
                continue;
            }

            foreach ($document['photos'] as $photo) {
                $url = $this->stringOrNull($photo);
                if ($url !== null) {
                    $documentPhotoUrls[] = $url;
                }
            }
        }

        $documentPhotoUrls = array_values(array_unique($documentPhotoUrls));

        return [
            'selfie_url' => $selfieUrl,
            'document_photo_urls' => count($documentPhotoUrls) > 0 ? $documentPhotoUrls : null,
        ];
    }

    private function pickString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (is_array($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }

            $stringValue = $this->stringOrNull($value);
            if ($stringValue !== null) {
                return $stringValue;
            }
        }

        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);
        return $stringValue === '' ? null : $stringValue;
    }

    private function mapMetaMapStatus(?string $status): string
    {
        if ($status === null) {
            return 'pending';
        }

        return match (strtolower($status)) {
            'verified' => 'verified',
            'rejected', 'reviewneeded', 'review_needed', 'review' => 'rejected',
            'expired' => 'expired',
            default => 'pending',
        };
    }

    private function isValidWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        if ($signature === '') {
            return true;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        $candidate = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        return hash_equals($expected, $candidate);
    }

    private function storeWebhookEvent(
        string $rawBody,
        ?array $payload,
        string $signature,
        ?bool $signatureValid
    ): bool {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $serviceRequestId = $this->resolveServiceRequestId($metadata ?? []);
        $resourceVerificationId = $this->extractVerificationIdFromResource($this->stringOrNull($payload['resource'] ?? null));

        $eventData = [
            'provider' => 'metamap',
            'event_name' => $this->stringOrNull($payload['eventName'] ?? null),
            'flow_id' => $this->stringOrNull($payload['flowId'] ?? null),
            'verification_id' => $this->stringOrNull($payload['verificationId'] ?? null) ?? $resourceVerificationId,
            'identity_id' => $this->stringOrNull($payload['identityId'] ?? null),
            'resource' => $this->stringOrNull($payload['resource'] ?? null),
            'record_id' => $this->stringOrNull($metadata['recordId'] ?? null),
            'service_request_id' => $serviceRequestId,
            'signature' => $this->stringOrNull($signature),
            'signature_valid' => $signatureValid,
            'event_timestamp' => $this->normalizeDateTime($payload['timestamp'] ?? null),
            'metadata' => $metadata,
            'payload' => $payload,
            'raw_payload' => $rawBody,
        ];

        try {
            if (Schema::hasTable('metamap_webhook_events')) {
                MetaMapWebhookEvent::query()->create($eventData);
                return true;
            }

            if (Schema::hasTable('webhook_data')) {
                DB::table('webhook_data')->insert([
                    'payload' => $rawBody,
                ]);
                return true;
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to persist MetaMap webhook payload', [
                'error' => $exception->getMessage(),
            ]);

            try {
                if (Schema::hasTable('webhook_data')) {
                    DB::table('webhook_data')->insert([
                        'payload' => $rawBody,
                    ]);
                    return true;
                }
            } catch (\Throwable $fallbackException) {
                Log::error('Fallback webhook_data insert failed', [
                    'error' => $fallbackException->getMessage(),
                ]);
            }
        }

        return false;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $stringValue = $this->stringOrNull($value);
        if (!$stringValue) {
            return null;
        }

        try {
            return Carbon::parse($stringValue)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveServiceRequestId(array $metadata): ?int
    {
        $requestId = $metadata['requestId'] ?? $metadata['serviceRequestId'] ?? $metadata['service_request_id'] ?? null;

        return is_numeric($requestId) ? (int) $requestId : null;
    }

    private function resolveServiceRequestFromMsisdn(array $payload, array $metadata): ?ServiceRequest
    {
        $fullVerificationMetadata = is_array($payload['full_verification']['metadata'] ?? null)
            ? $payload['full_verification']['metadata']
            : [];

        $msisdn = $this->stringOrNull($metadata['msisdn'] ?? null)
            ?? $this->stringOrNull($fullVerificationMetadata['msisdn'] ?? null);

        if ($msisdn === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';
        if (strlen($digits) > 8) {
            $digits = substr($digits, -8);
        }

        if ($digits === '') {
            return null;
        }

        return ServiceRequest::query()
            ->where('msisdn', $digits)
            ->latest('id')
            ->first();
    }

    private function bootstrapServiceRequestFromWebhook(array $payload, array $metadata): ?ServiceRequest
    {
        $fullVerificationMetadata = is_array($payload['full_verification']['metadata'] ?? null)
            ? $payload['full_verification']['metadata']
            : [];

        $msisdn = $this->stringOrNull($metadata['msisdn'] ?? null)
            ?? $this->stringOrNull($fullVerificationMetadata['msisdn'] ?? null);

        if ($msisdn === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';
        if (strlen($digits) > 8) {
            $digits = substr($digits, -8);
        }

        if ($digits === '') {
            return null;
        }

        return ServiceRequest::query()->create([
            'request_type' => 'kyc_compliance',
            'msisdn' => $digits,
            'status' => 'kyc_pending',
            'current_step' => 'verification',
            'otp_skipped' => true,
            'metadata' => [
                'source' => 'metamap_webhook_bootstrap',
                'bootstrapped_at' => now()->toISOString(),
            ],
        ]);
    }

    private function extractVerificationIdFromResource(?string $resource): ?string
    {
        if ($resource === null || $resource === '') {
            return null;
        }

        $path = parse_url($resource, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = end($segments);

        if (!is_string($last) || $last === '') {
            return null;
        }

        return $last;
    }
}
