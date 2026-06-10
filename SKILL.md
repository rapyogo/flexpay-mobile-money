---
name: flexpay-mobile-money
description: Use when integrating FlexPay.cd Mobile Money payments into a PHP or Next.js application — implementing checkout flows with Airtel Money, M-Pesa, AfriMoney, or Orange Money via the FlexPay REST API. Covers PHP (pure, no framework) and Next.js 14+ App Router (Route Handlers, Server Actions, Client Components). Includes initiation, polling, callbacks, transaction audit logging, simulated mode for local testing, and security hardening.
---

# FlexPay Mobile Money Integration

## Overview

FlexPay.cd is a DRC payment gateway that processes Mobile Money (Airtel Money, M-Pesa, AfriMoney, Orange Money) and bank card payments. This skill covers the **complete Mobile Money integration pattern** — from service layer to checkout UI to callback handling — extracted from a production ticketing platform.

**Core principle:** The integration follows a three-step async pattern: (1) initiate payment via FlexPay API → (2) user confirms push notification on their phone → (3) FlexPay calls back or you poll to finalize. Every API interaction is logged to an audit table.

## When to Use

```
User needs Mobile Money payments?
├─ DRC/RDC market? → Use FlexPay
├─ Other African markets? → Adapt provider, keep pattern
└─ Global Stripe-like? → Different skill needed
```

**Symptoms that trigger this skill:**
- "Add Mobile Money payment to my PHP / Next.js app"
- "Integrate FlexPay / Airtel Money / M-Pesa / Orange Money / AfriMoney"
- "DRC payment gateway integration"
- "Implement push notification payment flow"
- "Need simulated payment mode for local dev"

**When NOT to use:**
- Card payments only (FlexPay card flow is form-encoded POST, not JSON — different pattern)
- Non-PHP backends (adapt the pattern, but code is PHP-specific)
- Real-time/synchronous payments (Mobile Money is inherently async)

## Architecture Overview

```
┌──────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────────┐
│  Client   │────▶│  Checkout    │────▶│  FlexPay API │────▶│  User Phone  │
│ (Browser) │     │  Controller  │     │  /paymentSvc │     │  (Push MSG)  │
└──────────┘     └──────────────┘     └─────────────┘     └──────────────┘
       │                │                      │                    │
       │          ┌─────▼──────┐        ┌──────▼──────┐            │
       │          │  Pending    │        │  Callback   │◀───────────┘
       │          │  Page (poll)│        │  Controller │  (async)
       │          └─────┬──────┘        └──────┬──────┘
       │                │                      │
       ▼                ▼                      ▼
┌─────────────────────────────────────────────────────┐
│                  Finalize Transaction                │
│  payments.status=completed  tickets.status=paid      │
│  QR generated  stock decremented  email sent         │
└─────────────────────────────────────────────────────┘
```

## Environment Configuration

```ini
# .env — FlexPay configuration
FLEXPAY_MERCHANT_CODE=SIMULATED    # "SIMULATED" for local dev, real code for prod
FLEXPAY_API_URL=https://backend.flexpay.cd/api/rest/v1
FLEXPAY_API_TOKEN=                 # JWT token (with or without "Bearer " prefix)
FLEXPAY_CHECK_URL=https://backend.flexpay.cd/api/rest/v1/check
FLEXPAY_CALLBACK_URL=${APP_URL}/callback/flexpay

# Card (optional — different endpoint, form-encoded)
FLEXPAY_CARD_API_URL=https://cardpayment.flexpay.cd/v2/pay
FLEXPAY_CARD_MERCHANT=
FLEXPAY_CARD_TOKEN=

# Payout (optional — merchant to Mobile Money wallet)
FLEXPAY_PAYOUT_URL=https://backend.flexpay.cd/api/rest/v1/merchantPayOutService

# Security (production — configure AT LEAST one)
FLEXPAY_IP_WHITELIST=              # Comma-separated FlexPay source IPs
FLEXPAY_CALLBACK_TOKEN=            # Shared secret for callback verification
```

## Database Schema

### `payments` table (essential columns)

```sql
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ticket_id INT NULL,           -- FK to purchasable item
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    payment_method VARCHAR(20) DEFAULT 'mobile_money',  -- mobile_money | card | simulated | wallet | pos
    status VARCHAR(20) DEFAULT 'pending',               -- pending | completed | failed | cancelled
    reference VARCHAR(100) NOT NULL,                    -- UNIQUE, merchant-generated
    flexpay_order_number VARCHAR(100) NULL,             -- received from FlexPay after init
    phone VARCHAR(20) NULL,
    channel VARCHAR(50) NULL,                           -- set on completion (mpesa, airtel, etc.)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reference (reference),
    INDEX idx_order_number (flexpay_order_number),
    INDEX idx_status (status)
);
```

### `flexpay_transactions` table (audit log)

```sql
CREATE TABLE flexpay_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NULL,
    direction VARCHAR(20) DEFAULT 'outbound',  -- outbound | check | callback
    order_number VARCHAR(100) NULL,
    reference VARCHAR(100) NULL,
    code VARCHAR(10) NULL,                     -- FlexPay response code: "0" = success
    status VARCHAR(50) NULL,
    amount DECIMAL(10,2) NULL,
    amount_customer DECIMAL(10,2) NULL,
    phone VARCHAR(20) NULL,
    channel VARCHAR(50) NULL,                  -- mpesa, airtel, orangemoney, afrimoney
    provider_reference VARCHAR(255) NULL,
    raw_request TEXT NULL,                     -- Full JSON sent to FlexPay
    raw_response TEXT NULL,                    -- Full JSON received from FlexPay
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_number (order_number),
    INDEX idx_reference (reference),
    INDEX idx_direction (direction)
);
```

## Core Implementation

### 1. FlexPayService (app/Services/FlexPayService.php)

The service encapsulates all HTTP communication with FlexPay. Key design decisions:

- **Simulated mode:** When `FLEXPAY_MERCHANT_CODE=SIMULATED`, no real API calls are made — synthetic success responses are returned. This allows full checkout flow testing without real money.
- **Token normalization:** `normalizeToken()` auto-prepends `Bearer ` if missing, handles both config styles.
- **Phone normalization:** `normalizePhone()` strips non-digits, handles `00`/`0` prefixes, defaults to `243` (DRC country code).
- **SSL verification:** Always enabled (`CURLOPT_SSL_VERIFYPEER=true`) — this is a payment partner, never disable in production.
- **Debug logging:** Non-production environments log requests/responses via `error_log()`.

