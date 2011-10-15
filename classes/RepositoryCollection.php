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
		$data = parent::current();
		$repo = getRepository($this->repository);
		return $repo->convert($this->model, $data);
	}
	public function where($conditions) {
		if ($this->iterator instanceof Collection) {
			$convertedConditions = array();
			foreach ($conditions as $field => $value) {
				if (isset($this->mapping[$field])) {
					$convertedConditions[$this->mapping[$field]] = $value;
					unset($conditions[$field]);
				}
			}
			if (count($convertedConditions) != 0) { // There are conditions the low-level collection can handle?  
				$collection = new RepositoryCollection($this->iterator->where($convertedConditions), $this->model, $this->repository, $this->mapping);
				if (count($conditions) == 0) {
					return $collection;
				}
				return $collection->where($conditions); // Apply the remaining conditions
			}
			
		}
		return parent::where($conditions);
	}
}

?>
