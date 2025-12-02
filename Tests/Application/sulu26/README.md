# Sulu 2.6 Test Application

Creates test fixtures for migration testing. **Not required** for running tests.

## When to Use

- Add new test content (pages, snippets, articles)
- Reproduce edge cases
- Update test fixtures

## Setup

1. Install:
   ```bash
   composer install
   ```

2. Build:
   ```bash
   php bin/console doctrine:database:create
   php bin/adminconsole sulu:build dev
   ```

3. Start server:
   ```bash
   symfony server:start
   ```

## Export Fixture

After creating content:

```bash
# Dump your database
mysqldump -h 127.0.0.1 -u root -p migration_sulu_26 > Tests/Resources/fixtures/sulu26_dump.sql

# Prepare the test fixture (from bundle root)
php Tests/Scripts/prepare-test-fixture.php
```