```php
class FlexPayService
{
    private string $apiUrl;
    private string $apiToken;
    private string $merchantCode;
    private string $checkUrl;
    private string $callbackUrl;

    public function __construct()
    {
        $this->apiUrl = rtrim(Config::get('FLEXPAY_API_URL', 'https://backend.flexpay.cd/api/rest/v1'), '/');
        $this->apiToken = self::normalizeToken(Config::get('FLEXPAY_API_TOKEN', ''));
        $this->merchantCode = Config::get('FLEXPAY_MERCHANT_CODE', '');
        $this->checkUrl = rtrim(Config::get('FLEXPAY_CHECK_URL', $this->apiUrl . '/check'), '/');
        $this->callbackUrl = Config::get('FLEXPAY_CALLBACK_URL', '');
    }

    // ── Mode simulation ──────────────────────────────────────────
    public static function isSimulated(): bool
    {
        return strtoupper((string) Config::get('FLEXPAY_MERCHANT_CODE', '')) === 'SIMULATED';
    }

    private function simulatedResponse(string $reference): array
    {
        $orderNumber = 'SIM' . substr(bin2hex(random_bytes(8)), 0, 16) . time();
        return [
            'ok' => true,
            'http_code' => 200,
            'error' => null,
            'data' => [
                'code' => '0',
                'message' => 'Simulated transaction',
                'orderNumber' => $orderNumber,
            ],
            'raw_request' => json_encode(['simulated' => true, 'reference' => $reference]),
            'raw_response' => json_encode(['code' => '0', 'orderNumber' => $orderNumber]),
        ];
    }

    // ── Initiate Mobile Money ─────────────────────────────────────
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
            'type'        => '1',                          // 1 = Mobile Money
            'phone'       => self::normalizePhone($phone),
            'reference'   => $reference,
            'amount'      => (string) $amount,             // String, not float!
            'currency'    => $currency,
            'callbackUrl' => $this->callbackUrl,
        ];

        return $this->request('POST', $this->apiUrl . '/paymentService', $body);
    }

    // ── Check Transaction Status ──────────────────────────────────
    public function checkTransaction(string $orderNumber): array
    {
        if (self::isSimulated()) {
            return [
                'ok' => true,
                'http_code' => 200,
                'error' => null,
                'data' => [
                    'code' => '0',
                    'message' => 'Simulated transaction',
                    'transaction' => [
                        'orderNumber' => $orderNumber,
                        'status' => '0',                   // 0 = success, 1 = failed
                        'channel' => 'simulated',
                    ],
                ],
            ];
        }
        return $this->request('GET', $this->checkUrl . '/' . rawurlencode($orderNumber), null);
    }

    // ── HTTP Core ─────────────────────────────────────────────────
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   // Toujours vérifier SSL
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'http_code' => 0,
                'error' => $err ?: 'curl_exec failed',
                'data' => null,
                'raw_request' => $bodyJson,
                'raw_response' => null,
            ];
        }

        $decoded = json_decode($raw, true);
        return [
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'error' => null,
            'data' => is_array($decoded) ? $decoded : null,
            'raw_request' => $bodyJson,
            'raw_response' => $raw,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────
    private static function normalizeToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        return stripos($token, 'Bearer ') === 0 ? $token : 'Bearer ' . $token;
    }

    private static function normalizePhone(string $phone): string
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
}
```

### 2. Transaction Audit Log (FlexPayTransaction model)

**Every single API interaction is logged.** This is non-negotiable — without it, debugging payment issues is impossible.

```php
class FlexPayTransaction
{
    public static function log(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO flexpay_transactions
             (payment_id, direction, order_number, reference, code, status,
              amount, amount_customer, phone, channel, provider_reference,
              raw_request, raw_response)
             VALUES (:payment_id, :direction, :order_number, :reference, :code, :status,
                     :amount, :amount_customer, :phone, :channel, :provider_reference,
                     :raw_request, :raw_response)"
        );
        $stmt->execute([
            'payment_id'    => $data['payment_id'] ?? null,
            'direction'     => $data['direction'] ?? 'outbound',
            'order_number'  => $data['order_number'] ?? null,
            'reference'     => $data['reference'] ?? null,
            'code'          => $data['code'] ?? null,
            'status'        => $data['status'] ?? null,
            'amount'        => $data['amount'] ?? null,
            'amount_customer' => $data['amount_customer'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'channel'       => $data['channel'] ?? null,
            'provider_reference' => $data['provider_reference'] ?? null,
            'raw_request'   => $data['raw_request'] ?? null,
            'raw_response'  => $data['raw_response'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }
}
```

### 3. Checkout Flow (Controller)

The checkout follows a strict sequence. Never skip steps.

```
1. show()         → Display checkout form (ticket type, quantity, phone)
2. start()        → Validate CSRF + stock + phone → Create pending Payment + Ticket →
                    Initiate FlexPay → Log transaction → Redirect to pending
3. pending()      → Show "Confirm on your phone" page with JS polling
4. pollStatus()   → JSON endpoint: check FlexPay status every 3s → finalize on success
5. success()      → Show confirmation with QR code
   cancel()       → User cancelled
   decline()      → Payment declined
```

**Key implementation details for `start()`:**

