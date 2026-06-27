# MoneyUnify Switch API Documentation

![MoneyUnify Logo](public/moneyunify-logo-horizontal.png)

This document describes the external API endpoints available for consuming the MoneyUnify Switch payment processing capabilities.

## Authentication

All API requests must be authenticated using the API Token generated from your MoneyUnify Switch dashboard. 

Include the token in your request headers as a Bearer token:

```http
Authorization: Bearer <your-api-token>
Accept: application/json
Content-Type: application/json
```

---

## Endpoints

### 1. Initiate Payment Request

Initiate a mobile money collection payment request. The switch will automatically filter, validate, and sequentially route the request through your active configured payment providers until one successfully processes it.

* **URL**: `/api/v1/payment/request`
* **Method**: `POST`
* **Headers**:
  * `Authorization`: `Bearer <api-token>`
  * `Accept`: `application/json`
  * `Content-Type`: `application/json`

#### Request Payload (JSON)

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `amount` | Float/Numeric | Yes | The amount to charge (minimum `0.01`). |
| `account_number` | String | Yes | The recipient's mobile money phone number (e.g. `761234567` or `891234567`). |
| `country` | String | Yes | The 2-letter ISO country code. Supported: `ZM` (Zambia), `MW` (Malawi). |
| `callback_url` | String (URL) | No | A webhook URL. When you later verify the transaction and it settles (succeeds or fails), the switch POSTs the final result here exactly once. See [Callbacks](#callbacks). |

##### Example Request

```json
{
  "amount": 150.50,
  "account_number": "761234567",
  "country": "ZM",
  "callback_url": "https://your-app.com/webhooks/payments"
}
```

---

#### Response Payloads

##### Success (200 OK)
Returned when one of the active payment providers successfully initiates the payment.

```json
{
  "status": "success",
  "message": "Payment request initiated successfully",
  "data": {
    "transaction_id": "8482bf54-a63e-4fb1-b66a-ff55a3036980",
    "reference": "LENCO-REF-999"
  }
}
```

##### Validation Error (422 Unprocessable Content)
Returned if request fields fail validation.

```json
{
  "message": "The amount field is required. (and 2 more errors)",
  "errors": {
    "amount": ["The amount field is required."],
    "account_number": ["The account number field is required."],
    "country": ["The country field is required."]
  }
}
```

##### Route Configuration Error (400 Bad Request)
Returned if there are no active, configured providers supporting the requested country.

```json
{
  "status": "error",
  "message": "No active providers support the requested country"
}
```

##### Unauthorized (401 Unauthorized)
Returned if the `Authorization` header is missing or the token is invalid.

```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

##### Provider Execution Failure (400/500 Error codes)
If all active, matching providers fail to process the request sequentially, the switch returns the exact error from the last attempted provider.

```json
{
  "status": "error",
  "message": "Insufficient funds or generic provider error"
}
```

---

### 2. Verify Payment (Transaction Status)

Re-check a transaction's status. The switch queries the **same provider that
processed it**, persists the latest status, and — if the transaction has settled
and a `callback_url` was supplied — notifies that URL.

* **URL**: `/api/v1/payment/verify`
* **Method**: `POST`
* **Headers**: same as above (`Authorization`, `Accept`, `Content-Type`)

#### Request Payload (JSON)

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `transaction_id` | String | Yes | The `transaction_id` returned by the request-to-pay call. |

##### Example Request

```json
{
  "transaction_id": "8482bf54-a63e-4fb1-b66a-ff55a3036980"
}
```

#### Response Payloads

##### Verified (200 OK)

Verification always returns HTTP `200`. The top-level **`status` mirrors the
transaction's real outcome** (`success`, `failed`, or `pending`) — not just
"the API call worked" — so you can branch on it directly. `provider_status` is
the raw provider value (e.g. Lenco's `successful`, `pay-offline`).

Successful payment:

```json
{
  "status": "success",
  "message": "Transaction completed successfully",
  "data": {
    "transaction_id": "8482bf54-a63e-4fb1-b66a-ff55a3036980",
    "reference": "LENCO-REF-999",
    "status": "success",
    "provider_status": "successful",
    "amount": 150.50,
    "currency": "ZMW"
  }
}
```

Failed payment (top-level `status` is `failed`, not `success`):

```json
{
  "status": "failed",
  "message": "Transaction failed",
  "data": {
    "transaction_id": "8482bf54-a63e-4fb1-b66a-ff55a3036980",
    "reference": "LENCO-REF-999",
    "status": "failed",
    "provider_status": "failed",
    "amount": 150.50,
    "currency": "ZMW"
  }
}
```

> A `status: "error"` response (with a `4xx`/`5xx` code) means **verification
> itself failed** (provider unreachable, transaction not found) — never that
> the payment failed. A failed payment is `status: "failed"` with HTTP `200`.

##### Not Found (404)

```json
{
  "status": "error",
  "message": "Transaction not found"
}
```

---

## Callbacks

If a transaction was created with a `callback_url`, the switch delivers the final
result to that URL **once** — the first time verification finds it settled
(`success` or `failed`). Pending checks never fire a callback.

The callback is an HTTP `POST` with this JSON body:

```json
{
  "transaction_id": "8482bf54-a63e-4fb1-b66a-ff55a3036980",
  "reference": "LENCO-REF-999",
  "status": "success",
  "amount": 150.50,
  "currency": "ZMW",
  "provider": "Lenco"
}
```

* Delivery is **queued and retried** (up to 3 attempts with backoff) — make your
  endpoint **idempotent** by keying off `transaction_id`.
* Each transaction is notified **at most once**.
* Respond with a `2xx` status to acknowledge receipt.

---

## Integration Code Examples

### cURL

```bash
curl -X POST http://localhost:8000/api/v1/payment/request \
  -H "Authorization: Bearer <your-api-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 150.50,
    "account_number": "761234567",
    "country": "ZM"
  }'
```

### Node.js (fetch)

```javascript
const initiatePayment = async () => {
  const response = await fetch('http://localhost:8000/api/v1/payment/request', {
    method: 'POST',
    headers: {
      'Authorization': 'Bearer <your-api-token>',
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      amount: 150.50,
      account_number: '761234567',
      country: 'ZM'
    })
  });

  const data = await response.json();
  console.log(data);
};
```
