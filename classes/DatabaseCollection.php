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
	protected $keyColumn;
	
	function __construct($sql, $dbLink = 'default', $keyColumn = null) {
		$this->sql = $sql;
		$this->dbLink = $dbLink;
		$this->keyColumn = $keyColumn;
	}
	
	public function rewind() {
		if ($this->iterator == null) {
			$db = getDatabase($this->dbLink);
			$this->iterator = $db->query($this->sql, $this->keyColumn);
		} else {
			// @todo iterator isDirty check
		}
		parent::rewind();
	}
}
?>
