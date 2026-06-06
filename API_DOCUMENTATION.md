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

##### Example Request

```json
{
  "amount": 150.50,
  "account_number": "761234567",
  "country": "ZM"
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
