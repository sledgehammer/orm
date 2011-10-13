<?php
/**
 * Collection
 *
 * @package Record
 */

namespace SledgeHammer;
use \ArrayIterator;

class Collection extends Object implements \Iterator, \Countable, \ArrayAccess {

	/**
	 * @var Iterator
	 */
	protected $iterator;

	/**
	 * @param \Iterator|array $iterator
	 */
	function __construct($iterator) {
		if (is_array($iterator)) {
			$this->iterator = new ArrayIterator($iterator);
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

	function select($valueField, $keyField = null) {
		return new CollectionView(clone $this, $valueField, $keyField);
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
	function current() {
		return $this->iterator->current();
	}

	function key() {
		return $this->iterator->key();
	}

	function next() {
		return $this->iterator->next();
	}

	function rewind() {
		if ($this->iterator instanceof \Iterator) {
			return $this->iterator->rewind();
		}
		$type = gettype($this->iterator);
		$type = ($type == 'object') ? get_class($this->iterator) : $type;
		throw new \Exception(''.$type.' is not an Iterator');
	}

	function valid() {
		return $this->iterator->valid();
	}

	// Countable function

	function count() {
		return count($this->iterator);
	}

	// ArrayAccess functions

	function offsetExists($offset) {
		if (($this->iterator instanceof ArrayIterator) == false) {
			$this->iterator = new ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetExists($offset);
	}

	function offsetGet($offset) {
		if (($this->iterator instanceof ArrayIterator) == false) {
			$this->iterator = new ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetGet($offset);
	}

	function offsetSet($offset, $value) {
		if (($this->iterator instanceof ArrayIterator) == false) {
			$this->iterator = new ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetSet($offset, $value);
	}

	function offsetUnset($offset) {
		if (($this->iterator instanceof ArrayIterator) == false) {
			$this->iterator = new ArrayIterator(iterator_to_array($this->iterator));
		}
		return $this->iterator->offsetUnset($offset);
	}

	function __clone() {
		$this->iterator = new ArrayIterator(iterator_to_array($this->iterator));
//		$this->iterator = clone $this->iterator; // doesn't clone the data (in case of the ArrayIterator)
	}

}

?>
