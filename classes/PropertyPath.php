<?php
/**
 * PropertyPath, a helper class that resolves properties inside arrays and objects based on a path.
 * Inspired by XPath
 * But implemented similar to "Property Path Syntax" in Silverlight
 * @link http://msdn.microsoft.com/en-us/library/cc645024(v=VS.95).aspx
 *
 * @todo Escaping '[12\[34]' for key '12[34'
 * @todo Dot notation for automatic switching
 * @todo? Wildcards for getting and setting a range of properties ''
 * @todo? $path Validation properties
 *
 * Format:
 *   'abc'  maps to element $data['abc'] or property $data->abc.
 *   '->abc' maps to property $data->abc
 *   '[abc] maps to element  $data['abc']
 *   '->abc->efg' maps to property $data->abc->efg
 *   '->abc[efg]' maps to property $data->abc[efg]
 *
 * @package Record
 */
namespace SledgeHammer;
class PropertyPath extends Object {

	const TYPE_PROPERTY = 'PROPERTY';
	const TYPE_ELEMENT = 'ELEMENT';
	const TYPE_ANY = 'ANY';
	const TYPE_CHAIN = 'CHAIN';

	/**
	 *
	 * @param array|object $data
	 * @param string $path
	 * @return mixed
	 */
	static function get($data, $path) {
		$arrow = self::arrowPosition($path);
		$bracket = self::openBracketPosition($path);
		if ($arrow === false && $bracket === false) { // Autodetect element/property?
			if (is_array($data)) {
				return $data[$path];
			}
			if (is_object($data)) {
				return $data->$path;
			}
			notice('Unexpected type: '.gettype($data));
			return null;
		}
		if ($arrow !== false && ($bracket === false || $arrow < $bracket)) { // Property syntax?
			if ($arrow > 0) {
				$data = self::get($data, substr($path, 0, $arrow));
			}
			if (is_object($data) == false) {
				notice('Unexpected type: '.gettype($data).', expecting an object');
				return null;
			}
			$path = substr($path, $arrow + 2); // remove arrow
			$arrow2 = self::arrowPosition($path);
			if ($arrow2 === false && $bracket === false) {
				return $data->$path;
			}
			if ($bracket !== false) {
				$bracket += $arrow - 2;
				if ($arrow2 === false || $bracket < $arrow2) {
					$property = substr($path, 0, $bracket);
					$data = $data->$property;
					return self::get($data, substr($path, $bracket));
				}
			}
			$property = substr($path, 0, $arrow2);
			$data = $data->$property;
			return self::get($data, substr($path, $arrow2));
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
	 *
	 * @param array|object $data
	 * @param string $path
	 * @param mixed $value
	 */
	static function set(&$data, $path, $value) {
		$arrow = self::arrowPosition($path);
		$bracket = self::openBracketPosition($path);
		if ($arrow === false && $bracket === false) { // Autodetect element/property?
			if (is_array($data)) {
				$data[$path] = $value;
				return true;
			}
			if (is_object($data)) {
				$data->$path = $value;
				return true;
			}
			notice('Unexpected type: '.gettype($data));
			// @todo create object or array?
			return false;
		}
		if ($arrow !== false && ($bracket === false || $arrow < $bracket)) { // Property syntax?
			if ($arrow == 0) {
				if (is_object($data) == false) {
					notice('Unexpected type: '.gettype($data).', expecting an object');
					// @todo create object?
					return null;
				}
 				return self::set($data, substr($path, 2), $value);
			}
			$identifier = substr($path, 0, $arrow);
			if (is_object($data)) {
				$data = &$data->$identifier;

			} elseif (is_array($data)) {
				$data = &$data[$identifier];

			} else {
				notice('Unexpected type: '.gettype($data).', expecting an object');
				// @todo create object or array?
				return false;
			}
			$path = substr($path, $arrow + 2); // remove arrow
			$arrow2 = self::arrowPosition($path);
			if ($arrow2 === false && $bracket === false) {
				$data->$path = $value;
				return true;
			}
			if ($bracket !== false) {
				$bracket += $arrow - 2;
				if ($arrow2 === false || $bracket < $arrow2) {
					$property = substr($path, 0, $bracket);
					return self::set($data->$property, substr($path, $bracket));
				}
			}
			$property = substr($path, 0, $arrow2);
			return self::set($data->$property, substr($path, $arrow2), $value);
		}
		// array notation
		if ($bracket == 0) {
			if (is_array($data) == false) {
				notice('Unexpected type: '.gettype($data).', expecting an array');
				return false;
			}
			// $data = self::set($data, substr($path, 0, $bracket));
		} else {
			$identifier = substr($path, 0, $bracket);
			if (is_object($data)) {
				$data = &$data->$identifier;
			} elseif (is_array($data)) {
				$data = &$data[$identifier];
			} else {
				notice('Unexpected type: '.gettype($data).', expecting an object or array');
				// @todo create object or array?
				return false;
			}
		}
		$path = substr($path, $bracket + 1); // remove starting bracket '['
		$bracket = self::closeBracketPosition($path);
		if ($bracket === false) {
			notice('Unvalid path, unmatched brackets, missing a "]"');
		}
		$element = substr($path, 0, $bracket);
		$path = substr($path, $bracket + 1);
		if ($path === false) {
			$data[$element] = $value;
			return true;
		}
		return self::set($data[$element], $path, $value);
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




	/**
	 * Compile the path into
	 *
	 * @param string $path
	 * @return array
	 */
	static function compile($path) {
		$parts = self::compilePath($path, self::TYPE_ANY);
		// Validate parts
		foreach ($parts as $part) {
			if ($part[0] == self::TYPE_PROPERTY && preg_match('/^[a-z_]{1}[a-z_0-9]*$/i', $part[1]) != 1) {
				notice('Invalid property identifier "'.$part[1].'" in path "'.$path.'"');
			}
		}
		return $parts;
	}
	/**
	 *
	 * @param string $path
	 * @param TYPE $type start type
	 * @return array
	 */
	private static function compilePath($path, $type) {
		if ($path == '') {
			notice('$path was empty');
			return array();
		}
		$tokens = array();
		$arrowPos = self::arrowPosition($path);
		$bracketPos = self::openBracketPosition($path);
		$dotPos = self::dotPosition($path);
		if ($type === self::TYPE_CHAIN) {
			if ($dotPos === 0) {
				return self::compilePath(substr($path, 1), self::TYPE_ANY);
			}
			if ($arrowPos !== 0 && $bracketPos !== 0) {
				notice('Invalid chain, expecting a ".", "->" or "[" before "'.$path.'"');
			}
			$type = self::TYPE_ANY;
		}
		if ($arrowPos === false && $bracketPos === false && $dotPos === false) {
			$tokens[] = array($type, $path);
			return $tokens;
		}
		if ($arrowPos !== false && ($bracketPos === false || $arrowPos < $bracketPos) && ($dotPos === false || $arrowPos < $dotPos) ) {
			// PROPERTY(OBJECT)
			if ($arrowPos !== 0) {
				$tokens[] = array($type, substr($path, 0, $arrowPos));
			} elseif ($type !== self::TYPE_ANY) {
				notice('Invalid "->" in in the chain', array('path' => $path));

			}
//			if ($bracketPos === false) {
//				$secondArrowPos = self::arrowPosition($path, $arrowPos + 2);
//				// if secondArrow is 0 notice
//				if ($secondArrowPos === false) {
//					$tokens[] = array(self::TYPE_PROPERTY, substr($path, $arrowPos + 2));
//					return $tokens;
//				}
//			}
//
			return array_merge($tokens, self::compilePath(substr($path, $arrowPos + 2), self::TYPE_PROPERTY));
		}
		if ($bracketPos !== false && ($dotPos === false || $bracketPos < $dotPos)) {
			// ELEMENT(ARRAY)
			if ($bracketPos !== 0) {
				$tokens[] = array($type, substr($path, 0, $bracketPos));
			}
			$closeBracketPos = self::closeBracketPosition($path, $bracketPos + 1);
			if ($closeBracketPos === false) {
				warning('Unterminated "[", expecting a "]" in path: "'.$path.'"');
				return array(array($type, $path)); // return the entire path as identifier
			}
			$tokens[] = array(self::TYPE_ELEMENT, substr($path, $bracketPos + 1, $closeBracketPos - $bracketPos - 1));
			if ($closeBracketPos + 1 == strlen($path)) { // laatste element?
				return $tokens;
			}
			return array_merge($tokens, self::compilePath(substr($path, $closeBracketPos + 1), self::TYPE_CHAIN));
		}
		// ANY (ARRAY or OBJECT)
		if ($dotPos !== 0) {
			$tokens[] = array($type, substr($path, 0, $dotPos));
		} else {
			notice('Use "." for chaining, not at the beginning', array('path' => $path));
		}
		return array_merge($tokens, self::compilePath(substr($path, $dotPos), self::TYPE_CHAIN));
	}

	// @todo check escaped positions
	private static function dotPosition($path, $offset = null) {
		return strpos($path, '.', $offset);
	}
	private static function arrowPosition($path, $offset = null) {
		return strpos($path, '->', $offset);
	}
	private static function openBracketPosition($path, $offset = null) {
		return strpos($path, '[', $offset);
	}
	private static function closeBracketPosition($path, $offset = null) {
		return strpos($path, ']', $offset);
	}
}

?>