```php
public function start(array $params = []): void
{
    Auth::requireAuth();
    $user = Auth::user();

    // 1. CSRF protection — ALWAYS verify before any payment operation
    if (!Session::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        Session::setFlash('error', 'Session invalide, veuillez réessayer.');
        $this->redirect('/event/' . $params['slug']);
    }

    // 2. Validate event, stock, ticket type
    $event = Event::findBySlug($params['slug']);
    // ... validation checks ...

    // 3. Block self-purchase
    if ((int) ($event['organizer_id'] ?? 0) === (int) $user['id']) {
        Session::setFlash('error', 'Vous ne pouvez pas acheter vos propres billets.');
        $this->redirect('/event/' . $event['slug']);
    }

    // 4. Generate UNIQUE reference
    $reference = sprintf('EVT%d-U%d-%d-%s', $eventId, $userId, time(), bin2hex(random_bytes(3)));

    // 5. Create PENDING ticket + payment BEFORE calling FlexPay
    $ticketId = Ticket::create($userId, $eventId, $ticketTypeId, $quantity, $totalPrice, 'pending');
    $paymentId = Payment::create([
        'user_id' => $userId,
        'ticket_id' => $ticketId,
        'amount' => $totalPrice,
        'currency' => 'USD',
        'payment_method' => 'mobile_money',
        'status' => 'pending',
        'reference' => $reference,
        'phone' => $phone,
    ]);

    // 6. Call FlexPay
    $service = new FlexPayService();
    $resp = $service->initiateMobileMoney($phone, $reference, $totalPrice, 'USD');

    // 7. ALWAYS log the transaction
    FlexPayTransaction::log([
        'payment_id' => $paymentId,
        'direction' => 'outbound',
        'reference' => $reference,
        'phone' => $phone,
        'amount' => $totalPrice,
        'order_number' => $resp['data']['orderNumber'] ?? null,
        'code' => $resp['data']['code'] ?? null,
        'raw_request' => $resp['raw_request'],
        'raw_response' => $resp['raw_response'],
    ]);

    // 8. Handle failure: cancel ticket + payment, show error
    $ok = $resp['ok'] && (($resp['data']['code'] ?? null) === '0')
          && !empty($resp['data']['orderNumber']);
    if (!$ok) {
        Ticket::markCancelled($ticketId);
        Payment::updateStatus($paymentId, 'failed');
        Session::setFlash('error', 'Échec du paiement: ' . ($resp['data']['message'] ?? 'Erreur inconnue'));
        $this->redirect('/event/' . $event['slug']);
    }

    // 9. Store order number and redirect to waiting page
    Payment::setOrderNumber($paymentId, $resp['data']['orderNumber']);
    $this->redirect('/checkout/pending/' . $paymentId);
}
```

### 4. Polling Mechanism (pending.php + pollStatus)

The pending page polls a JSON endpoint every 3 seconds. This is the **client-side resolution path** — the callback is the server-side path. Both can trigger finalization; the atomic status update prevents double-processing.

**JavaScript polling (in the pending view):**

```html
<script>
  (function () {
    const paymentId = <?= (int) $payment['id'] ?>;
    let attempts = 0;
    const maxAttempts = 40; // ~2 min timeout

    async function check() {
      attempts++;
      try {
        const r = await fetch('/checkout/pending/' + paymentId + '/status',
          { cache: 'no-store' });
        const j = await r.json();
        if (j.status === 'completed' && j.redirect) {
          window.location.href = j.redirect;
          return;
        }
        if (j.status === 'failed' || j.status === 'cancelled') {
          showError('Paiement échoué.');
          return;
        }
        if (attempts >= maxAttempts) {
          showError('Délai dépassé. Contactez le support.');
          return;
        }
        setTimeout(check, 3000);
      } catch (e) {
        if (attempts < maxAttempts) setTimeout(check, 5000);
      }
    }
    setTimeout(check, 2000); // First check after 2s
  })();
</script>
```

**Server-side poll endpoint:**

```php
public function pollStatus(array $params = []): void
{
    Auth::requireAuth();
    $payment = Payment::findById((int) $params['id']);

    header('Content-Type: application/json');

    if (!$payment || (int) $payment['user_id'] !== (int) Auth::user()['id']) {
        echo json_encode(['status' => 'not_found']);
        return;
    }

    // If still pending with an order number, query FlexPay
    if ($payment['status'] === 'pending' && !empty($payment['flexpay_order_number'])) {
        $service = new FlexPayService();
        $resp = $service->checkTransaction($payment['flexpay_order_number']);

        // Log the check
        FlexPayTransaction::log([
            'payment_id' => $paymentId,
            'direction' => 'check',
            'order_number' => $payment['flexpay_order_number'],
            'reference' => $payment['reference'],
            'code' => $resp['data']['code'] ?? null,
            'status' => $resp['data']['transaction']['status'] ?? null,
            'channel' => $resp['data']['transaction']['channel'] ?? null,
            'raw_response' => $resp['raw_response'],
        ]);

        $txStatus = $resp['data']['transaction']['status'] ?? null;
        if ($txStatus === '0') {
            self::finalize($paymentId);  // Success
        } elseif ($txStatus === '1') {
            Payment::updateStatus($paymentId, 'failed');
            Ticket::markCancelled((int) $payment['ticket_id']);
        }
        $payment = Payment::findById($paymentId); // Re-read after update
    }

    echo json_encode([
        'status' => $payment['status'],
        'redirect' => $payment['status'] === 'completed'
            ? '/payment/success/' . $paymentId : null,
    ]);
}
```

### 5. Callback Handler

FlexPay sends a POST callback when the user confirms/declines the push notification. The callback must be publicly accessible.

```php
class CallbackController extends Controller
{
    public function mobileMoney(): void
    {
        // 1. Verify caller authenticity
        if (!$this->callerIsTrusted()) {
            http_response_code(403);
            echo 'forbidden';
            return;
        }

        // 2. Parse JSON body
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo 'invalid payload';
            return;
        }

        $orderNumber = $data['orderNumber'] ?? null;
        $reference = $data['reference'] ?? null;
        $code = $data['code'] ?? null;
        $channel = $data['channel'] ?? null;

        // 3. Find the payment (by orderNumber first, then reference)
        $payment = null;
        if ($orderNumber) {
            $payment = Payment::findByOrderNumber($orderNumber);
        }
        if (!$payment && $reference) {
            $payment = Payment::findByReference($reference);
        }

        // 4. ALWAYS log — even unknown payments — for audit trail
        FlexPayTransaction::log([
            'direction' => 'callback',
            'order_number' => $orderNumber,
            'reference' => $reference,
            'code' => (string) $code,
            'amount' => $data['amount'] ?? null,
            'amount_customer' => $data['amountCustomer'] ?? null,
            'phone' => $data['phone'] ?? null,
            'channel' => $channel,
            'provider_reference' => $data['provider_reference'] ?? null,
            'raw_request' => $raw,
        ]);

        if (!$payment) {
            http_response_code(404);
            echo 'unknown payment';
            return;
        }

        // 5. Replay protection — only process pending payments
        if ($payment['status'] !== 'pending') {
            http_response_code(200);
            echo 'already processed';
            return;
        }

        // 6. Process result
        if ((string) $code === '0') {
            CheckoutController::finalize((int) $payment['id'], $channel);
        } else {
            Payment::updateStatus((int) $payment['id'], 'failed', $channel);
            Ticket::markCancelled((int) $payment['ticket_id']);
        }

        http_response_code(200);
        echo 'OK';
    }
}
```

### 6. Finalize — Atomic Completion

`finalize()` is called from two paths (poll + callback). It MUST be idempotent and race-condition safe.

