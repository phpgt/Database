<img src="logo.png" alt="Database logic organisation." align="right" />

# Database API organisation.

Encloses your application's database scripts within a simple and standardised interface, separating database access from your page logic.

***

<a href="https://circleci.com/gh/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-build.svg" alt="Build status" />
</a>
<a href="https://scrutinizer-ci.com/g/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-quality.svg" alt="Code quality" />
</a>
<a href="https://scrutinizer-ci.com/g/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-coverage.svg" alt="Code coverage" />
</a>
<a href="https://packagist.org/packages/PhpGt/Database" target="_blank">
	<img src="https://badge.status.php.gt/database-version.svg" alt="Current version" />
</a>
<a href="http://www.php.gt/database" target="_blank">
	<img src="https://badge.status.php.gt/database-docs.svg" alt="PHP.Gt/Database documentation" />
</a>

## Example usage

This library organises SQL access through a consistent API. To execute an example query located at `src/query/shop_item/getItemsInCategory.sql`, the following pattern is used:

```php
$userRow = $db->fetch("user/getById", 105);
```

Examples of CRUD operations:

```php
// "fetchAll" method returns an iterable ResultSet of Row objects.
$bookResultSet = $db->fetchAll("shopitem/getItemsInCategory", "books");

foreach($bookResultSet as $bookRow) {
	echo "Book title: " . $bookRow->title . PHP_EOL;
	echo "Book price: " . $bookRow->price . PHP_EOL;
	
	if($bookRow->offerEnds) {
		echo "Item on offer until: " . $bookRow->offerEnds->format("dS M Y");
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
	"percent" => 12.5
	"max_increase" => 20.00
]);

$numberOfDeletedReviews = $db->delete(
	"remove/deleteOlderThan",
	new DateTime("-6 months")
);
```

## Features at a glance

+ [Automatic database migrations][wiki-migrations]
+ [Encapsulation of queries using `QueryCollection`s][wiki-query-collections]
+ [Bind parameters by name or sequentially][wiki-parameters]
+ [Fully configurable][wiki-config]

## Planned features

+ Compatibility with no-SQL databases

[wiki-query-collections]: https://www.php.gt/docs/database/query-collections
[wiki-parameters]: https://www.php.gt/docs/database/parameters
[wiki-migrations]: https://www.php.gt/docs/database/migrations
[wiki-config]: https://www.php.gt/docs/database/config
