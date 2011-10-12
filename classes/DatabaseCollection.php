<?php
/**
 * DatabaseCollection
 * Inspired by "Linq to SQL"
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
		// The result are rows(fetch_assoc arrays), all conditions must be columnnames (or invalid)
		foreach ($conditions as $column => $value) {
			$sql = $sql->andWhere($db->quoteIdentifier($column).' = '.$db->quote($value));
		}
		return new DatabaseCollection($sql, $this->dbLink);
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
