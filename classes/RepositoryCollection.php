<?php
/**
 * RepositoryCollection a Collection containing repository instances.
 *
 * Will contain the raw \Traversable|array from the backend and will convert the data
 *
 * @package ORM
 */
namespace SledgeHammer;

class RepositoryCollection extends Collection {

	protected $model;
	protected $repository;
	protected $mapping;

	/**
	 * @var bool  True when all raw elements been converted to repository items. (Triggered by offsetSet)
	 */
	private $isConverted = false;

	/**
	 * @param \Traversable|array $data
	 * @param string $model
	 * @param string $repository
	 * @param array $mapping data => object  mapping (reverse from modelConfig mapping)
	 */
	function __construct($collection, $model, $repository = 'default', $mapping = array()) {
		$this->model = $model;
		$this->repository = $repository;
		$this->mapping = $mapping;
		parent::__construct($collection);
	}

	function current() {
		if ($this->isConverted) {
			return parent::current();
		}
		return $this->convertItem(parent::current());
	}

	function offsetGet($offset) {
		if ($this->isConverted) {
			return parent::offsetGet($offset);
		}
		return $this->convertItem(parent::offsetGet($offset));
	}

	function offsetSet($offset, $value) {
		if ($this->isConverted === false) {
			$this->data = $this->convertAllItems();
		}
		parent::offsetSet($offset, $value);
	}

	function where($conditions) {
		if ($this->isConverted) {
			return parent::where($conditions);
		}
		if ($this->data instanceof Collection) {
			$convertedConditions = array();
			foreach ($conditions as $field => $value) {
				if (isset($this->mapping[$field])) {
					$convertedConditions[$this->mapping[$field]] = $value;
					unset($conditions[$field]);
				}
			}
			if (count($convertedConditions) != 0) { // There are conditions the low-level collection can handle?
				$collection = new RepositoryCollection($this->data->where($convertedConditions), $this->model, $this->repository, $this->mapping);
				if (count($conditions) == 0) {
					return $collection;
				}
				return $collection->where($conditions); // Apply the remaining conditions
			}
		}
		return parent::where($conditions);
	}

	function toArray() {
		if ($this->isConverted === false) {
			$this->data = $this->convertAllItems();
		}
		return $this->data;
	}

	function setQuery($query) {
		if ($this->data instanceof Collection) {
			$this->data->setQuery($query);
		}
		throw new \Exception('The setQuery() method failed');
	}

	/**
	 * Convert the raw data to an repository object
	 * @param mixed $item
	 * @return object
	 */
	private function convertItem($item) {
		if ($this->isConverted) {
			return $item;
		}
		$repo = getRepository($this->repository);
		return $repo->convert($this->model, $item);
	}

	private function convertAllItems() {
		$repo = getRepository($this->repository);
		$data = array();
		foreach ($this->data as $key => $item) {
			$data[$key] = $repo->convert($this->model, $item);
		}
		$this->isConverted = true;
		return $data;
	}
}

?>
