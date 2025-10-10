<img src="logo.png" alt="Database logic organisation." align="right" />

# Database API organisation.

Encloses your application's database scripts within a simple and standardised interface, separating database access from your application logic.

The first parameter to any database functions is always the query name, which represents a query file on disk - either a raw SQL file or a PHP representation of a query using [SqlBuilder][sqlbuilder].

***

<a href="https://github.com/PhpGt/Database/actions" target="_blank">
	<img src="https://badge.status.php.gt/database-build.svg" alt="Build status" />
</a>
<a href="https://app.codacy.com/gh/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-quality.svg" alt="Code quality" />
</a>
<a href="https://app.codecov.io/gh/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-coverage.svg" alt="Code coverage" />
</a>
<a href="https://packagist.org/packages/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-version.svg" alt="Current version" />
</a>
<a href="http://www.php.gt/database" target="_blank">
	<img src="https://badge.status.php.gt/database-docs.svg" alt="PHP.Gt/Database documentation" />
</a>

## Example usage

This library organises SQL access through a consistent API. To execute an example query located at `src/query/user/getById.sql`, the following pattern is used:

```php
$userRow = $db->fetch("user/getById", 105);
```

Examples of CRUD operations:

```php
// "fetchAll" method returns an iterable ResultSet of Row objects.
$bookResultSet = $db->fetchAll("shopitem/getItemsInCategory", "books");

foreach($bookResultSet as $bookRow) {
	echo "Book title: ", $bookRow->getString("title"), PHP_EOL;
	echo "Book price: £", ($bookRow->getFloat("price") + $bookRow->getFloat("vat")), PHP_EOL;
	
	if($bookRow->offerEnds) {
		echo "Item on offer until: ", $bookRow->getDateTime("offerEnds")->format("dS M Y");
	}
}

// "Create" method always returns the last inserted ID:
$newCustomerId = $db->create("customer/new", [
	"first_name" => "Marissa",
	"last_name" => "Mayer",
	"dob" => new DateTime("1975-05-30"),
]);

// "Update" or "delete" methods always return the number of affected rows:
$numberOfItemsAffected = $db->update("shop/item/increasePrice", [
	"percent" => 12.5,
	"max_increase" => 20.00,
]);

$numberOfDeletedReviews = $db->delete(
	"remove/deleteOlderThan",
	new DateTime("-6 months")
);

// Individual type-safe fields can be pulled from queries that return only one column:
$userName = $db->fetchString("user/getUsernameById", 105);
```

## Features at a glance

+ [Automatic database migrations][wiki-migrations]
+ [Encapsulation of queries using `QueryCollection`s][wiki-query-collections]
+ [Bind parameters by name or sequentially][wiki-parameters]
+ [Fully configurable][wiki-config]
+ [Type safe getters][wiki-type-safety]

[sqlbuilder]: https://www.php.gt/sqlbuilder
[wiki-query-collections]: https://www.php.gt/docs/database/query-collections
[wiki-parameters]: https://www.php.gt/docs/database/parameters
[wiki-migrations]: https://www.php.gt/docs/database/migrations
[wiki-config]: https://www.php.gt/docs/database/config
[wiki-type-safety]: https://www.php.gt/docs/database/type-safety

### Proudly sponsored by

[JetBrains Open Source sponsorship program](https://www.jetbrains.com/community/opensource/)

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://www.jetbrains.com/community/opensource/)
