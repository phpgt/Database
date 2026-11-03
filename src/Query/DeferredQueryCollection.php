<?php
namespace GT\Database\Query;

use Closure;
use GT\Database\Connection\Driver;
use GT\Database\Result\ResultSet;

class DeferredQueryCollection extends QueryCollection {
	protected ?QueryCollection $resolvedCollection = null;

	/** @param Closure():QueryCollection $resolver */
	public function __construct(
		string $name,
		Driver $driver,
		protected Closure $resolver,
	) {
		parent::__construct($name, $driver);
	}

	public function query(string $name, mixed...$placeholderMap):ResultSet {
		$collection = $this->resolve();
		$collection->setAppNamespace($this->appNamespace);
		return $collection->query($name, ...$placeholderMap);
	}

	public function getDirectoryPath():string {
		return $this->resolve()->getDirectoryPath();
	}

	protected function resolve():QueryCollection {
		return $this->resolvedCollection ??= ($this->resolver)();
	}
}
