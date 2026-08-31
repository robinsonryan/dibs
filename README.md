# Dibs

Headless slot-based booking engine for Laravel: availabilities generate slots, offers hold them, bookings claim them; polymorphic hosts with roles. No UI, no auth, no notifications - fires events, consumers decide.

## Installation

```bash
composer require robinsonryan/dibs
```

Publish the config if you need to change it:

```bash
php artisan vendor:publish --tag=dibs-config
```

## Development

```bash
ddev start
ddev composer quality     # lint:check -> analyze -> refactor:check -> test
ddev composer lint        # fix style
ddev composer refactor    # apply Rector
```

The gate is verify-only — it never rewrites your files.
