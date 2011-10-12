<?php
/**
 * CollectionView
 *
 */
namespace SledgeHammer;

class CollectionView extends Collection {

	protected $valueField = null;
	protected $keyField = null;

	function __construct($collection, $valueField, $keyField = null) {
		$this->valueField = $valueField;
		$this->keyField = $keyField;
		parent::__construct($collection);
	}

	public function key() {
		$key = parent::key();
		if ($this->keyField === null) {
			return $key;
		}
		return PropertyPath::get(parent::current(), $this->keyField);
	}
	function current() {
		$value = parent::current();
		if ($this->valueField === null) {
			return $value;
		}
		return PropertyPath::get($value, $this->valueField);
	}
	
	public function where($conditions) {
		if ($this->valueField === null && $this->iterator instanceof Collection) {
			return new CollectionView($this->iterator->where($conditions), null, $this->keyField);
		}
		return parent::where($conditions);
	}
}

?>
