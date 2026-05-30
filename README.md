# MoneyUnify Switch

## Introduction and Objective

MoneyUnify Switch is a Laravel + Inertia React application designed to manage payment provider integrations, customer accounts, and transaction flows from a unified dashboard. It combines Laravel Fortify authentication, Sanctum API tokens, and Wayfinder route helpers to deliver a developer-friendly payment orchestration backend with a modern React frontend.

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

2. Install PHP dependencies:

```bash
composer install
```

3. Install JavaScript dependencies:

```bash
npm install
```

4. Create the environment file:

```bash
cp .env.example .env
```

5. Generate the application key:

```bash
php artisan key:generate
```

6. Configure your database connection inside `.env` - the .env.example file contains the necessary configuration options samples.

7. Run migrations:

> **Note**: The migrations will create the necessary tables for users, payment providers, customers, and transactions. Make sure your database is set up and the connection details in `.env` are correct before running this command.

```bash
php artisan migrate
```

8. Start the development environment:

```bash
npm run dev
```

If you prefer the Laravel `setup` shortcut script, you can run:

```bash
npm run setup
```

## Creating Fake Providers

A development-only Artisan command exists to create fake payment providers for a given user email. It is intentionally restricted to `local` or `development` environments.

```bash
php artisan mu:fake-providers user@example.com
```

To create a custom number of providers:

```bash
php artisan mu:fake-providers user@example.com --count=5
```

This command will:

- validate that the app environment is `local` or `development`
- find the user by email
- create fake `PaymentProvider` records using Faker data

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

This project is licensed under the MIT License. See the `LICENSE.txt` file for full details.
