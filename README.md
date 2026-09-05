### Run linter auto-correct :
```bash
php vendor/bin/phpcbf --ignore=app/Http/Controllers/Auth,app/Http/Controllers/Controller.php app/Http/Controllers
```

### Run the test suite :
```bash
vendor/bin/phpunit
# or
php artisan test
```

Tests run against an SQLite in-memory database (configured in `phpunit.xml`), so they
never touch the development database. The feature suites in `tests/Feature/*ApiTest.php`
pin the public API contract (see `server.md`); if a response shape changes, the suite
must be updated consciously in the same commit.

### Work tickets
Server work is tracked in [`tickets/`](tickets/README.md) (`API-0XX` tickets, one commit
each, spec in [`server.md`](server.md)).
