<?php
/**
 * RepositoryCollectionIterator
 */
namespace Sledgehammer;
/**
 * Helper class for the RepositoryCollection, which converts data to the mode instances when needed.
 */
class RepositoryCollectionIterator extends Object implements \Iterator {

	/**
	 * @var Iterator
	 */
	private $iterator;

	/**
	 * @var string|Repository
	 */
	private $repository;

	/**
	 * @var string
	 */
	private $model;

	/**
	 *
	 * @param Iterator $iterator
	 * @param string|Repository $repository
	 * @param string $model
	 */
	function __construct($iterator, $repository, $model) {
		$this->iterator = $iterator;
		$this->repository = $repository;
		$this->model = $model;
	}

	function current() {
		$repo = getRepository($this->repository);
		return $repo->convert($this->model, $this->iterator->current());
	}

	function key() {
		return $this->iterator->key();
	}

	function next() {
		return $this->iterator->next();
	}

	function rewind() {
		return $this->iterator->rewind();
	}

	function valid() {
		return $this->iterator->valid();
	}

}

?>
