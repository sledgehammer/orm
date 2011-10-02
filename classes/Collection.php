<?php
/**
 * Collection
 *
 * @package Record
 */
namespace SledgeHammer;
class Collection extends Object implements \Iterator, \Countable, \ArrayAccess {

	public $keyField;
	public $valueField;
	/**
	 * @var Iterator
	 */
	protected $iterator;

	protected $model;
	protected $repository;

	private $current;

	/**
	 * @param \Iterator|array $iterator
	 */
	function __construct($iterator) {
		if (is_array($iterator)) {
			$this->iterator = new \ArrayIterator($iterator);
		} else {
			$this->iterator = $iterator;
		}
	}

	/**
	 * Return all collections items as an array
	 * @return array
	 */
	function asArray() {
		return iterator_to_array($this);
	}

	/**
	 *
	 * @param array $conditions
	 * @return Collection
	 */
	function where($conditions) {
		$data = array();
		foreach ($this as $key => $item) {
			foreach ($conditions as $path => $expectation) {
				$actual = PropertyPath::get($item, $path);
				if (equals($actual, $expectation) == false) {
					continue 2; // Skip this entry
				}
			}
			// $data[$key] = $item; @todo check if its not an index array
			$data[] = $item;

		}
		return new Collection($data);
	}

	// Iterator functions

	/**
	 *
	 * @return mixed
	 */
	public function current() {
		if ($this->valueField === null) {
			return $this->current;
		}
		return PropertyPath::get($this->current, $this->valueField);
	}
	public function key() {
		if ($this->keyField === null) {
			return $this->iterator->key();
		}
		return PropertyPath::get($this->current, $this->keyField);
	}
	public function next() {
		$retval = $this->iterator->next();
		$this->current = $this->convertValue($this->iterator->current());
		return $retval;
	}
	public function rewind() {
		if ($this->iterator instanceof \Iterator) {
			$retval = $this->iterator->rewind();
			$this->current = $this->convertValue($this->iterator->current());
			return $retval;
		}
		$type = gettype($this->iterator);
		$type = ($type == 'object') ? get_class($this->iterator) : $type;
		throw new \Exception(''.$type.' is not an Iterator');
	}
	public function valid() {
		return $this->iterator->valid();
	}

	// Countable function
	public function count() {
		return count($this->iterator);
	}

	// ArrayAccess functions
	public function offsetExists($offset) {
		if (($this->iterator instanceof \ArrayIterator) == false) {
			$this->iterator = new \ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetExists($offset);
	}
	public function offsetGet($offset) {
		if (($this->iterator instanceof \ArrayIterator) == false) {
			$this->iterator = new \ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->convertValue($this->iterator->offsetGet($offset));
	}
	public function offsetSet($offset, $value) {
		if (($this->iterator instanceof \ArrayIterator) == false) {
			$this->iterator = new \ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetSet($offset, $value);
	}
	public function offsetUnset($offset) {
		if (($this->iterator instanceof \ArrayIterator) == false) {
			$this->iterator = new \ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetUnset($offset);
	}

	// Repository binding

	/**
	 * Bind a model from a repository to the items in this collection
	 *
	 * @param string $model The model
	 * @param string $repository The repository id
	 */
	function bind($model, $repository = 'default', $options = array()) {
		$this->model = $model;
		$this->repository = $repository;
		foreach ($options as $property => $value) {
			dump($property);
		}
	}

	/**
	 * Convert the raw data to an instance via the repository
	 *
	 * @param mixed $value
	 * @return stdClass
	 */
	private function convertValue($value) {
		if ($value === null) {
			return null;
		}
		if ($this->repository !== null) { // Not bound to a repository?
			$repository = getRepository($this->repository);
			return $repository->convert($this->model, $value);
		}
	}
}
?>
