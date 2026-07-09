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
country, its ISO code and settlement currency, the principal mobile-money
networks (MNOs) reachable there, and the drivers that can route to it. The
networks are the operators reachable through the listed providers — aggregators
(pawaPay, Ting) auto-select the payer's operator, while operator-specific drivers
route to a single network.

| Country | Code | Currency | Networks (MNOs) | Providers |
| --- | --- | --- | --- | --- |
| Benin | BJ | XOF | MTN MoMo, Moov Money | MTN MoMo, pawaPay, Ting |
| Botswana | BW | BWP | Orange Money, Mascom MyZaka, BTC Smega | Kazang |
| Burkina Faso | BF | XOF | Orange Money, Moov Money | Flutterwave, pawaPay |
| Cameroon | CM | XAF | MTN MoMo, Orange Money | Flutterwave, MTN MoMo, pawaPay, Ting |
| Chad | TD | XAF | Airtel Money, Moov Money | Airtel Money, Ting |
| Congo-Brazzaville | CG | XAF | MTN MoMo, Airtel Money | Airtel Money, MTN MoMo, pawaPay, Ting |
| Côte d'Ivoire | CI | XOF | MTN MoMo, Orange Money, Moov Money, Wave | Flutterwave, MTN MoMo, pawaPay, Ting |
| DR Congo | CD | CDF | Vodacom M-Pesa, Airtel Money, Orange Money | Airtel Money, M-Pesa (Vodacom), pawaPay, Ting |
| Eswatini | SZ | SZL | MTN MoMo | MTN MoMo, Ting |
| Ethiopia | ET | ETB | Safaricom M-Pesa, Telebirr | pawaPay |
| Gabon | GA | XAF | Airtel Money, Moov Money | Airtel Money, pawaPay, Ting |
| Ghana | GH | GHS | MTN MoMo, AirtelTigo Money, Telecel Cash | DPO Pay, Flutterwave, M-Pesa (Vodacom), MTN MoMo, pawaPay, Ting |
| Guinea | GN | GNF | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Guinea-Bissau | GW | XOF | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Kenya | KE | KES | Safaricom M-Pesa, Airtel Money, T-Kash | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Kenya), pawaPay, Ting |
| Lesotho | LS | LSL | Vodacom M-Pesa, EcoCash | M-Pesa (Vodacom), pawaPay, Ting |
| Liberia | LR | LRD | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Madagascar | MG | MGA | Airtel Money, Orange Money, MVola | Airtel Money |
| Malawi | MW | MWK | Airtel Money, TNM Mpamba | Airtel Money, DPO Pay, Lenco, MobiPay, pawaPay, Ting |
| Mozambique | MZ | MZN | Vodacom M-Pesa, e-Mola, mKesh | DPO Pay, M-Pesa (Vodacom), pawaPay, Ting |
| Namibia | NA | NAD | MTC Money | Kazang |
| Niger | NE | XOF | Airtel Money, Moov Money, Orange Money | Airtel Money, Ting |
| Nigeria | NG | NGN | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, MTN MoMo, pawaPay, Ting |
| Rwanda | RW | RWF | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Senegal | SN | XOF | Orange Money, Free Money, Wave | Flutterwave, pawaPay |
| Seychelles | SC | SCR | Airtel Money | Airtel Money, Ting |
| Sierra Leone | SL | SLE | Orange Money, Africell Money | pawaPay |
| South Africa | ZA | ZAR | MTN MoMo, Vodacom | Kazang, MTN MoMo, Ting |
| South Sudan | SS | SSP | MTN MoMo | MTN MoMo, Ting |
| Tanzania | TZ | TZS | Vodacom M-Pesa, Airtel Money, Tigo Pesa, Halopesa | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Vodacom), pawaPay, Ting |
| Uganda | UG | UGX | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Zambia | ZM | ZMW | MTN MoMo, Airtel Money, Zamtel Kwacha | Airtel Money, cGrate, DPO Pay, Flutterwave, Kazang, Lenco, Lipila, MTN MoMo, pawaPay, Ting |

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
