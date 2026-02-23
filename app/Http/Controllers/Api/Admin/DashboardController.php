<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\KycVerification;
use App\Models\PaymentTransaction;
use App\Models\ServiceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;

class DashboardController extends BaseApiController
{
    public function index(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $totalRequests = ServiceRequest::query()->count();
        $completedRequests = ServiceRequest::query()->where('status', 'completed')->count();
        $pendingKyc = KycVerification::query()->where('status', 'pending')->count();
        $totalRevenue = (float) PaymentTransaction::query()
            ->where('status', 'completed')
            ->sum('amount');

        $recentRequests = ServiceRequest::query()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'request_type', 'msisdn', 'status', 'current_step', 'created_at']);

        $kycVerifications = KycVerification::query()
            ->latest('id')
            ->get();

        $serviceTypeByRequestId = ServiceRequest::query()
            ->whereIn('id', $kycVerifications->pluck('service_request_id')->filter()->unique())
            ->pluck('request_type', 'id');

        $records = $kycVerifications
            ->map(fn (KycVerification $verification) => $this->transformKycRecord($verification, $serviceTypeByRequestId))
            ->values();

        $todayStart = now()->startOfDay();
        $stats = [
            'total' => $records->count(),
            'pending' => $records->where('status', 'pending')->count(),
            'verified' => $records->where('status', 'verified')->count(),
            'rejected' => $records->where('status', 'rejected')->count(),
            'expired' => $records->where('status', 'expired')->count(),
            'omang' => $records->where('document_type', 'omang')->count(),
            'passport' => $records->where('document_type', 'passport')->count(),
            'esimPurchase' => $records->where('service_type', 'esim_purchase')->count(),
            'simSwap' => $records->where('service_type', 'sim_swap')->count(),
            'newPhysicalSim' => $records->where('service_type', 'new_physical_sim')->count(),
            'kycCompliance' => $records->where('service_type', 'kyc_compliance')->count(),
            'smegaRegistration' => $records->where('service_type', 'smega_registration')->count(),
            'todayCount' => $records->filter(function (array $row) use ($todayStart): bool {
                if (empty($row['created_at'])) {
                    return false;
                }

                return Carbon::parse($row['created_at'])->greaterThanOrEqualTo($todayStart);
            })->count(),
        ];

        return $this->ok([
            'stats' => [
                'total_requests' => $totalRequests,
                'completed_requests' => $completedRequests,
                'pending_kyc' => $pendingKyc,
                'total_revenue' => $totalRevenue,
            ],
            'dashboard' => [
                'stats' => $stats,
            ],
            'records' => $records,
            'kyc_records' => $records,
            'recent_requests' => $recentRequests,
        ]);
    }

    private function transformKycRecord(KycVerification $verification, $serviceTypeByRequestId): array
    {
        $raw = is_array($verification->raw_response) ? $verification->raw_response : [];
        $meta = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        $full = is_array($raw['full_verification'] ?? null) ? $raw['full_verification'] : [];
        $fullMeta = is_array($full['metadata'] ?? null) ? $full['metadata'] : [];

        $doc = [];
        if (is_array($full['documents'] ?? null) && isset($full['documents'][0]) && is_array($full['documents'][0])) {
            $doc = $full['documents'][0];
        }
        $docFields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];

        $fullName = $verification->full_name
            ?? $this->pickFieldValue($docFields, ['fullName', 'name'])
            ?? null;
        $firstName = $verification->first_name
            ?? $this->pickFieldValue($docFields, ['firstName', 'givenName'])
            ?? null;
        $surname = $verification->surname
            ?? $this->pickFieldValue($docFields, ['surname', 'lastName', 'familyName'])
            ?? null;
        $countryAbbreviation = $this->pickFieldValue($docFields, ['nationality', 'issueCountry']);
        $selfieUrl = $verification->selfie_url
            ?? $this->extractSelfieFromFullVerification($full);
        $documentPhotoUrls = is_array($verification->document_photo_urls) && count($verification->document_photo_urls) > 0
            ? array_values($verification->document_photo_urls)
            : $this->extractDocumentPhotosFromFullVerification($full);

        $serviceType = $this->detectServiceType($meta, $fullMeta)
            ?? ($serviceTypeByRequestId[$verification->service_request_id] ?? null);
		// Safely construct the physical address
        $plot = $meta['plotNumber'] ?? $fullMeta['plotNumber'] ?? null;
        $ward = $meta['ward'] ?? $fullMeta['ward'] ?? null;
        $village = $meta['village'] ?? $fullMeta['village'] ?? null;
        $city = $meta['city'] ?? $fullMeta['city'] ?? null;

        $physicalAddress = collect([$plot, $ward, $village, $city])
            ->filter()
            ->implode(', ');

        return [
            'id' => (string) $verification->id,
            'msisdn' => $meta['msisdn'] ?? $fullMeta['msisdn'] ?? null,
            'metadata' => $meta,
            'country' => $verification->country,
            'country_abbreviation' => $countryAbbreviation,
            'full_name' => $fullName,
            'first_name' => $firstName,
            'surname' => $surname,
            'date_of_birth' => $verification->date_of_birth?->toDateString(),
            'sex' => $verification->sex,
            'document_type' => $this->normalizeDocumentType($verification->document_type),
            'document_number' => $verification->document_number,
            'physical_address' => $physicalAddress ?? null,
            'postal_address' => $meta['postalAddress'] ?? $fullMeta['postalAddress'] ?? null,
            'date_of_issue' => $this->pickFieldValue($docFields, ['emissionDate', 'dateOfIssue']),
            'expiry_date' => $verification->expiry_date?->toDateString(),
            'email' => $meta['email'] ?? $fullMeta['email'] ?? null,
            'next_of_kin_name' => $meta['nextOfKinName'] ?? $fullMeta['nextOfKinName'] ?? null,
            'next_of_kin_relation' => $meta['nextOfKinRelation'] ?? $fullMeta['nextOfKinRelation'] ?? null,
            'next_of_kin_phone' => $meta['nextOfKinPhone'] ?? $fullMeta['nextOfKinPhone'] ?? null,
            'plot_number' => $meta['plotNumber'] ?? $fullMeta['plotNumber'] ?? null,
            'ward' => $meta['ward'] ?? $fullMeta['ward'] ?? null,
            'village' => $meta['village'] ?? $fullMeta['village'] ?? null,
            'city' => $meta['city'] ?? $fullMeta['city'] ?? null,
            'add_phone_number_1' => $meta['addPhoneNumber1'] ?? null,
            'add_phone_number_2' => $meta['addPhoneNumber2'] ?? null,
            'add_phone_number_3' => $meta['addPhoneNumber3'] ?? null,
            'add_phone_number_4' => $meta['addPhoneNumber4'] ?? null,
            'add_phone_number_5' => $meta['addPhoneNumber5'] ?? null,
            'add_phone_number_6' => $meta['addPhoneNumber6'] ?? null,
            'add_phone_number_7' => $meta['addPhoneNumber7'] ?? null,
            'add_phone_number_8' => $meta['addPhoneNumber8'] ?? null,
            'add_phone_number_9' => $meta['addPhoneNumber9'] ?? null,
            'add_phone_number_10' => $meta['addPhoneNumber10'] ?? null,
            'selfie_url' => $selfieUrl,
            'document_photo_urls' => $documentPhotoUrls,
            'service_type' => $serviceType,
            'status' => $this->normalizeStatus($verification->status),
            'created_at' => optional($verification->created_at)->toISOString(),
            'updated_at' => optional($verification->updated_at)->toISOString(),
        ];
    }

    private function pickFieldValue(array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];
            if (is_array($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }

            if ($value === null) {
                continue;
            }

            $str = trim((string) $value);
            if ($str !== '') {
                return $str;
            }
        }

        return null;
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            'expired' => 'expired',
            default => 'pending',
        };
    }

    private function normalizeDocumentType(?string $type): string
    {
        $normalized = strtolower((string) $type);
        return in_array($normalized, ['omang', 'passport'], true) ? $normalized : 'omang';
    }

    private function detectServiceType(array $metadata, array $fullMetadata): ?string
    {
        $value = $metadata['serviceType']
            ?? $fullMetadata['serviceType']
            ?? $metadata['flowType']
            ?? $fullMetadata['flowType']
            ?? null;

        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'esim_purchase', 'buy_esim', 'esim' => 'esim_purchase',
            'sim_swap', 'simswap' => 'sim_swap',
            'new_physical_sim', 'physical_sim' => 'new_physical_sim',
            'kyc_compliance' => 'kyc_compliance',
            'smega_registration' => 'smega_registration',
            default => null,
        };
    }

    private function extractSelfieFromFullVerification(array $full): ?string
    {
        $steps = is_array($full['steps'] ?? null) ? $full['steps'] : [];

        foreach ($steps as $step) {
            if (!is_array($step) || !is_array($step['data'] ?? null)) {
                continue;
            }

            $candidate = $step['data']['selfieUrl']
                ?? $step['data']['selfiePhotoUrl']
                ?? $step['data']['selfie']
                ?? null;

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractDocumentPhotosFromFullVerification(array $full): array
    {
        $documents = is_array($full['documents'] ?? null) ? $full['documents'] : [];
        $urls = [];

        foreach ($documents as $document) {
            if (!is_array($document) || !is_array($document['photos'] ?? null)) {
                continue;
            }

            foreach ($document['photos'] as $photo) {
                if (is_string($photo) && trim($photo) !== '') {
                    $urls[] = trim($photo);
                }
            }
        }

        return array_values(array_unique($urls));
    }
}
