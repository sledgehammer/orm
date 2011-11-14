<?php
/**
 * RepositoryCollection
 *
 * @package Record
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
	 *
	 * @param Collection $collection
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

	public function offsetGet($offset) {
		if ($this->isConverted) {
			return parent::offsetGet($offset);
		}
		return $this->convertItem(parent::offsetGet($offset));
	}

	public function offsetSet($offset, $value) {
		if ($this->isConverted == false) {
			$repo = getRepository($this->repository);
			foreach ($this->data as $key => $item) {
				$this->data[$key] = $repo->convert($this->model, $item);
			}
			$this->isConverted = true;
		}
		parent::offsetSet($offset, $value);
	}

	public function where($conditions) {
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

}

?>
