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
	function bind($model, $repository = 'master') {
		$this->model = $model;
		$this->repository = $repository;
	}
	public function current() {
		$data = $this->iterator->current();
		if ($this->repository === null) {
			return $data;
		}
		$repository = getRepository($this->repository);
		$instance = $repository->loadInstance($this->model, $this->key(), $data);
		return $instance;
	}
	public function key() {
		return $this->iterator->key();
	}
	public function next() {
		return $this->iterator->next();
	}
	public function rewind() {
		return $this->iterator->rewind();
	}
	public function valid() {
		return $this->iterator->valid();
	}
	public function count() {
		return count($this->iterator);
	}
}
?>
