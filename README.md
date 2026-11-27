# Atom

Open Source, lightweight MVC framework built for **PHP 8.6.0+**.

Designed for fast prototyping, clean modularity, strict typing, adaptive RAM caching, and stateless execution (ready for Swoole, FrankenPHP, ZTS, and microservice architectures).

## Requirements

- **PHP `^8.6.0`** (ZTS / TSR-ready)
- **Composer**

## Installation & Setup

1. Clone the repository:

```bash
gh repo clone AtomFW/Atom
```

2. Install dependencies and initialize `.env`:

```bash
composer install
composer setup
```

3. Configure your environment variables in `.env` and review the configuration files in `config/` (`app.php`, `cache.php`, `database.php`, `modul.php`, `session.php`, `filesystem.php`, `logger.php`, `mail.php`, `auth.php`).

4. Run core and module database migrations:

```bash
php atom.php migrate
php atom.php module:migrate
```

## Key Features

- **Adaptive PHP RAM Cache (`Atom\Cache\MemoryCacheManager`)**: Unified cross-platform in-memory cache automatically selecting `apcu`, System V IPC shared memory (`sysvshm` + `sysvsem` + `sysvmsg`), or bounded PHP runtime memory with automatic `\Time\Duration` TTL self-purging, tags, lists/sets, locks, and queues.
- **Rich Auto-Mapping & Validation (`Atom\DataBase\AutoMapped` / `Atom\Filter\TypeCaster`)**: Out-of-the-box bidirectional mapping between Database, PHP 8.6 models, and HTTP forms (`email`, `slug`, `phone`, `mac`, `hex_color`, `semver`, `ip`/`ip_bin`/`ipv4_int`, `uri`/`url`, `csv`, `set`, `int_list`, `gzjson`, `money`/`cents`, `percent`, `timestamp`, `duration`, `point`, `uuid_bin`, `ulid_bin`, enums, and custom callback validators in `Atom\Model`).
- **Extensible Module System (`Atom\Modules\Modul`)**: Configuration-driven modules in `Atom/Modules/` (`config/modul.php`) with required-module verification, kernel `boot()` hooks, custom CLI commands, per-module `migration/` SQL/PHP `up` & `down` runners, and automatic discovery of per-module `tests/` suites.
- **Graphical Debug Error Inspector (`Atom\Modules\ErrorDebugger`)**: Interactive dark-mode error diagnostics active strictly when `APP_DEBUG=true`, displaying `-5` / `+5` source context lines, full file path + line + column/verse, complete causal & lifecycle event tree, and `#[\SensitiveParameter]`-redacted arguments.
- **Atom CLI Terminal (`Atom\Terminal\Terminal` / `atom.php`)**: Interactive REPL and command runner with Atom ASCII banner, TSR/ZTS status, ANSI colors, `ProgressBar`, tab-completion (`bash`/`zsh`/`pwsh`), automatic CSS/JS minification (`minify`), module migrations, and dynamic invocation of any `Atom\*` class (`call`).

## Project Structure

- `Atom/` — Core framework components and built-in modules (`Atom/Modules/`)
- `app/` — Application controllers, models, and views
- `config/` — Framework and application configuration files
- `migrations/` — Core database migrations
- `public/` — Web entry point (`public/index.php`)
- `runtime/` — Generated cache, logs, temporary files, and test runtime artifacts
- `tests/Atom/` — PHPUnit test suite for `Atom\` (plus module tests in `Atom/Modules/*/tests/`)

## Atom CLI Terminal Usage

```bash
# Launch interactive REPL with tab-completion
php atom.php

# Show Atom banner, version, TSR/ZTS status, and diagnostics
php atom.php version
php atom.php about

# Automatically scan and minify CSS and JS assets with progress bar
php atom.php minify

# Manage modules and module SQL migrations (up & downgrade)
php atom.php module:list
php atom.php module:verify
php atom.php module:migrate
php atom.php module:downgrade ErrorDebugger --steps=1

# Dynamically call any Atom\* class method and inspect returned data
php atom.php call Filter\TypeCaster::supportedTypes
php atom.php call Modules\NameGenerator\Name::generate 16 usr_
```

## Testing & Code Quality

Temporary files created during test runs are stored in `runtime/temp/` and cleaned up automatically. Both `tests/Atom` and per-module `Atom/Modules/*/tests` suites run together during global test execution:

```bash
# Run PHPUnit tests (Core + App + Modules)
composer test

# Run PHPStan static analysis (level 5)
composer stan

# Run PHPCS code style check / auto-fix
composer lint
composer fix

# Run PHPStan + PHPUnit together
composer check
```

## Documentation

Generate API documentation with Doctum:

```bash
composer docs
```

## License

See [License.md](License.md) (Community License (FCL) 2.0).

## Authors

- [@Timonix](https://www.github.com/di-Timonix)
