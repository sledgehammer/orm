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
