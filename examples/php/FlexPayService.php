<?php
/**
 * FlexPay Mobile Money Service — PHP
 *
 * Integration complète pour les paiements Mobile Money via FlexPay.cd.
 * Compatible PHP 8.0+ — pas de framework requis.
 *
 * Usage :
 *   $flexpay = new FlexPayService();
 *   $resp = $flexpay->initiateMobileMoney('0812345678', 'REF-123', 25.00, 'USD');
 *   if ($resp['ok'] && $resp['data']['code'] === '0') {
 *       // Succès — rediriger vers la page d'attente
 *       header('Location: /checkout/pending/' . $paymentId);
 *   }
 *
 * Mode simulé : définir FLEXPAY_MERCHANT_CODE=SIMULATED dans .env
 * pour tester le flux complet sans transactions réelles.
 */

class FlexPayService
{
    private string $apiUrl;
    private string $apiToken;
    private string $merchantCode;
    private string $checkUrl;
    private string $callbackUrl;

    public function __construct()
    {
        $this->apiUrl       = rtrim(Config::get('FLEXPAY_API_URL', 'https://backend.flexpay.cd/api/rest/v1'), '/');
        $this->apiToken     = self::normalizeToken(Config::get('FLEXPAY_API_TOKEN', ''));
        $this->merchantCode = Config::get('FLEXPAY_MERCHANT_CODE', '');
        $this->checkUrl     = rtrim(Config::get('FLEXPAY_CHECK_URL', $this->apiUrl . '/check'), '/');
        $this->callbackUrl  = Config::get('FLEXPAY_CALLBACK_URL', '');
    }

    // ═══════════════════════════════════════════════════════════════
    // Mode simulation
    // ═══════════════════════════════════════════════════════════════

    public static function isSimulated(): bool
    {
        return strtoupper((string) Config::get('FLEXPAY_MERCHANT_CODE', '')) === 'SIMULATED';
    }

    private function simulatedResponse(string $reference): array
    {
        $orderNumber = 'SIM' . substr(bin2hex(random_bytes(8)), 0, 16) . time();
        return [
            'ok'           => true,
            'http_code'    => 200,
            'error'        => null,
            'data'         => [
                'code'        => '0',
                'message'     => 'Simulated transaction',
                'orderNumber' => $orderNumber,
            ],
            'raw_request'  => json_encode(['simulated' => true, 'reference' => $reference]),
            'raw_response' => json_encode(['code' => '0', 'orderNumber' => $orderNumber]),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Mobile Money — Initiation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Initie un paiement Mobile Money.
     * L'utilisateur recevra une notification push sur son téléphone.
     *
     * @param string $phone    Numéro au format local ou international
     * @param string $reference Référence unique de la transaction
     * @param float  $amount   Montant en USD
     * @param string $currency Devise (défaut: USD)
     * @return array{ok:bool, http_code:int, error:?string, data:?array, raw_request:string, raw_response:string}
     */
    public function initiateMobileMoney(
        string $phone,
        string $reference,
        float $amount,
        string $currency = 'USD'
    ): array {
        if (self::isSimulated()) {
            return $this->simulatedResponse($reference);
        }

        $body = [
            'merchant'    => $this->merchantCode,
            'type'        => '1',
            'phone'       => self::normalizePhone($phone),
            'reference'   => $reference,
            'amount'      => (string) $amount,
            'currency'    => $currency,
            'callbackUrl' => $this->callbackUrl,
        ];

        return $this->request('POST', $this->apiUrl . '/paymentService', $body);
    }

    // ═══════════════════════════════════════════════════════════════
    // Vérification du statut
    // ═══════════════════════════════════════════════════════════════

    /**
     * Vérifie le statut d'une transaction via l'orderNumber.
     *
     * @return array{ok:bool, data:?array{code:string, transaction:array{status:string, channel:string}}}
     */
    public function checkTransaction(string $orderNumber): array
    {
        if (self::isSimulated()) {
            return [
                'ok'        => true,
                'http_code' => 200,
                'error'     => null,
                'data'      => [
                    'code'    => '0',
                    'message' => 'Simulated transaction',
                    'transaction' => [
                        'orderNumber' => $orderNumber,
                        'status'      => '0',
                        'channel'     => 'simulated',
                    ],
                ],
                'raw_request'  => null,
                'raw_response' => json_encode(['simulated' => true, 'orderNumber' => $orderNumber]),
            ];
        }

        return $this->request('GET', $this->checkUrl . '/' . rawurlencode($orderNumber), null);
    }

    // ═══════════════════════════════════════════════════════════════
    // Merchant Pay Out (retrait organisateur)
    // ═══════════════════════════════════════════════════════════════

    public function merchantPayOut(
        string $phone,
        string $reference,
        float $amount,
        string $currency = 'USD'
    ): array {
        if (self::isSimulated()) {
            return $this->simulatedResponse($reference);
        }

        $payoutUrl = rtrim(Config::get('FLEXPAY_PAYOUT_URL', $this->apiUrl . '/merchantPayOutService'), '/');
        $body = [
            'merchant'    => $this->merchantCode,
            'type'        => '1',
            'phone'       => self::normalizePhone($phone),
            'reference'   => $reference,
            'amount'      => (string) $amount,
            'currency'    => $currency,
            'callbackUrl' => $this->callbackUrl,
        ];

        return $this->request('POST', $payoutUrl, $body);
    }

    // ═══════════════════════════════════════════════════════════════
    // HTTP Core
    // ═══════════════════════════════════════════════════════════════

    private function request(string $method, string $url, ?array $body): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: ' . $this->apiToken,
        ];
        $bodyJson = $body ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok'           => false,
                'http_code'    => 0,
                'error'        => $err ?: 'curl_exec failed',
                'data'         => null,
                'raw_request'  => $bodyJson,
                'raw_response' => null,
            ];
        }

        $decoded = json_decode($raw, true);
        return [
            'ok'           => $httpCode >= 200 && $httpCode < 300,
            'http_code'    => $httpCode,
            'error'        => null,
            'data'         => is_array($decoded) ? $decoded : null,
            'raw_request'  => $bodyJson,
            'raw_response' => $raw,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers statiques
    // ═══════════════════════════════════════════════════════════════

    /**
     * Normalise le token : garantit le préfixe "Bearer ".
     */
    public static function normalizeToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        return stripos($token, 'Bearer ') === 0 ? $token : 'Bearer ' . $token;
    }

    /**
     * Normalise un numéro de téléphone au format international 243XXXXXXXXX.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '243' . substr($digits, 1);
        } elseif (!str_starts_with($digits, '243') && strlen($digits) === 9) {
            $digits = '243' . $digits;
        }
        return $digits;
    }

    /**
     * Génère une référence unique pour une transaction.
     */
    public static function buildReference(int $eventId, int $userId): string
    {
        return sprintf('EVT%d-U%d-%d-%s', $eventId, $userId, time(), bin2hex(random_bytes(3)));
    }
}
