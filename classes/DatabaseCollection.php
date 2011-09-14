<?php
/**
 * DatabaseCollection
 * 
 * @package Record
 */
namespace SledgeHammer;
class DatabaseCollection extends Collection {

	/**
	 * @var SQL
	 */
	public $sql;
	protected $dbLink;
	
	function __construct($sql, $dbLink = 'default') {
		$this->sql = $sql;
		$this->dbLink = $dbLink;
	}
	
	function where($conditions) {
		$db = getDatabase($this->dbLink);
		$sql = $this->sql;
		foreach ($conditions as $column => $value) {
			$sql = $sql->andWhere($db->quoteIdentifier($column).' = '.$db->quote($value));
		}
		$collection = new DatabaseCollection($sql, $this->dbLink);
		$collection->bind($this->model, $this->repository);
		return $collection;
		// fallback
		// @todo detect non-database properties
//		$this->validateIterator();
//		return parent::where($conditions);
	}
 
	
	public function rewind() {
		$this->validateIterator();		
		parent::rewind();
	}
	public function count() {
		$this->validateIterator();
		return parent::count();
	}
	
	private function validateIterator() {
		if ($this->iterator == null) {
			$db = getDatabase($this->dbLink);
			$this->iterator = $db->query($this->sql);
		} else {
			// @todo iterator isDirty check
		}
	}
}
?>
