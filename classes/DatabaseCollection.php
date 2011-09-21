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
	protected $config;
	protected $dbLink;
	
	function __construct($sql, $dbLink = 'default', $config = array()) {
		$this->sql = $sql;
		$this->dbLink = $dbLink;
		$this->config = $config;
	}
	
	function where($conditions) {
		$db = getDatabase($this->dbLink);
		$sql = $this->sql;
		if ($this->model === null) { // Not bound to an repository?
			// The result are rows(fetch_assoc arrays), all conditions must be columnnames (or invalid)
			foreach ($conditions as $column => $value) {
				$sql = $sql->andWhere($db->quoteIdentifier($column).' = '.$db->quote($value));
			}
			return new DatabaseCollection($sql, $this->dbLink, $this->config);
		}
		$sqlChanged = false;
		foreach ($conditions as $property => $value) {
			$column = @$this->config['columns'][$property];
			if ($column !== null) { // No direct mapping to a column available?
				$sql = $sql->andWhere($db->quoteIdentifier($column).' = '.$db->quote($value));
				$sqlChanged = true;
				unset($conditions[$property]);
			}
		}
		if ($sqlChanged) {
			$collection = new DatabaseCollection($sql, $this->dbLink, $this->config);
			$collection->bind($this->model, $this->repository);
			if (count($conditions) == 0) { // All conditions are handled by sql?
				return $collection;
			} else {
				return $collection->where($conditions); // Filter the remaining items in php
			}
		}
		// Filter all items in php
		return parent::where($conditions);
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