```php
public static function finalize(int $paymentId, ?string $channel = null): void
{
    $payment = Payment::findById($paymentId);
    if (!$payment || $payment['status'] === 'completed') {
        return;  // Already finalized — idempotent
    }

    // Atomic flip — prevents race between poll and callback
    if (!Payment::updateStatusAtomically($paymentId, 'completed', $channel)) {
        return;  // Another process already finalized this
    }

    $ticket = Ticket::findById((int) $payment['ticket_id']);
    if (!$ticket || $ticket['status'] === 'paid') {
        return;
    }

    // Generate QR token and mark paid atomically
    $qrToken = bin2hex(random_bytes(16));
    if (!Ticket::markPaidAtomically($ticketId, $qrToken)) {
        return;
    }

    // Increment sold count
    Ticket::incrementSold((int) $ticket['ticket_type_id'], (int) $ticket['quantity']);

    // Send confirmation email
    // ... Mailer::sendTicketConfirmation(...)
}
```

## Security Checklist

### Critical (must have in production)

1. **CSRF on all payment forms** — Verify `Session::verifyCsrfToken()` before any money-moving operation
2. **Callback verification** — IP whitelist OR shared secret token (see `callerIsTrusted()` above). Never trust unverified callbacks in production (CWE-345)
3. **Replay protection** — `updateStatusAtomically()` ensures `pending → completed` transition happens exactly once
4. **SSL verification** — `CURLOPT_SSL_VERIFYPEER=true` always (payment partner)

### High Priority
5. **Unique references** — Include timestamp + random bytes to prevent collision
6. **Audit log** — Log every FlexPay interaction (outbound, check, callback) with raw request/response
7. **Self-purchase block** — Organizers cannot buy their own tickets (prevents money laundering via payouts)
8. **Stock check before FlexPay call** — Don't initiate payment if stock is insufficient

### Medium Priority
9. **Polling timeout** — Max 2 minutes (40 attempts × 3s), then show support contact
10. **Idempotent finalize** — Safe to call multiple times from poll + callback
11. **Atomic operations** — Use `WHERE status = 'pending'` guards on all status transitions

## FlexPay API Reference

### Initiate Mobile Money
```
POST {FLEXPAY_API_URL}/paymentService
Authorization: Bearer {token}
Content-Type: application/json

{
    "merchant": "YOUR_CODE",
    "type": "1",
    "phone": "243812345678",
    "reference": "EVT123-U456-1717440000-abc123",
    "amount": "25.00",
    "currency": "USD",
    "callbackUrl": "https://yourdomain.com/callback/flexpay"
}

Response: { "code": "0", "message": "Transaction envoyée...", "orderNumber": "9bsTX7qXdpQe243815877848" }
```

### Check Transaction
```
GET {FLEXPAY_CHECK_URL}/{orderNumber}
Authorization: Bearer {token}

Response: {
    "code": "0",
    "transaction": {
        "orderNumber": "...",
        "status": "0",       // 0=success, 1=failed, 2=pending
        "amount": "25.00",
        "channel": "mpesa"
    }
}
```

### Callback (FlexPay → You)
```
POST {FLEXPAY_CALLBACK_URL}
Content-Type: application/json

{
    "code": "0",
    "reference": "EVT123-U456-...",
    "orderNumber": "9bsTX7qXdpQe243815877848",
    "amount": "25.00",
    "amountCustomer": "25.50",
    "phone": "243812345678",
    "channel": "mpesa",
    "provider_reference": "7KI81020PHS"
}

Your response: HTTP 200 "OK"
```

## Simulated Mode — Local Testing

Set `FLEXPAY_MERCHANT_CODE=SIMULATED` in `.env`. The service returns synthetic success without network calls.

**Full E2E test flow:**
1. Register user, create event, publish
2. Click "Acheter" → select ticket → enter phone → submit
3. Redirected to `/checkout/pending/{id}` → JS polls every 3s
4. Simulated check returns `status=0` → redirect to success page
5. Verify: `payments.status=completed`, `tickets.status=paid`, `qr_code` set, `quantity_sold` incremented, `flexpay_transactions` logged

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Reusing PDO placeholders (`:phone` twice) | Use unique names: `:phone_init`, `:phone_check` (EMULATE_PREPARES=false) |
| Not logging the FlexPay response before checking `ok` | Log FIRST, then handle error — otherwise failed attempts leave no trace |
| Calling FlexPay before creating the payment record | Create pending payment first — if FlexPay succeeds but your DB insert fails, you lose track |
| `CURLOPT_SSL_VERIFYPEER=false` | Always `true` for payment partners. Use staging credentials for dev, not SSL disable |
| Not handling the `code` field as string | FlexPay returns `"0"` (string), not `0` (int). Use `(string) $code === '0'` |
| Redirecting to success URL before finalize completes | The success page reads from DB — finalize must complete before the redirect |
| Missing the `type: "1"` field in initiation | Without `type: 1`, FlexPay doesn't know it's Mobile Money (not card) |
| In Next.js: calling FlexPay from Client Component | FlexPay API calls MUST go through Route Handlers or Server Actions — the token must never reach the browser |
| In Next.js: using `fetch` directly in `"use client"` | Move the fetch to a Route Handler (`/api/checkout/route.ts`) — client fetches your endpoint, not FlexPay directly |

---

## Next.js Integration (App Router)

This section mirrors the PHP implementation in Next.js 14+ App Router patterns. The architecture is identical — the code is idiomatic to Next.js.

### Project Structure

```
src/
├── app/
│   ├── api/
│   │   ├── checkout/
│   │   │   ├── start/route.ts        # POST — initiate payment
│   │   │   └── [id]/
│   │   │       ├── status/route.ts   # GET — poll status
│   │   │       └── finalize/route.ts # POST — complete payment
│   │   └── callback/
│   │       └── flexpay/route.ts      # POST — FlexPay webhook (public)
│   ├── checkout/
│   │   ├── [eventSlug]/
│   │   │   └── page.tsx              # Checkout form (Client Component)
│   │   └── pending/
│   │       └── [paymentId]/
│   │           └── page.tsx          # Waiting page with polling
│   └── payment/
│       ├── success/[id]/page.tsx
│       ├── cancel/page.tsx
│       └── decline/page.tsx
├── lib/
│   ├── flexpay.ts                    # FlexPayService (server-only)
│   ├── db.ts                         # Prisma/Drizzle client
│   └── utils.ts                      # Helpers (normalizePhone, buildReference)
└── prisma/
    └── schema.prisma                 # Payment + FlexPayTransaction models
```

