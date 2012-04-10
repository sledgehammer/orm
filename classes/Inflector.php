<?php
/**
 * The Inflector transforms words from singular to plural and from plural to singular
 *
 * Original code from php-activerecord @link http://www.phpactiverecord.org/ who copied it from RoR's ActiveRecord @link http://api.rubyonrails.org/classes/ActiveSupport/Inflector.html
 *
 * @package ORM
 */
namespace SledgeHammer;
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
	 * Create a model name from a plural table name.
	 *
	 * customers => Customer
	 * cart_items => CartItem
	 *
	 * @param string $table
	 * @param string $prefix  Database/table prefix
	 * @return string
	 */
	static function modelize($table, $prefix = '') {
		if ($prefix != '' && substr($table, 0, strlen($prefix)) == $prefix) {
			$table = substr($table, strlen($prefix)); // Strip prefix
		}
		$words = explode('_', $table);
		foreach ($words as $i => $word) {
			$words[$i] = ucfirst($word);
		}
		$last = count($words) - 1;
		$words[$last] = Inflector::singularize($words[$last]);
		return implode('', $words);
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
