# MoneyUnify Switch

![MoneyUnify Logo](public/moneyunify-logo-horizontal.png)

## Introduction and Objective

MoneyUnify Switch is a source-available, unified payment switch API platform designed to orchestrate payment provider integrations, manage customer accounts, and process transaction flows from a centralized dashboard. The platform provides a standardized interface for multiple payment gateways, allowing applications to initiate collections and automatically failover/retry across configured providers sequentially without modifying core business logic.

With dynamic provider routing, real-time transaction tracking, and automatic provider credential configuration from the admin panel, MoneyUnify Switch ensures high transaction success rates and eliminates single-gateway dependency.

### Documentation

MoneyUnify Switch ships with a built-in documentation site served at the **`/docs`** route (installation, provider configuration, and the full API reference). It is generated from the Markdown files in [`prezet/content`](prezet/content). See [Documentation Site (`/docs`)](#documentation-site-docs) below for how to bring it up.

A standalone [API Documentation](API_DOCUMENTATION.md) file is also available for quick reference.

## Requirements

- **PHP 8.3+** (8.5 recommended) with the `dom` and `gd` extensions enabled
- **Composer** 2.x
- **Node.js 20+** with npm (pnpm or yarn also work)
- A database — **SQLite is the zero-config default**; MySQL or PostgreSQL also work (any engine with native JSON support), configured via `.env`
- **Git**

## Local Setup

1. Clone the repository:

```bash
git clone https://github.com/MoneyUnify/mu-switch.git
cd mu-switch
```

2. Run the automated setup command:

```bash
composer setup
```

   This single command bootstraps everything:
   - installs Composer and npm dependencies,
   - creates your `.env` file (from `.env.example`) and the SQLite database file,
   - generates the application key,
   - runs the database migrations,
   - **builds the documentation index** so the `/docs` site is live,
   - compiles the front-end assets (`npm run build`).

   > **Using MySQL/PostgreSQL instead of SQLite?** Edit the `DB_*` values in `.env`
   > before running `composer setup` (or run `php artisan migrate` again afterward).

3. Start the development environment (runs the PHP server, Vite, queue worker, and log viewer concurrently):

```bash
composer dev
```

4. Open the app and create your first account:
   - Application: <http://localhost:8000>
   - Documentation: <http://localhost:8000/docs>

   After registering, your **API token** is shown on the dashboard, and you can add a
   payment provider from the **Providers** page.

## Documentation Site (`/docs`)

The in-app documentation lives at the **`/docs`** route and is powered by Markdown
files in [`prezet/content`](prezet/content), indexed into a small SQLite catalogue
for search and navigation.

`composer setup` already builds this index, so after setup the docs are live at
<http://localhost:8000/docs>. You only need to rebuild the index when you **add or
edit** documentation:

```bash
php artisan docs:index --fresh
```

Tips:

- While running `composer dev` (or `npm run dev`), the docs index **rebuilds
  automatically** whenever you change a file under `prezet/`.
- In **production**, run `php artisan docs:index --fresh` as part of your deploy
  (after pulling new content) so `/docs` reflects the latest documentation.
- To edit the docs, update the Markdown in `prezet/content/` and the sidebar
  navigation in `prezet/SUMMARY.md`.

## API Documentation & Consumption

To consume the payment switch APIs from external client applications, read the
**API Reference** at [`/docs`](http://localhost:8000/docs) (or the standalone
[API Documentation](API_DOCUMENTATION.md)) for endpoint specifications, payload
parameters, responses, and integration examples. In short: your application sends
a single authenticated request to `POST /api/v1/payment/request`, and the switch
routes it across your active providers with automatic fallback.

## Authors

- 👤 **Blessed Jason Mwanza**
  - GitHub: [@blessedjasonmwanza](https://github.com/blessedjasonmwanza)
  - Email: [blessed.jason.mwanza@example.com](mailto:blessed.jason.mwanza@example.com)
  - Website: [https://blessedjasonmwanza.github.io](https://blessedjasonmwanza.github.io)
  - X (Twitter): [@mwanzabj](https://twitter.com/mwanzabj)
  - LinkedIn: [Blessedjasonmwanza](https://linkedin.com/in/blessedjasonmwanza)


## Contributing

We welcome contributions! If you want to help improve MoneyUnify Switch:

- open an issue for bugs or feature requests
- send a pull request with a clear description and tests
- follow existing code style and architecture patterns

When contributing, please:

- include a meaningful commit message
- write or update tests for your changes
- keep new features small and incremental

## Show Your Support

If this project helps you, please consider supporting the development by:

- starring the repository on GitHub
- sharing it with the community
- suggesting improvements or use cases

If you want to donate, please use the MoneyUnify team or sponsorship links associated with this repository.
- Donate via Buy Me a Coffee: [https://buymeacoffee.com/mwanzabj](https://www.buymeacoffee.com/mwanzabj)
<!-- - Sponsor on GitHub: [https://github.com/sponsors/MoneyUnify](https://github.com/sponsors/MoneyUnify) -->

## License

This project is licensed under a Source-Available Proprietary License. See the [LICENSE.txt](LICENSE.txt) file for full details.