### Environment Variables (`.env.local`)

```bash
FLEXPAY_MERCHANT_CODE=SIMULATED
FLEXPAY_API_URL=https://backend.flexpay.cd/api/rest/v1
FLEXPAY_API_TOKEN=
FLEXPAY_CHECK_URL=https://backend.flexpay.cd/api/rest/v1/check
FLEXPAY_CALLBACK_URL=${NEXT_PUBLIC_APP_URL}/api/callback/flexpay

# Security — configure AT LEAST one in production
FLEXPAY_IP_WHITELIST=
FLEXPAY_CALLBACK_TOKEN=

NEXT_PUBLIC_APP_URL=http://localhost:3000
```

### 1. FlexPay Service (`lib/flexpay.ts`)

This is a **server-only** module — never import in `"use client"` files. Uses native `fetch()` instead of cURL.

```typescript
// lib/flexpay.ts — SERVER ONLY (never "use client")
import "server-only";

const API_URL = (process.env.FLEXPAY_API_URL || "https://backend.flexpay.cd/api/rest/v1").replace(/\/+$/, "");
const CHECK_URL = (process.env.FLEXPAY_CHECK_URL || `${API_URL}/check`).replace(/\/+$/, "");
const MERCHANT_CODE = process.env.FLEXPAY_MERCHANT_CODE || "";
const CALLBACK_URL = process.env.FLEXPAY_CALLBACK_URL || "";

function token(): string {
  const raw = (process.env.FLEXPAY_API_TOKEN || "").trim();
  if (!raw) return "";
  return raw.toLowerCase().startsWith("bearer ") ? raw : `Bearer ${raw}`;
}

export function isSimulated(): boolean {
  return MERCHANT_CODE.toUpperCase() === "SIMULATED";
}

export function normalizePhone(phone: string): string {
  const digits = phone.replace(/\D+/g, "");
  if (digits.startsWith("00")) return digits.slice(2);
  if (digits.startsWith("0")) return "243" + digits.slice(1);
  if (!digits.startsWith("243") && digits.length === 9) return "243" + digits;
  return digits;
}

export function buildReference(eventId: number, userId: number): string {
  const rand = Array.from({ length: 6 }, () =>
    Math.floor(Math.random() * 16).toString(16)
  ).join("");
  return `EVT${eventId}-U${userId}-${Date.now()}-${rand}`;
}

// ── Types ──────────────────────────────────────────────────────────
export interface FlexPayInitResponse {
  ok: boolean;
  httpCode: number;
  error: string | null;
  data: {
    code: string;
    message: string;
    orderNumber: string;
  } | null;
  rawRequest: string;
  rawResponse: string;
}

export interface FlexPayCheckResponse {
  ok: boolean;
  httpCode: number;
  error: string | null;
  data: {
    code: string;
    message: string;
    transaction: {
      orderNumber: string;
      status: string;    // "0"=success, "1"=failed
      amount: string;
      channel: string;
    };
  } | null;
  rawRequest: string;
  rawResponse: string;
}

// ── Core ───────────────────────────────────────────────────────────
export async function initiateMobileMoney(
  phone: string,
  reference: string,
  amount: number,
  currency = "USD"
): Promise<FlexPayInitResponse> {
  if (isSimulated()) {
    const orderNumber = `SIM${Date.now()}${Math.random().toString(36).slice(2, 10)}`;
    return {
      ok: true,
      httpCode: 200,
      error: null,
      data: { code: "0", message: "Simulated", orderNumber },
      rawRequest: JSON.stringify({ simulated: true, reference }),
      rawResponse: JSON.stringify({ code: "0", orderNumber }),
    };
  }

  const body = {
    merchant: MERCHANT_CODE,
    type: "1",
    phone: normalizePhone(phone),
    reference,
    amount: String(amount),
    currency,
    callbackUrl: CALLBACK_URL,
  };

  const rawRequest = JSON.stringify(body);

  try {
    const res = await fetch(`${API_URL}/paymentService`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: token(),
        Accept: "application/json",
      },
      body: rawRequest,
      signal: AbortSignal.timeout(30_000),
    });

    const rawResponse = await res.text();
    const data = JSON.parse(rawResponse);

    return {
      ok: res.ok,
      httpCode: res.status,
      error: null,
      data,
      rawRequest,
      rawResponse,
    };
  } catch (err: any) {
    return {
      ok: false,
      httpCode: 0,
      error: err.message || "Network error",
      data: null,
      rawRequest,
      rawResponse: "",
    };
  }
}

export async function checkTransaction(
  orderNumber: string
): Promise<FlexPayCheckResponse> {
  if (isSimulated()) {
    return {
      ok: true,
      httpCode: 200,
      error: null,
      data: {
        code: "0",
        message: "Simulated",
        transaction: {
          orderNumber,
          status: "0",
          amount: "0",
          channel: "simulated",
        },
      },
      rawRequest: "",
      rawResponse: JSON.stringify({ simulated: true, orderNumber }),
    };
  }

  try {
    const res = await fetch(
      `${CHECK_URL}/${encodeURIComponent(orderNumber)}`,
      {
        headers: {
          Authorization: token(),
          Accept: "application/json",
        },
        signal: AbortSignal.timeout(15_000),
      }
    );

    const rawResponse = await res.text();
    const data = JSON.parse(rawResponse);

    return {
      ok: res.ok,
      httpCode: res.status,
      error: null,
      data,
      rawRequest: "",
      rawResponse,
    };
  } catch (err: any) {
    return {
      ok: false,
      httpCode: 0,
      error: err.message || "Network error",
      data: null,
      rawRequest: "",
      rawResponse: "",
    };
  }
}
```

### 2. Prisma Schema

