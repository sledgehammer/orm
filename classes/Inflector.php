<?php
/**
 * Inflector
 */
namespace Sledgehammer;
/**
 * The Inflector transforms words from singular to plural and from plural to singular
 *
 * Original code from php-activerecord @link http://www.phpactiverecord.org/ who copied it from RoR's ActiveRecord @link http://api.rubyonrails.org/classes/ActiveSupport/Inflector.html
 *
 * @package ORM
 */
class Inflector extends Object {

	static $plural = array(
		'/(quiz)$/i' => "$1zes",
		'/^(ox)$/i' => "$1en",
		'/([m|l])ouse$/i' => "$1ice",
		'/(matr|vert|ind)ix|ex$/i' => "$1ices",
		'/(x|ch|ss|sh)$/i' => "$1es",
		'/([^aeiouy]|qu)y$/i' => "$1ies",
		'/(hive)$/i' => "$1s",
		'/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
		'/(shea|lea|loa|thie)f$/i' => "$1ves",
		'/sis$/i' => "ses",
		'/([ti])um$/i' => "$1a",
		'/(tomat|potat|ech|her|vet)o$/i' => "$1oes",
		'/(bu)s$/i' => "$1ses",
		'/(alias)$/i' => "$1es",
		'/(octop)us$/i' => "$1i",
		'/(ax|test)is$/i' => "$1es",
		'/(us)$/i' => "$1es",
		'/s$/i' => "s",
		'/$/' => "s"
	);
	static $singular = array(
		'/(quiz)zes$/i' => "$1",
		'/(matr)ices$/i' => "$1ix",
		'/(vert|ind)ices$/i' => "$1ex",
		'/^(ox)en$/i' => "$1",
		'/(alias)es$/i' => "$1",
		'/(octop|vir)i$/i' => "$1us",
		'/(cris|ax|test)es$/i' => "$1is",
		'/(shoe)s$/i' => "$1",
		'/(o)es$/i' => "$1",
		'/(bus)es$/i' => "$1",
		'/([m|l])ice$/i' => "$1ouse",
		'/(x|ch|ss|sh)es$/i' => "$1",
		'/(m)ovies$/i' => "$1ovie",
		'/(s)eries$/i' => "$1eries",
		'/([^aeiouy]|qu)ies$/i' => "$1y",
		'/([lr])ves$/i' => "$1f",
		'/(tive)s$/i' => "$1",
		'/(hive)s$/i' => "$1",
		'/(li|wi|kni)ves$/i' => "$1fe",
		'/(shea|loa|lea|thie)ves$/i' => "$1f",
		'/(^analy)ses$/i' => "$1sis",
		'/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i' => "$1$2sis",
		'/([ti])a$/i' => "$1um",
		'/(n)ews$/i' => "$1ews",
		'/(h|bl)ouses$/i' => "$1ouse",
		'/(corpse)s$/i' => "$1",
		'/(us)es$/i' => "$1",
		'/s$/i' => ""
	);
	static $irregular = array(
		'move' => 'moves',
		'foot' => 'feet',
		'goose' => 'geese',
		'sex' => 'sexes',
		'child' => 'children',
		'man' => 'men',
		'tooth' => 'teeth',
		'person' => 'people'
	);
	static $uncountable = array(
		'sheep',
		'fish',
		'deer',
		'series',
		'species',
		'money',
		'rice',
		'information',
		'equipment'
	);

	/**
	 * Special characters, which are not allowed in class or variable names.
	 * @var string
	 */
	static $specialCharacters = ' +-=!@#$%^&*()<>?\'"[](){}`~\\:;,.';

	/**
	 * Keyords are disallowed as model/class name.
	 * @link http://php.net/manual/en/reserved.keywords.php
	 * @var array
	 */
	static $reservedKeywords = array(
		// PHP Keywords
		'__halt_compiler',
		'abstract',
		'and',
		'array',
		'as',
		'break',
		'callable',
		'case',
		'catch',
		'class',
		'clone',
		'const',
		'continue',
		'declare',
		'default',
		'die',
		'do',
		'echo',
		'else',
		'elseif',
		'empty',
		'enddeclare',
		'endfor',
		'endforeach',
		'endif',
		'endswitch',
		'endwhile',
		'eval',
		'exit',
		'extends',
		'final',
		'for',
		'foreach',
		'function',
		'global',
		'goto',
		'if',
		'implements',
		'include',
		'include_once',
		'instanceof',
		'insteadof',
		'interface',
		'isset',
		'list',
		'namespace',
		'new',
		'or',
		'print',
		'private',
		'protected',
		'public',
		'require',
		'require_once',
		'return',
		'static',
		'switch',
		'throw',
		'trait',
		'try',
		'unset',
		'use',
		'var',
		'while',
		'xor',
		// Compile-time constants (converted to lowercase)
		'__class__',
		'__dir__',
		'__file__',
		'__function__',
		'__line__',
		'__method__',
		'__namespace__',
		'__trait__',
	);

