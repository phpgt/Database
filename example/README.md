# Database examples

Run examples from the repository root:

```bash
php example/01-quick-start.php
```

Each example:

- creates an isolated SQLite database in a temporary directory
- seeds and uses its own query files
- removes all temporary files before exit

## Example list

- `01-quick-start.php`: basic CRUD with query files
- `02-parameter-binding.php`: named, positional and special bindings
- `03-type-safe-getters.php`: `fetch*` and row type conversion methods
- `04-dynamic-bindings.php`: `__dynamicIn`, `__dynamicOr`, plus special bindings
- `05-database-migrations.php`: running SQL migrations with `Migrator`
- `06-multiple-connections.php`: named connections and switching context
- `07-php-query-collections.php`: PHP-backed query collections with custom namespace