```prisma
model Payment {
  id                 Int       @id @default(autoincrement())
  userId             Int       @map("user_id")
  ticketId           Int?      @map("ticket_id")
  amount             Decimal   @db.Decimal(10, 2)
  currency           String    @default("USD") @db.VarChar(3)
  paymentMethod      String    @default("mobile_money") @map("payment_method") @db.VarChar(20)
  status             String    @default("pending") @db.VarChar(20)
  reference          String    @db.VarChar(100)
  flexpayOrderNumber String?   @map("flexpay_order_number") @db.VarChar(100)
  phone              String?   @db.VarChar(20)
  channel            String?   @db.VarChar(50)
  createdAt          DateTime  @default(now()) @map("created_at")
  updatedAt          DateTime  @updatedAt @map("updated_at")

  transactions FlexPayTransaction[]

  @@index([reference])
  @@index([flexpayOrderNumber])
  @@index([status])
  @@map("payments")
}

model FlexPayTransaction {
  id                Int      @id @default(autoincrement())
  paymentId         Int?     @map("payment_id")
  direction         String   @default("outbound") @db.VarChar(20)
  orderNumber       String?  @map("order_number") @db.VarChar(100)
  reference         String?  @db.VarChar(100)
  code              String?  @db.VarChar(10)
  status            String?  @db.VarChar(50)
  amount            Decimal? @db.Decimal(10, 2)
  amountCustomer    Decimal? @map("amount_customer") @db.Decimal(10, 2)
  phone             String?  @db.VarChar(20)
  channel           String?  @db.VarChar(50)
  providerReference String?  @map("provider_reference") @db.VarChar(255)
  rawRequest        String?  @map("raw_request") @db.Text
  rawResponse       String?  @map("raw_response") @db.Text
  createdAt         DateTime @default(now()) @map("created_at")

  payment Payment? @relation(fields: [paymentId], references: [id])

  @@index([orderNumber])
  @@index([reference])
  @@index([direction])
  @@map("flexpay_transactions")
}
```

### 3. Route Handler — Initiate Payment (`app/api/checkout/start/route.ts`)

```typescript
// app/api/checkout/start/route.ts
import { NextRequest, NextResponse } from "next/server";
import { getServerSession } from "next-auth";        // or your auth
import { prisma } from "@/lib/db";
import {
  initiateMobileMoney,
  buildReference,
  isSimulated,
} from "@/lib/flexpay";
import { logFlexPayTransaction } from "@/lib/audit"; // wraps prisma.flexPayTransaction.create

export async function POST(req: NextRequest) {
  // 1. Auth guard
  const session = await getServerSession();
  if (!session?.user?.id) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  const userId = Number(session.user.id);

  // 2. Parse + validate
  const { eventSlug, ticketTypeId, quantity, phone } = await req.json();

  // 3. Fetch event & ticket type from DB…
  // const event = await prisma.event.findUnique({ where: { slug: eventSlug } });
  // … validate stock, block self-purchase, etc.

  const totalPrice = 25.0; // from ticketType.price * quantity
  const reference = buildReference(1, userId); // event.id, user.id

  // 4. Create pending payment + ticket FIRST
  const payment = await prisma.payment.create({
    data: {
      userId,
      // ticketId,
      amount: totalPrice,
      currency: "USD",
      paymentMethod: "mobile_money",
      status: "pending",
      reference,
      phone,
    },
  });

  // 5. Initiate FlexPay
  const resp = await initiateMobileMoney(phone, reference, totalPrice, "USD");

  // 6. ALWAYS log
  await logFlexPayTransaction({
    paymentId: payment.id,
    direction: "outbound",
    reference,
    phone,
    amount: totalPrice,
    orderNumber: resp.data?.orderNumber ?? null,
    code: resp.data?.code ?? null,
    rawRequest: resp.rawRequest,
    rawResponse: resp.rawResponse,
  });

  // 7. Handle result
  if (!resp.ok || resp.data?.code !== "0" || !resp.data?.orderNumber) {
    await prisma.payment.update({
      where: { id: payment.id },
      data: { status: "failed" },
    });
    return NextResponse.json(
      { error: resp.data?.message || resp.error || "FlexPay initiation failed" },
      { status: 502 }
    );
  }

  // 8. Store order number
  await prisma.payment.update({
    where: { id: payment.id },
    data: { flexpayOrderNumber: resp.data.orderNumber },
  });

  // 9. Return redirect URL for the pending page
  return NextResponse.json({
    redirect: `/checkout/pending/${payment.id}`,
    paymentId: payment.id,
  });
}
```

### 4. Route Handler — Poll Status (`app/api/checkout/[id]/status/route.ts`)

```typescript
// app/api/checkout/[id]/status/route.ts
import { NextRequest, NextResponse } from "next/server";
import { getServerSession } from "next-auth";
import { prisma } from "@/lib/db";
import { checkTransaction } from "@/lib/flexpay";
import { logFlexPayTransaction } from "@/lib/audit";
import { finalizePayment } from "@/lib/finalize";

export async function GET(
  req: NextRequest,
  { params }: { params: { id: string } }
) {
  const session = await getServerSession();
  if (!session?.user?.id) {
    return NextResponse.json({ status: "not_found" });
  }

  const paymentId = Number(params.id);
  let payment = await prisma.payment.findUnique({ where: { id: paymentId } });

  if (!payment || payment.userId !== Number(session.user.id)) {
    return NextResponse.json({ status: "not_found" });
  }

  // If pending with order number, check FlexPay
  if (payment.status === "pending" && payment.flexpayOrderNumber) {
    const resp = await checkTransaction(payment.flexpayOrderNumber);

    await logFlexPayTransaction({
      paymentId,
      direction: "check",
      orderNumber: payment.flexpayOrderNumber,
      reference: payment.reference,
      code: resp.data?.code ?? null,
      status: resp.data?.transaction?.status ?? null,
      channel: resp.data?.transaction?.channel ?? null,
      rawResponse: resp.rawResponse,
    });

    const txStatus = resp.data?.transaction?.status ?? null;
    const channel = resp.data?.transaction?.channel ?? null;

    if (txStatus === "0") {
      await finalizePayment(paymentId, channel);
    } else if (txStatus === "1") {
      await prisma.payment.update({
        where: { id: paymentId },
        data: { status: "failed", channel },
      });
    }

    // Re-read
    payment = await prisma.payment.findUnique({ where: { id: paymentId } });
  }

  return NextResponse.json({
    status: payment?.status,
    redirect:
      payment?.status === "completed"
        ? `/payment/success/${paymentId}`
        : null,
  });
}
```

### 5. Client Component — Checkout Form (`app/checkout/[eventSlug]/page.tsx`)

