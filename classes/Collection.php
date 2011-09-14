<?php
/**
 * Collection
 *
 * @package Record
 */
namespace SledgeHammer;
class Collection extends Object implements \Iterator, \Countable {

	/**
	 * @var Iterator
	 */
	protected $iterator;

	protected $model;
	protected $repository;

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

	function bind($model, $repository = 'master') {
		$this->model = $model;
		$this->repository = $repository;
	}

	function where($conditions) {
		$data = array();
		foreach ($this as $key => $item) {
			foreach ($conditions as $property => $value) {
				if (text($property)->endsWith('_id')) { // @todo 
					$property = substr($property, 0, -3);
					if ($item->$property->id == $value) {
						$data[$key] = $item;
					}
				} elseif ($item->$property == $value) {
					$data[$key] = $item;
				}
			}
		}
		return new Collection($data);
	}

	// Iterator function

	/**
	 *
	 * @return mixed
	 */
	public function current() {
		$data = $this->iterator->current();
		if ($this->repository === null) {
			return $data;
		}
		$repository = getRepository($this->repository);
		$instance = $repository->convert($this->model, $data);
		return $instance;
	}
	public function key() {
		return $this->iterator->key();
	}
	public function next() {
		return $this->iterator->next();
	}
	public function rewind() {
		if ($this->iterator instanceof \Iterator) {
			return $this->iterator->rewind();
		}
		$type = gettype($this->iterator);
		$type = ($type == 'object') ? get_class($this->iterator) : $type;
		throw new \Exception(''.$type.' is not an Iterator');
	}
	public function valid() {
		return $this->iterator->valid();
	}
	public function count() {
		return count($this->iterator);
	}
}
?>
