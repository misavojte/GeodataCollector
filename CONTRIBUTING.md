# Contributing

Thanks for your interest in Geodata Collector! Issues and pull requests are welcome.

## Reporting bugs and ideas

Open an [issue](https://github.com/misavojte/GeodataCollector/issues) and include:

- what you did, what you expected and what happened instead,
- browser, PHP and MySQL/MariaDB versions,
- for server errors: the relevant lines from your PHP error log or `logs/db.log`.

Please **never** paste your `config/config.php`, passwords or personal data from your records.
Report security problems privately to the maintainer instead of opening a public issue.

## Development setup

Follow the [Quick start](README.md#quick-start-local-5-minutes), load the demo data
(`php tools/install.php --demo`), and run the frontend with hot reload:

```bash
php -S 127.0.0.1:8088 router.php      # PHP API
cd frontend && npm run dev             # Vite dev server (proxies /api)
```

## Before you open a pull request

```bash
cd frontend && npm run check                         # 0 errors
find . -name '*.php' -not -path './frontend/*' -exec php -l {} \;
```

The same checks (plus a database install) run automatically on GitHub Actions.

## Conventions

- **Language**: code, comments and docs are in English. User-facing text in the SPA goes
  through `frontend/src/lib/i18n.*.ts` – add every new string to **all** dictionaries.
- **Backend**: plain PHP 8.0+, no framework and no Composer dependencies; keep it runnable
  on shared hosting. All SQL uses prepared statements.
- **API errors** return an English message plus a machine `code`; the frontend translates by code (`apiErr` in the dictionaries).
- **Database changes**: update `db/schema.sql` for new installations **and** add an
  idempotent `db/migrate_*.sql` for existing ones.
- Keep pull requests focused; describe what changed and how you tested it.
