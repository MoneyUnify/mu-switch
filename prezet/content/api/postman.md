---
title: Postman Collection
excerpt: Download every API endpoint as a ready-to-use Postman collection, pointed at your deployment.
date: 2026-10-30
category: API Reference
---

Every endpoint in this reference is available as a Postman collection, so you
can try the whole API without writing code.

**[Download the Postman collection](/postman-collection.json)**

The collection is built from the [OpenAPI spec](/openapi.yaml) on every
download, so it always matches the API, and it points at the deployment you
downloaded it from.

## What's in it

| Folder | Requests |
| --- | --- |
| Payments | Quote, collect (mobile-money push or PayPal checkout) and check a payment |
| Refunds | Refund a PayPal payment and check the refund |
| Payouts | Pay out to a mobile-money wallet and check it |
| Transfers | Quote, start and check a PayPal-to-mobile-money transfer |
| Merchants | Onboard, update, quote, charge and list settlements |
| Pay links | Create, list, update and delete a merchant's pay links |
| FX | Live rates, and locked FX quotes |

Each request has an example body, and the requests that move money send a
fresh `Idempotency-Key` each time (`{{$guid}}`), so a retry never pays twice.

## Set it up

1. In Postman, choose **Import** and drop in the downloaded file.
2. Open the collection's **Variables** tab:

   | Variable | Value |
   | --- | --- |
   | `baseUrl` | Already set to this deployment's API root (`…/api/v1`). |
   | `apiToken` | Your API token, from the dashboard's **API Token** page. |
   | `merchant` | A merchant's id or slug, for the merchant requests. |
   | `link` | A pay link's id or code, for the pay link requests. |
   | `id` | An FX quote's id, to look one up. |

3. Save, and send any request. The token is sent as a Bearer token on every
   request.

## A first run

1. **FX → Exchange rates** to see today's rates.
2. **Transfers → Quote a transfer**, then **Start a transfer**. Open the
   returned `checkout_url` and pay with a PayPal sandbox buyer.
3. **Transfers → Check a transfer** with the returned `reference` to watch it
   reach `delivered`.
4. **Payments → Collect a payment** with a mobile number to send a payment
   prompt to the payer's phone.

On a sandbox deployment, the recipient's number decides what a sandbox payout
does, so you can show success, failure and pending on demand (see
[Payouts](/docs/api/payouts)).

## Generate the file yourself

To write the collection to a file instead, for example in CI or for an
offline copy:

```bash
php artisan docs:postman
```

It writes `public/postman/moneyunify-api.postman_collection.json`, pointed at
`APP_URL`. Use `--url` to point it at another deployment and `--path` to write
it elsewhere.
