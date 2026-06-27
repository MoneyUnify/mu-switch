---
title: API Tokens
excerpt: Generate, view, and rotate the API token that authenticates your server-to-server calls to the switch.
date: 2026-06-27
category: Configuration
---

Every API request to the switch is authenticated with your **API token**. The
token identifies your account, which in turn determines the set of
[providers](/docs/providers) the switch will route through.

## Finding your token

Your API token is generated automatically the first time you open the
**Dashboard**. From there you can:

- **Show / hide** the token.
- **Copy** it to your clipboard.
- **Regenerate** it.

## Using the token

Send the token as a Bearer token in the `Authorization` header of every API
request:

```http
Authorization: Bearer YOUR_API_TOKEN
```

See [Authentication](/docs/api/authentication) for details and error
responses.

## Rotating the token

Click **Regenerate Token** on the dashboard to issue a new token. This
**immediately invalidates the previous token** — any integration still using the
old value will start receiving `401 Unauthorized` responses, so update your
services with the new token right away.

> Treat the token like a password. Store it in your server's secret manager or
> environment variables, never in client-side code or version control.
