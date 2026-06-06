# MoneyUnify Switch

![MoneyUnify Logo](public/moneyunify-logo-horizontal.png)

## Introduction and Objective

MoneyUnify Switch is a source-available, unified payment switch API platform designed to orchestrate payment provider integrations, manage customer accounts, and process transaction flows from a centralized dashboard. The platform provides a standardized interface for multiple payment gateways, allowing applications to initiate collections and automatically failover/retry across configured providers sequentially without modifying core business logic.

With dynamic provider routing, real-time transaction tracking, and automatic provider credential configuration from the admin panel, MoneyUnify Switch ensures high transaction success rates and eliminates single-gateway dependency.

### Documentation

- Refer to the [API Documentation](API_DOCUMENTATION.md) for specifications on authenticating and consuming the payment switch endpoints.

## Requirements

- PHP 8.5+
- Composer
- Node.js 18+ / npm or pnpm
- PostgreSQL preferably (you can use any db that supports native JSON columns)(configured via `.env`)
- Git

## Local Setup

1. Clone the repository:

```bash
git clone https://github.com/MoneyUnify/mu-switch.git
cd mu-switch
```

2. Create the environment file:

```bash
cp .env.example .env
```

3. Configure your database connection inside `.env` (SQLite or preferred database).

4. Run the automated setup command:

```bash
composer setup
```

5. Start the concurrent development server (runs PHP server, Vite, logs, and queue concurrently):

```bash
composer dev
```

## API Documentation & Consumption

To consume the payment switch APIs from external client applications, check the [API Documentation](API_DOCUMENTATION.md) for endpoint specifications, payload parameters, responses, and code integration examples.

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
