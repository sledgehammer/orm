<?php
/**
 * RepositoryCollection
 */
namespace Sledgehammer;
/**
 * A Collection containing repository instances.
 * Contains the raw \Traversable|array from the backend and converts the data to instances on-access.
 *
 * @package ORM
 */
class RepositoryCollection extends Collection {

	/**
	 * The model that convert the data into instances
	 * @var string
	 */
	protected $model;

	/**
	 * The connected repository id.
	 * @var string
	 */
	protected $repository;

	/**
	 * Paths with direct mapping to the backend data.
	 * @var array array($path => $column)
	 */
	protected $mapping;

	/**
	 * True when all raw elements been converted to repository items. (Triggered by offsetSet, toArray, etc)
	 * @var bool
	 */
	private $isConverted = false;

	/**
	 * Constructor
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

	/**
	 * Iterator::current()
	 * @return object
	 */
	function current() {
		if ($this->isConverted) {
			return parent::current();
		}
		return $this->convertItem(parent::current());
	}

	function select($selector, $selectKey = false) {
		if ($this->isConverted || ($this->data instanceof Collection) === false) {
			return parent::select($selector, $selectKey);
		}
		if ($selectKey !== false && $selectKey !== null) {
			if (empty($this->mapping[$selectKey])) { // Key not in the mapping array?
				return parent::select($selector, $selectKey);
			}
			$selectKeyMapped = $this->mapping[$selectKey];
		} else {
			$selectKeyMapped = $selectKey;
		}
		if (is_string($selector) && isset($this->mapping[$selector])) {
			// Bypass Repository and return the resultset directly from the backend-collection.
			return $this->data->select($this->mapping[$selector], $selectKeyMapped);
		} elseif (is_array($selector)) {
			$selectorMapped = array();
			foreach ($selector as $to => $from) {
				if (empty($this->mapping[$from])) { // Field not in the mapping array?
					return parent::select($selector, $selectKey);
				}
				$selectorMapped[$to] = $this->mapping[$from];
			}
			// Bypass Repository and return the resultset directly from the backend-collection.
			return $this->data->select($selectorMapped, $selectKeyMapped);
		}
		// Closure selector
		return parent::select($selector, $selectKey);
	}

	function where($conditions) {
		if ($this->isConverted) {
			return parent::where($conditions);
		}
		if ($this->data instanceof Collection && is_array($conditions)) {
			$convertedConditions = array();
			foreach ($conditions as $path => $value) {
				if (isset($this->mapping[$path])) {
					$convertedConditions[$this->mapping[$path]] = $value;
					unset($conditions[$path]);
				}
			}
			if (count($convertedConditions) !== 0) { // There are conditions the low-level collection can handle?
				$collection = new RepositoryCollection($this->data->where($convertedConditions), $this->model, $this->repository, $this->mapping);
				if (count($conditions) == 0) {
					return $collection;
				}
				return $collection->where($conditions); // Apply the remaining conditions
			} elseif (count($conditions) === 0) { // An empty array was given as $conditions?
				return new RepositoryCollection(clone $this->data, $this->model, $this->repository, $this->mapping);
			}
		}
		return parent::where($conditions);
	}

	function skip($length) {
		if ($this->isConverted === false && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->skip($length), $this->model, $this->repository, $this->mapping);
		}
		return parent::skip($length);
	}

	function take($length) {
		if ($this->isConverted === false && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->take($length), $this->model, $this->repository, $this->mapping);
		}
		return parent::take($length);
	}

	function orderBy($selector, $method = SORT_REGULAR) {
		if ($this->isConverted === false && is_string($selector) && isset($this->mapping[$selector]) && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->orderBy($this->mapping[$selector], $method), $this->model, $this->repository, $this->mapping);
		}
		return parent::orderBy($selector, $method);
	}

	function orderByDescending($selector, $method = SORT_REGULAR) {
		if ($this->isConverted === false && is_string($selector) && isset($this->mapping[$selector]) && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->orderByDescending($this->mapping[$selector], $method), $this->model, $this->repository, $this->mapping);
		}
		return parent::orderByDescending($selector, $method);
	}

	/**
	 * Return the number of elements in the collection.
	 * count($collection)
	 *
	 * @return int
	 */
	function count() {
		return count($this->data);
	}

	function toArray() {
		if ($this->isConverted === false) {
			$this->dataToArray();
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

	protected function dataToArray() {
		if ($this->isConverted === false) {
			$repo = getRepository($this->repository);
			$data = array();
			foreach ($this->data as $key => $item) {
				$data[$key] = $repo->convert($this->model, $item);
			}
			$this->data = $data;
			$this->isConverted = true;
		}
	}

}

?>
