<?php
/**
 * PropertyPath, a helper class that resolves properties inside arrays and objects based on a path.
 * Inspired by XPath 
 * But implemented similar to "Property Path Syntax" in Silverlight
 * @link http://msdn.microsoft.com/en-us/library/cc645024(v=VS.95).aspx
 * 
 * @package Record
 */
namespace SledgeHammer;
class PropertyPath extends Object {
	
	/**
	 *
	 * @param array|object $data
	 * @param string $path
	 * @return mixed
	 */
	static function get($data, $path) {
		$parts = explode('.', $path);
		foreach ($parts as $index => $key) {
			if (is_array($data)) {
				$data = $data[$key];
			} elseif (is_object($data)) {
				$data = $data->$key;
			} else {
				notice('Unexcpected type: '.gettype($ref));
				return null;
			}
		}
		return $data;
	}

	/**
	 * @param string $path
	 * @param object/array $data 
	 */
	static private function &reference($data, $path) {
		$parts = explode('.', $path);
		$ref = &$data;
		foreach ($parts as $index => $key) {
			if (is_array($ref)) {
				$ref = &$ref[$key];
			} elseif (is_object($ref)) {
				$ref = &$ref->$key;
			} else {
				throw new \Exception('Unexcpected type: '.gettype($ref));
			}
		}
		return $ref;
	}
	
}

?>
