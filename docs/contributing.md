# Upgrading, releases and contributing

[← Back to README](../README.md) · [All docs](README.md)

---

## Upgrading and releases

Versions follow semantic versioning: `1.x` releases never rename or remove a config key, route
name, translation key, class or event property. See [CHANGELOG.md](../CHANGELOG.md) and the
[GitHub Releases](https://github.com/TechnoBase-krd/laravel-system-status/releases).

```bash
composer update technobase/laravel-system-status
php artisan migrate
```

Read the *Upgrade notes* in the changelog for each version. Published configs keep working;
new options use their defaults until you add them.

**Releases are automatic.** Packagist picks up every new tag through the GitHub webhook. On every push to `main`, the *Release* workflow
(`.github/workflows/release.yml`) checks the PHP syntax and `composer.json`, reads the commit
messages since the last tag and, when needed, creates the next tag and a GitHub Release with
notes:

| Commits since the last release | Next version |
| --- | --- |
| `feat!:` / `fix!:` (any `type!:`) or a `BREAKING CHANGE:` line in the body | major, e.g. `v2.0.0` |
| `feat:` | minor, e.g. `v1.2.0` |
| `fix:`, `perf:`, `refactor:`, `revert:` | patch, e.g. `v1.1.1` |
| only `docs:`, `chore:`, `ci:`, `style:`, `test:` | no release |

To force a release, open *Actions → Release → Run workflow* and choose `patch`, `minor` or
`major`. Never create or move `v*` tags by hand.

To work on the package itself, see [`.claude/skills/laravel-system-status/SKILL.md`](../.claude/skills/laravel-system-status/SKILL.md).

## Contributing

Issues and pull requests are welcome on
[GitHub](https://github.com/TechnoBase-krd/laravel-system-status). Use
[Conventional Commits](https://www.conventionalcommits.org/) messages (`feat:`, `fix:`, `docs:`, …):
they decide the next version.

### Tests

The tests use [Pest](https://pestphp.com) on Orchestra Testbench.

```bash
composer install
composer test     # Pest
composer lint     # Pint (laravel preset)
composer serve    # demo app with sample data: open http://127.0.0.1:8000/demo-login (?locale=ar for RTL)
```

They run on in-memory SQLite by default. To run them against MySQL or PostgreSQL, put the
credentials in `tests/.env.testing` (git-ignored):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=system_status_test
DB_USERNAME=root
DB_PASSWORD=secret
```

A few tests use Redis on `127.0.0.1:6379` (database 15) when it is available. GitHub Actions
(`.github/workflows/tests.yml`) runs the suite on PHP 8.3 and 8.4, Laravel 12 and 13, and
SQLite, MySQL and PostgreSQL, plus `composer lint`.

## License

The MIT License (MIT). See [LICENSE](../LICENSE).
