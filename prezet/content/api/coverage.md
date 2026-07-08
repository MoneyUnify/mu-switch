---
title: Coverage
excerpt: The countries, currencies and mobile networks the switch can reach, and which built-in provider driver routes to each.
date: 2026-07-08
category: API Reference
---

The switch reaches a country only when you have configured (and activated) a
provider that supports it. The tables below list every market the **built-in
drivers** can serve, the currency used, and the mobile networks reachable there
— so you can pick the providers that cover your markets.

> Coverage is the **union of the providers you configure**. Configuring one
> provider does not enable every country below — it enables the countries that
> provider supports. Tick the markets you serve when you
> [add a provider](/docs/providers).

## Countries & currencies

The built-in drivers collectively cover **32 countries**. Each row shows the
country, its settlement currency, and the drivers that can route there.

| Country | Code | Currency | Provider drivers |
| --- | --- | --- | --- |
| Benin | BJ | XOF | MTN MoMo, pawaPay, Ting |
| Botswana | BW | BWP | Kazang |
| Burkina Faso | BF | XOF | Flutterwave, pawaPay |
| Cameroon | CM | XAF | Flutterwave, MTN MoMo, pawaPay, Ting |
| Chad | TD | XAF | Airtel Money, Ting |
| Congo-Brazzaville | CG | XAF | Airtel Money, MTN MoMo, pawaPay, Ting |
| Côte d'Ivoire | CI | XOF | Flutterwave, MTN MoMo, pawaPay, Ting |
| DR Congo | CD | CDF | Airtel Money, M-Pesa (Vodacom), pawaPay, Ting |
| Eswatini | SZ | SZL | MTN MoMo, Ting |
| Ethiopia | ET | ETB | pawaPay |
| Gabon | GA | XAF | Airtel Money, pawaPay, Ting |
| Ghana | GH | GHS | DPO Pay, Flutterwave, M-Pesa (Vodacom), MTN MoMo, pawaPay, Ting |
| Guinea | GN | GNF | MTN MoMo, Ting |
| Guinea-Bissau | GW | XOF | MTN MoMo, Ting |
| Kenya | KE | KES | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Kenya), pawaPay, Ting |
| Lesotho | LS | LSL | M-Pesa (Vodacom), pawaPay, Ting |
| Liberia | LR | LRD | MTN MoMo, Ting |
| Madagascar | MG | MGA | Airtel Money |
| Malawi | MW | MWK | Airtel Money, DPO Pay, Lenco, MobiPay, pawaPay, Ting |
| Mozambique | MZ | MZN | DPO Pay, M-Pesa (Vodacom), pawaPay, Ting |
| Namibia | NA | NAD | Kazang |
| Niger | NE | XOF | Airtel Money, Ting |
| Nigeria | NG | NGN | Airtel Money, DPO Pay, MTN MoMo, pawaPay, Ting |
| Rwanda | RW | RWF | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Senegal | SN | XOF | Flutterwave, pawaPay |
| Seychelles | SC | SCR | Airtel Money, Ting |
| Sierra Leone | SL | SLE | pawaPay |
| South Africa | ZA | ZAR | Kazang, MTN MoMo, Ting |
| South Sudan | SS | SSP | MTN MoMo, Ting |
| Tanzania | TZ | TZS | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Vodacom), pawaPay, Ting |
| Uganda | UG | UGX | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Zambia | ZM | ZMW | Airtel Money, cGrate, DPO Pay, Flutterwave, Kazang, Lenco, Lipila, MTN MoMo, pawaPay, Ting |

## Providers & mobile networks

Each built-in driver, the number of markets it serves, and the mobile networks
it routes payments to. **Aggregators** (pawaPay, Ting) reach the major
mobile-money operators (MMOs) in each of their markets and pick the right one
automatically; **operator-specific** drivers route to a single network.

| Provider driver | Markets | Mobile networks reached |
| --- | --- | --- |
| **MTN MoMo** | 15 | MTN Mobile Money |
| **Airtel Money** | 14 | Airtel Money |
| **M-Pesa (Kenya)** | 1 | Safaricom M-Pesa (STK Push) |
| **M-Pesa (Vodafone/Vodacom)** | 5 | Vodacom / Vodafone M-Pesa |
| **Lenco** | 2 | ZM: MTN, Airtel, Zamtel · MW: Airtel, TNM |
| **Lipila** | 1 | Zambia: MTN, Airtel, Zamtel |
| **cGrate** (Konse Konse 543) | 1 | Zambia: MTN, Airtel, Zamtel |
| **MobiPay** (Malipo) | 1 | Malawi: Airtel Money, TNM Mpamba |
| **Kazang** (ContentReady) | 4 | ZM: MTN, Airtel, Zamtel · ZA/NA/BW: operator configured per market |
| **Flutterwave** | 10 | M-Pesa (KE); MTN, Vodafone, AirtelTigo (GH); MTN, Airtel (UG, RW); MTN, Airtel, Zamtel (ZM); Vodacom, Airtel, Tigo (TZ); MTN, Orange (CM, CI, SN, BF) |
| **DPO Pay** | 9 | Operator configured per market (MNO code) — e.g. Safaricom, Vodacom, Tigo, MTN, Airtel |
| **pawaPay** | 20 | All major MMOs per market — auto-detected (e.g. MTN, Airtel, Vodafone, Orange, Tigo, Moov) |
| **Ting by Cellulant** | 25 | All major MMOs per market — operator code configured per market |

> **Operator selection.** Where a market has several networks, operator-specific
> drivers choose the network from the payer's phone prefix (with an optional
> caller override), while aggregators either auto-detect it (pawaPay) or use a
> per-market operator code you configure (Ting, DPO, Kazang's ZA/NA/BW). See each
> driver's section in [Payment Providers](/docs/providers) for details.
