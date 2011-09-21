<?php
/**
 * PropertyPath, a helper class that resolves properties inside arrays and objects based on a path.
 * Inspired by XPath
 * But implemented similar to "Property Path Syntax" in Silverlight
 * @link http://msdn.microsoft.com/en-us/library/cc645024(v=VS.95).aspx
 *
 * Format:
 *   'abc'  maps to element $data['abc'] or property $data->abc.
 *   '.abc' maps to property $data->abc
 *   '[abc] maps to element  $data['abc']
 *   '.abc.efg' maps to property $data->abc->efg
 *   '.abc[efg]' maps to property $data->abc[efg]
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
		$dot = self::dotPosition($path);
		$bracket = self::openBracketPosition($path);
		if ($dot === false && $bracket === false) { // Autodetect element/property?
			if (is_array($data)) {
				return $data[$path];
			}
			if (is_object($data)) {
				return $data->$path;
			}
			notice('Unexpected type: '.gettype($data));
			return null;
		}
		if ($dot !== false && ($bracket === false || $dot < $bracket)) { // Property syntax?
			if ($dot > 0) {
				$data = self::get($data, substr($path, 0, $dot));
			}
			if (is_object($data) == false) {
				notice('Unexpected type: '.gettype($data).', expecting an object');
				return null;
			}
			$path = substr($path, $dot + 1); // remove dot
			$dot2 = self::dotPosition($path);
			if ($dot2 === false && $bracket === false) {
				return $data->$path;
			}
			if ($bracket !== false) {
				$bracket += $dot - 1;
				if ($dot2 === false || $bracket < $dot2) {
					$property = substr($path, 0, $bracket);
					$data = $data->$property;
					return self::get($data, substr($path, $bracket));
				}
			}
			$property = substr($path, 0, $dot2);
			$data = $data->$property;
			return self::get($data, substr($path, $dot2));
		}
		// array notation
		if ($bracket > 0) {
			$data = self::get($data, substr($path, 0, $bracket));
		}
		if (is_array($data) == false) {
			notice('Unexpected type: '.gettype($data).', expecting an array');
			return null;
		}
		$path = substr($path, $bracket + 1); // remove starting bracket '['
		$bracket = self::closeBracketPosition($path);
		if ($bracket === false) {
			notice('Unvalid path, unmatched brackets, missing a "]"');
		}
		$element = substr($path, 0, $bracket);
		$data = $data[$element];
		$path = substr($path, $bracket + 1);
		if ($path === false) {
			return $data;
		}
		return self::get($data, $path);
	}

	/**
	 * @param array|object $data
	 * @param string $path
	 * @param mixed $value
	 */
	static function set($data, $path, $value) {
		$parts = explode('.', $path);
		$ref = &$data;
		foreach ($parts as $index => $key) {
			if (is_array($data)) {
				$data = $data[$key];
			} elseif (is_object($data)) {
				$data = $data->$key;
			} else {
				notice('Unexcpected type: '.gettype($ref));
			}
		}
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

	// @todo check escaped positions

	private static function dotPosition($path) {
		return strpos($path, '.');
	}
	private static function openBracketPosition($path) {
		return strpos($path, '[');
	}
	private static function closeBracketPosition($path) {
		return strpos($path, ']');
	}

}

?>
