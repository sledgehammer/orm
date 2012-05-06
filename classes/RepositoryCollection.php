<?php
/**
 * RepositoryCollection a Collection containing repository instances.
 *
 * Contains the raw \Traversable|array from the backend and converts the data to instances on-access.
 *
 * @package ORM
 */
namespace SledgeHammer;

class RepositoryCollection extends Collection {

	protected $model;
	protected $repository;
	protected $mapping;

	/**
	 * @var bool  True when all raw elements been converted to repository items. (Triggered by offsetSet, toArray, etc)
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

	function where($conditions) {
		if ($this->isConverted) {
			return parent::where($conditions);
		}
		if ($this->data instanceof Collection && is_array($conditions)) {
			$convertedConditions = array();
			foreach ($conditions as $field => $value) {
				if (isset($this->mapping[$field])) {
					$convertedConditions[$this->mapping[$field]] = $value;
					unset($conditions[$field]);
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