```tsx
"use client";

import { useState, FormEvent } from "react";
import { useRouter } from "next/navigation";

export default function CheckoutPage({ params }: { params: { eventSlug: string } }) {
  const router = useRouter();
  const [phone, setPhone] = useState("");
  const [quantity, setQuantity] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");

    try {
      const res = await fetch("/api/checkout/start", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          eventSlug: params.eventSlug,
          ticketTypeId: 1, // from props/state
          quantity,
          phone,
        }),
      });

      const data = await res.json();

      if (!res.ok) {
        setError(data.error || "Payment initiation failed");
        return;
      }

      router.push(data.redirect); // → /checkout/pending/{id}
    } catch {
      setError("Network error. Please try again.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="max-w-md mx-auto space-y-6">
      <h1 className="text-3xl font-headline uppercase italic">Acheter un billet</h1>

      {error && (
        <div className="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400">
          {error}
        </div>
      )}

      <div>
        <label className="block text-sm font-mono uppercase tracking-wider mb-2">
          Quantité
        </label>
        <select
          value={quantity}
          onChange={(e) => setQuantity(Number(e.target.value))}
          className="w-full p-3 rounded-xl bg-surface-container border border-white/10"
        >
          {[1, 2, 3, 4, 5].map((n) => (
            <option key={n} value={n}>{n}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="block text-sm font-mono uppercase tracking-wider mb-2">
          Numéro Mobile Money
        </label>
        <input
          type="tel"
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          placeholder="081 234 5678"
          className="w-full p-3 rounded-xl bg-surface-container border border-white/10"
          required
        />
      </div>

      <button
        type="submit"
        disabled={loading}
        className="w-full py-4 rounded-xl bg-lime-primary text-black font-headline
                   uppercase italic text-lg disabled:opacity-50 hover:glow-primary transition"
      >
        {loading ? "Initialisation..." : "Payer avec Mobile Money"}
      </button>
    </form>
  );
}
```

### 6. Client Component — Pending Page with Polling (`app/checkout/pending/[paymentId]/page.tsx`)

```tsx
"use client";

import { useEffect, useState, useRef } from "react";
import { useRouter } from "next/navigation";

export default function PendingPage({
  params,
}: {
  params: { paymentId: string };
}) {
  const router = useRouter();
  const [message, setMessage] = useState("");
  const attempts = useRef(0);
  const MAX_ATTEMPTS = 40; // ~2 min

  useEffect(() => {
    const check = async () => {
      attempts.current++;
      try {
        const res = await fetch(
          `/api/checkout/${params.paymentId}/status`,
          { cache: "no-store" }
        );
        const data = await res.json();

        if (data.status === "completed" && data.redirect) {
          router.push(data.redirect);
          return;
        }
        if (data.status === "failed" || data.status === "cancelled") {
          setMessage("Paiement échoué. Veuillez réessayer.");
          return;
        }
        if (attempts.current >= MAX_ATTEMPTS) {
          setMessage("Délai dépassé. Contactez le support si le paiement a été débité.");
          return;
        }
        setTimeout(check, 3000);
      } catch {
        if (attempts.current < MAX_ATTEMPTS) setTimeout(check, 5000);
      }
    };

    const timer = setTimeout(check, 2000);
    return () => clearTimeout(timer);
  }, [params.paymentId, router]);

  return (
    <div className="max-w-lg mx-auto text-center py-20">
      <div className="inline-flex items-center justify-center w-20 h-20 rounded-full mb-6 bg-lime-primary/10">
        <svg className="animate-spin" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#b8d300" strokeWidth="2">
          <path d="M21 12a9 9 0 1 1-6.219-8.56" strokeLinecap="round" />
        </svg>
      </div>
      <h2 className="text-xl font-headline uppercase italic mb-3">
        Confirmation en attente
      </h2>
      <p className="text-on-surface-variant mb-4">
        Veuillez confirmer le push sur votre téléphone pour finaliser le paiement.
      </p>
      {message && (
        <p className="text-sm text-red-400 mt-4">{message}</p>
      )}
    </div>
  );
}
```

### 7. Callback Route Handler (`app/api/callback/flexpay/route.ts`)

```typescript
// app/api/callback/flexpay/route.ts — PUBLIC (no auth)
import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { logFlexPayTransaction } from "@/lib/audit";
import { finalizePayment } from "@/lib/finalize";

// Verify the callback is genuinely from FlexPay.
function callerIsTrusted(req: NextRequest): boolean {
  // Option 1: IP whitelist
  const ipList = (process.env.FLEXPAY_IP_WHITELIST || "")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  if (ipList.length > 0) {
    const remote =
      req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
      req.headers.get("x-real-ip") ||
      "127.0.0.1";
    if (!ipList.includes(remote)) {
      console.error(`[FlexPay callback] IP rejetée: ${remote}`);
      return false;
    }
  }

  // Option 2: Shared secret token
  const secret = (process.env.FLEXPAY_CALLBACK_TOKEN || "").trim();
  if (secret) {
    const url = new URL(req.url);
    const provided =
      url.searchParams.get("token") ||
      req.headers.get("x-callback-token") ||
      "";
    // Use timing-safe comparison
    const crypto = require("crypto");
    if (
      provided.length !== secret.length ||
      !crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(secret))
    ) {
      console.error("[FlexPay callback] Jeton partagé invalide");
      return false;
    }
  }

  return true;
}

export async function POST(req: NextRequest) {
  if (!callerIsTrusted(req)) {
    return new Response("forbidden", { status: 403 });
  }

  const raw = await req.text();
  let data: any;
  try {
    data = JSON.parse(raw);
  } catch {
    return new Response("invalid payload", { status: 400 });
  }

  const orderNumber = data.orderNumber ?? null;
  const reference = data.reference ?? null;
  const code = String(data.code ?? "");
  const channel = data.channel ?? null;

  // Find payment
  let payment = null;
  if (orderNumber) {
    payment = await prisma.payment.findFirst({
      where: { flexpayOrderNumber: orderNumber },
    });
  }
  if (!payment && reference) {
    payment = await prisma.payment.findFirst({
      where: { reference },
    });
  }

  // ALWAYS log
  await logFlexPayTransaction({
    direction: "callback",
    orderNumber,
    reference,
    code,
    amount: data.amount ?? null,
    amountCustomer: data.amountCustomer ?? null,
    phone: data.phone ?? null,
    channel,
    providerReference: data.provider_reference ?? null,
    rawRequest: raw,
  });

  if (!payment) {
    return new Response("unknown payment", { status: 404 });
  }

  // Replay protection
  if (payment.status !== "pending") {
    return new Response("already processed", { status: 200 });
  }

  if (code === "0") {
    await finalizePayment(payment.id, channel);
  } else {
    await prisma.payment.update({
      where: { id: payment.id },
      data: { status: "failed", channel },
    });
    // Mark ticket as cancelled too
    if (payment.ticketId) {
      await prisma.ticket.update({
        where: { id: payment.ticketId },
        data: { status: "cancelled" },
      });
    }
  }

  return new Response("OK", { status: 200 });
}
```

