<?php
/**
 * RepositoryCollection
 */
namespace Sledgehammer;
/**
 * A Collection containing repository instances.
 * Contains the raw \Traversable|array from the backend and converts the data to instances on-access.
 *
 * @package ORM
 */
class RepositoryCollection extends Collection {

	/**
	 * The model that convert the data into instances
	 * @var string
	 */
	protected $model;

	/**
	 * The connected repository id.
	 * @var string
	 */
	protected $repository;

	/**
	 * Convert options.
	 * @var array
	 */
	protected $options = array();

	/**
	 * True when all raw elements been converted to repository items. (Triggered by offsetSet, toArray, etc)
	 * @var bool
	 */
	private $isConverted = false;

	/**
	 * Constructor
	 * @param \Traversable|array $data
	 * @param string $model
	 * @param string $repository
	 * @param array $options
	 *  'mapping' => array
	 *    data => object  mapping (reverse from modelConfig mapping)
	 */
	function __construct($collection, $model, $repository = 'default', $options = array()) {
		$this->model = $model;
		$this->repository = $repository;
		if (array_key_exists('mapping', $options) === false) {
			$options['mapping'] = array();
		}
		$this->options = $options;
		parent::__construct($collection);
	}

	function getIterator() {
		if ($this->isConverted) {
			return parent::getIterator();
		}
		if ($this->data instanceof \IteratorAggregate) {
			return new RepositoryCollectionIterator($this->data->getIterator(), $this->repository, $this->model);
		}
		if (is_array($this->data)) {
			return new RepositoryCollectionIterator(new \ArrayIterator($this->data), $this->repository, $this->model);
		}
		$this->dataToArray();
		return parent::getIterator();
	}

	function select($selector, $selectKey = false) {
		if ($this->isConverted || ($this->data instanceof Collection) === false) {
			return parent::select($selector, $selectKey);
		}
		if ($selectKey !== false && $selectKey !== null) {
			if (empty($this->options['mapping'][$selectKey])) { // Key not in the mapping array?
				return parent::select($selector, $selectKey);
			}
			$selectKeyMapped = $this->options['mapping'][$selectKey];
		} else {
			$selectKeyMapped = $selectKey;
		}
		if (is_string($selector) && isset($this->options['mapping'][$selector])) {
			// Bypass Repository and return the resultset directly from the backend-collection.
			return $this->data->select($this->options['mapping'][$selector], $selectKeyMapped);
		} elseif (is_array($selector)) {
			$selectorMapped = array();
			foreach ($selector as $to => $from) {
				if (empty($this->options['mapping'][$from])) { // Field not in the mapping array?
					return parent::select($selector, $selectKey);
				}
				$selectorMapped[$to] = $this->options['mapping'][$from];
			}
			// Bypass Repository and return the resultset directly from the backend-collection.
			return $this->data->select($selectorMapped, $selectKeyMapped);
		}
		// Closure selector
		return parent::select($selector, $selectKey);
	}

	function where($conditions) {
		if ($this->isConverted) {
			return parent::where($conditions);
		}
		if ($this->data instanceof Collection && is_array($conditions)) {
			$logicalOperator = extract_logical_operator($conditions);
			$convertedConditions = array();
			if ($logicalOperator === false) {
				if (count($conditions) > 1) {
					notice('Conditions with multiple conditions require a logical operator.', "Example: array('AND', 'x' => 1, 'y' => 5)");
				}
				$minimum = 0;
			} else {
				$minimum = 1;
				$convertedConditions[0] = $logicalOperator;
			}
			foreach ($conditions as $path => $value) {
				if (preg_match('/^(.*) ('.COMPARE_OPERATORS.')$/', $path, $match)) {
					$column = $match[1];
					$columnOperator = ' '.$match[2];
				} else {
					$column = $path;
					$columnOperator = '';
				}
				if (($path !== 0 || $logicalOperator === false) && isset($this->options['mapping'][$column])) {
					$convertedConditions[$this->options['mapping'][$column].$columnOperator] = $value;
					unset($conditions[$path]);
				}
			}
			if (count($convertedConditions) > $minimum) { // There are conditions the low-level collection can handle?
				$collection = new RepositoryCollection($this->data->where($convertedConditions), $this->model, $this->repository, $this->options);
				if (count($conditions) === $minimum) {
					return $collection;
				}
				return $collection->where($conditions); // Apply the remaining conditions
			} elseif (count($conditions) === $minimum) { // An empty array was given as $conditions?
				return new RepositoryCollection(clone $this->data, $this->model, $this->repository, $this->options);
			}
		}
		return parent::where($conditions);
	}

	function skip($length) {
		if ($this->isConverted === false && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->skip($length), $this->model, $this->repository, $this->options);
		}
		return parent::skip($length);
	}

	function take($length) {
		if ($this->isConverted === false && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->take($length), $this->model, $this->repository, $this->options);
		}
		return parent::take($length);
	}

	function orderBy($selector, $method = SORT_REGULAR) {
		if ($this->isConverted === false && is_string($selector) && isset($this->options['mapping'][$selector]) && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->orderBy($this->options['mapping'][$selector], $method), $this->model, $this->repository, $this->options);
		}
		return parent::orderBy($selector, $method);
	}

	function orderByDescending($selector, $method = SORT_REGULAR) {
		if ($this->isConverted === false && is_string($selector) && isset($this->options['mapping'][$selector]) && $this->data instanceof Collection) {
			return new RepositoryCollection($this->data->orderByDescending($this->options['mapping'][$selector], $method), $this->model, $this->repository, $this->options);
		}
		return parent::orderByDescending($selector, $method);
	}

	/**
	 * Return the number of elements in the collection.
	 * count($collection)
	 *
	 * @return int
	 */
	function count() {
		return count($this->data);
	}

	function toArray() {
		if ($this->isConverted === false) {
			$this->dataToArray();
		}
		return $this->data;
	}

	function getQuery() {
		if ($this->data instanceof Collection) {
			return $this->data->getQuery();
		}
		throw new \Exception('The getQuery() method is not available');
	}

	function setQuery($query) {
		if ($this->data instanceof Collection) {
			return $this->data->setQuery($query);
		}
		throw new \Exception('The setQuery() method is not available');
	}

	protected function dataToArray() {
		if ($this->isConverted === false) {
			$repo = getRepository($this->repository);
			$data = array();
			foreach ($this->data as $key => $item) {
				$data[$key] = $repo->convert($this->model, $item, $this->options);
			}
			$this->data = $data;
			$this->isConverted = true;
		}
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
		return $repo->convert($this->model, $item, $this->options);
	}

}

?>
