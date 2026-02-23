<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MatiService
{
    private ?string $accessToken = null;

    public function authenticate(): bool
    {
        $clientId = (string) config('services.metamap.client_id', '');
        $clientSecret = (string) config('services.metamap.client_secret', '');
        $baseUrl = rtrim((string) config('services.metamap.base_url', 'https://api.getmati.com'), '/');

        if ($clientId === '' || $clientSecret === '') {
            Log::warning('MetaMap credentials are missing');
            return false;
        }

        $authHeader = 'Authorization: Basic '.base64_encode($clientId.':'.$clientSecret);
        $result = $this->curlRequest(
            'POST',
            $baseUrl.'/oauth',
            [
                $authHeader,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'grant_type=client_credentials'
        );

        if (!$result['ok']) {
            Log::error('MetaMap authenticate failed', [
                'status' => $result['status'],
                'body' => $result['body'],
            ]);
            return false;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            Log::error('MetaMap authenticate returned invalid JSON');
            return false;
        }

        $this->accessToken = (string) ($data['access_token'] ?? '');
        return $this->accessToken !== '';
    }

    public function getVerification(string $verificationId): ?array
    {
        $baseUrl = rtrim((string) config('services.metamap.base_url', 'https://api.getmati.com'), '/');

        if ($verificationId === '') {
            return null;
        }

        if ($this->accessToken === null && !$this->authenticate()) {
            return null;
        }

        $result = $this->curlRequest(
            'GET',
            $baseUrl.'/v2/verifications/'.$verificationId,
            [
                'Authorization: Bearer '.(string) $this->accessToken,
                'Accept: application/json',
            ]
        );

        if (!$result['ok']) {
            Log::error('MetaMap verification fetch failed', [
                'verification_id' => $verificationId,
                'status' => $result['status'],
                'body' => $result['body'],
            ]);
            return null;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            Log::error('MetaMap verification returned invalid JSON', [
                'verification_id' => $verificationId,
            ]);
            return null;
        }

        return $data;
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    private function curlRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not initialize cURL'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            Log::error('MetaMap cURL error', ['error' => $curlError, 'url' => $url]);
            return ['ok' => false, 'status' => $statusCode, 'body' => '', 'error' => $curlError !== '' ? $curlError : null];
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status' => $statusCode,
            'body' => (string) $responseBody,
            'error' => null,
        ];
    }
}