	/**
	 * Returns the plural form of the word in the string
	 *
	 * @param string $singular
	 * @return string
	 */
	static function pluralize($singular) {
		// save some time in the case that singular and plural are the same
		if (in_array(strtolower($singular), self::$uncountable)) {
			return $singular;
		}
		// check for irregular singular forms
		foreach (self::$irregular as $pattern => $result) {
			$pattern = '/'.$pattern.'$/i';
			if (preg_match($pattern, $singular)) {
				return preg_replace($pattern, $result, $singular);
			}
		}

		// check for matches using regular expressions
		foreach (self::$plural as $pattern => $result) {
			if (preg_match($pattern, $singular)) {
				return preg_replace($pattern, $result, $singular);
			}
		}
		return $singular;
	}

	/**
	 * Returns the singular form of a word in a string
	 *
	 * @param string $plural
	 * @return string
	 */
	static function singularize($plural) {
		// save some time in the case that singular and plural are the same
		if (in_array(strtolower($plural), self::$uncountable)) {
			return $plural;
		}
		// check for irregular plural forms
		foreach (self::$irregular as $result => $pattern) {
			$pattern = '/'.$pattern.'$/i';
			if (preg_match($pattern, $plural)) {
				return preg_replace($pattern, $result, $plural);
			}
		}
		// check for matches using regular expressions
		foreach (self::$singular as $pattern => $result) {
			if (preg_match($pattern, $plural)) {
				return preg_replace($pattern, $result, $plural);
			}
		}
		return $plural;
	}

	/**
	 * Returns a valid classname in PascalCase.
	 *
	 *  Examples:
	 *   customer => Customer
	 * when options[singularizeLast] is enabled:
	 *   customers => Customer
	 *   cart_items => CartItem
	 *
	 * @param string $name A (table) name
	 * @param string $options array(
	 *   'stripPrefix' => Remove the given database/table prefix
	 *   'singularizeLast' => (bool) default: true
	 * )
	 * @return string
	 */
	static function modelize($name, $options = array()) {
		$defaults = array(
			'prefix' => '',
			'singularizeLast' => false
		);
		$options = array_merge($defaults, $options);
		if ($options['prefix'] != '' && substr($name, 0, strlen($options['prefix'])) == $options['prefix']) {
			$name = substr($name, strlen($options['prefix'])); // Strip prefix
		}
		// Split into words on "_" and invalid characters, etc. ("ä" etc are allowed in classnames)
		$words = preg_split('/['.preg_quote(self::$specialCharacters.'_').']+/i', $name);
		// Ucfist all the words
		foreach ($words as $i => $word) {
			$words[$i] = ucfirst($word);
		}
		// Singularize the last word
		if ($options['singularizeLast']) {
			$last = count($words) - 1;
			$words[$last] = Inflector::singularize($words[$last]);
		}
		// Merge all the words into 1 string.
		$model = implode('', $words);
		// Append a '_' if the modal is a reserved keyword like "Case", "Final", etc.
		if (in_array(strtolower($model), self::$reservedKeywords)) {
			return $model.'_';
		}
		// Prefix a '_' if the name starts with a number.
		if (preg_match('/^[0-9]/', $model)) { //
			return '_'.$model;
		}
		return $model;
	}

	/**
	 * Returns a valid variable/property name.
	 *
	 * @param string $name  The (column) name
	 * @return string
	 */
	static function variablize($name) {
		$words = preg_split('/['.preg_quote(self::$specialCharacters).']+/i', $name);
		foreach ($words as $i => $word) {
			$words[$i] = ucfirst($word);
		}
		$property = lcfirst(implode('', $words));
		// Prefix a '_' if the name starts with a number.
		if (preg_match('/^[0-9]/', $property)) { //
			return '_'.$property;
		}
		return $property;
	}

	/**
	 * Return as lowercase underscored word.
	 *
	 * CartItem => cart_item
	 * cartItem => cart_item
	 *
	 * @param string $camelCasedWord
	 * @return string underscored_word
	 */
	static function underscore($camelCasedWord) {
		return strtolower(preg_replace('/[A-Z]{1}[a-z][1]/', '_$0', $camelCasedWord));
	}

	/**
	 * Return as CamelCased word.
	 *
	 * cart_item => CartItem
	 * cartItem  => CartItem
	 *
	 * @param string $lowerCaseAndUnderscoredWord
	 * @return string CamelCased word
	 */
	static function camelCase($lowerCaseAndUnderscoredWord) {
		return ucfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $lowerCaseAndUnderscoredWord))));
	}

	/**
	 * Return as camelBack word.
	 *
	 * cart_item => cartItem
	 * cartItem => cartItem
	 *
	 * @param string $lowerCaseAndUnderscoredWord
	 * @return string camelBack word
	 */
	static function camelBack($lowerCaseAndUnderscoredWord) {
		return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $lowerCaseAndUnderscoredWord))));
	}

}

?>