### 8. Finalize Payment (`lib/finalize.ts`)

```typescript
// lib/finalize.ts — SERVER ONLY
import "server-only";
import { prisma } from "@/lib/db";
import crypto from "crypto";

export async function finalizePayment(
  paymentId: number,
  channel?: string | null
): Promise<boolean> {
  // Atomic flip — prevents race between poll and callback
  const updated = await prisma.payment.updateMany({
    where: { id: paymentId, status: "pending" },
    data: {
      status: "completed",
      channel: channel ?? undefined,
    },
  });

  if (updated.count === 0) {
    return false; // Already finalized by another process
  }

  const payment = await prisma.payment.findUnique({
    where: { id: paymentId },
    include: { ticket: true },
  });

  if (!payment?.ticketId) return true;

  // Atomic ticket flip + QR token
  const qrToken = crypto.randomBytes(16).toString("hex");
  const ticketUpdated = await prisma.ticket.updateMany({
    where: { id: payment.ticketId, status: "pending" },
    data: {
      status: "paid",
      qrCode: qrToken,
    },
  });

  if (ticketUpdated.count === 0) return true; // Already set

  // Increment sold count
  if (payment.ticket?.ticketTypeId) {
    await prisma.ticketType.update({
      where: { id: payment.ticket.ticketTypeId },
      data: { quantitySold: { increment: payment.ticket.quantity } },
    });
  }

  // Send confirmation email (resend, sendgrid, etc.)
  // await sendTicketConfirmation(payment.userId, payment.ticketId, qrToken);

  return true;
}
```

### 9. Server Action Alternative (for forms)

If you prefer Server Actions over Route Handlers for the checkout form:

```typescript
// app/checkout/[eventSlug]/actions.ts
"use server";

import { getServerSession } from "next-auth";
import { prisma } from "@/lib/db";
import { initiateMobileMoney, buildReference } from "@/lib/flexpay";
import { logFlexPayTransaction } from "@/lib/audit";
import { redirect } from "next/navigation";

export async function startCheckout(
  eventSlug: string,
  formData: FormData
): Promise<{ error?: string; paymentId?: number }> {
  const session = await getServerSession();
  if (!session?.user?.id) return { error: "Vous devez être connecté." };

  const phone = (formData.get("phone") as string)?.trim() || "";
  const quantity = Number(formData.get("quantity")) || 1;
  const ticketTypeId = Number(formData.get("ticket_type_id")) || 0;
  const userId = Number(session.user.id);

  if (!phone.match(/^[0-9+\s]{9,15}$/)) {
    return { error: "Numéro de téléphone invalide." };
  }

  // … validate event, stock, self-purchase, etc …
  const totalPrice = 25.0;
  const reference = buildReference(1, userId);

  // Create pending payment
  const payment = await prisma.payment.create({
    data: {
      userId,
      amount: totalPrice,
      currency: "USD",
      paymentMethod: "mobile_money",
      status: "pending",
      reference,
      phone,
    },
  });

  // Initiate FlexPay
  const resp = await initiateMobileMoney(phone, reference, totalPrice, "USD");

  // Always log
  await logFlexPayTransaction({
    paymentId: payment.id,
    direction: "outbound",
    reference,
    phone,
    amount: totalPrice,
    orderNumber: resp.data?.orderNumber ?? null,
    code: resp.data?.code ?? null,
    rawRequest: resp.rawRequest,
    rawResponse: resp.rawResponse,
  });

  if (!resp.ok || resp.data?.code !== "0" || !resp.data?.orderNumber) {
    await prisma.payment.update({
      where: { id: payment.id },
      data: { status: "failed" },
    });
    return { error: resp.data?.message || "Échec de l'initialisation du paiement." };
  }

  await prisma.payment.update({
    where: { id: payment.id },
    data: { flexpayOrderNumber: resp.data.orderNumber },
  });

  // Server Action can redirect directly
  redirect(`/checkout/pending/${payment.id}`);
}
```

### Next.js Security Considerations

| Concern | Solution |
|---------|----------|
| FlexPay token exposed to browser | `lib/flexpay.ts` uses `import "server-only"` — cannot be imported in Client Components |
| CSRF on Server Actions | Next.js 14+ auto-includes CSRF tokens on Server Actions (POST-only) |
| CSRF on Route Handlers | Add your own token check or use `next-auth` CSRF |
| Callback endpoint public | Verify with IP whitelist (`x-forwarded-for` + `x-real-ip`) AND/OR shared secret token |
| Race condition (poll + callback) | `updateMany({ where: { status: "pending" } })` ensures exactly one wins |
| Request timeout | `AbortSignal.timeout(30_000)` on FlexPay calls, 15s on check |
| Rate limiting | Add `@upstash/ratelimit` or similar on `/api/checkout/start` |
| Environment variables | `FLEXPAY_*` vars are NOT prefixed with `NEXT_PUBLIC_` — they stay server-side |

### Next.js File Checklist

```
□ lib/flexpay.ts              — Service (initiate, check, helpers)
□ lib/finalize.ts             — Atomic finalize + QR + email
□ lib/audit.ts                — logFlexPayTransaction() wrapper
□ lib/db.ts                   — Prisma/Drizzle client
□ app/api/checkout/start/route.ts      — POST initiate
□ app/api/checkout/[id]/status/route.ts — GET poll
□ app/api/callback/flexpay/route.ts    — POST webhook (public)
□ app/checkout/[eventSlug]/page.tsx    — Checkout form (Client)
□ app/checkout/pending/[id]/page.tsx   — Polling page (Client)
□ app/payment/success/[id]/page.tsx    — Success page
□ app/payment/cancel/page.tsx          — Cancel page
□ app/payment/decline/page.tsx         — Decline page
□ prisma/schema.prisma                 — Payment + FlexPayTransaction models
□ .env.local                           — FlexPay env vars
```

---

## Routes to Define (PHP)

```
GET  /checkout/{slug}                    → show checkout form
POST /checkout/{slug}/start              → initiate payment
GET  /checkout/pending/{id}              → waiting page with polling JS
GET  /checkout/pending/{id}/status       → JSON poll endpoint
GET  /payment/success/{id}               → success page
GET  /payment/cancel                     → cancelled page
GET  /payment/decline                    → declined page
POST /callback/flexpay                   → Mobile Money callback (public, no auth)
```
