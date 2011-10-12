<?php
/**
 * PropertyPathTests
 * @package Record
 */
namespace SledgeHammer;

class PropertyPathTests extends \UnitTestCase {

	function test_compile() {
		$any = PropertyPath::TYPE_ANY;
		$element = PropertyPath::TYPE_ELEMENT;
		$property = PropertyPath::TYPE_PROPERTY;

		$this->assertEqual(PropertyPath::compile('any'), array(array($any, 'any')));
		$this->assertEqual(PropertyPath::compile('any1.any2'), array(array($any, 'any1'), array($any, 'any2')));
		$this->assertEqual(PropertyPath::compile('[element]'), array(array($element, 'element')));
		$this->assertEqual(PropertyPath::compile('any[element]'), array(array($any, 'any'), array($element, 'element')));
		$this->assertEqual(PropertyPath::compile('[element1][element2]'), array(array($element, 'element1'), array($element, 'element2')));

		$this->assertEqual(PropertyPath::compile('->property'), array(array($property, 'property')));
		$this->assertEqual(PropertyPath::compile('any->property'), array(array($any, 'any'), array($property, 'property')));
		$this->assertEqual(PropertyPath::compile('->property1->property2'), array(array($property, 'property1'), array($property, 'property2')));

		$this->assertEqual(PropertyPath::compile('[element]->property'), array(array($element, 'element'), array($property, 'property')));
		$this->assertEqual(PropertyPath::compile('any[element]->property'), array(array($any, 'any'), array($element, 'element'), array($property, 'property')));
		$this->assertEqual(PropertyPath::compile('[element]->property.any'), array(array($element, 'element'), array($property, 'property'), array($any, 'any')));
		$this->assertEqual(PropertyPath::compile('->property[element]'), array(array($property, 'property'), array($element, 'element')));
		$this->assertEqual(PropertyPath::compile('any->property[element]'), array(array($any, 'any'),  array($property, 'property'), array($element, 'element')));
		$this->assertEqual(PropertyPath::compile('->property[element].any'), array(array($property, 'property'), array($element, 'element'), array($any, 'any')));
	}

	function test_compile_warnings() {
		$any = PropertyPath::TYPE_ANY;
		$element = PropertyPath::TYPE_ELEMENT;
		$property = PropertyPath::TYPE_PROPERTY;
		$this->expectError('Path is empty');
		$this->assertEqual(PropertyPath::compile(''), array());
		$this->expectError('Use "." for chaining, not at the beginning');
		$this->assertEqual(PropertyPath::compile('.any'), array(array($any, 'any')));
		$this->expectError('Invalid chain, expecting a ".", "->" or "[" before "any"');
		$this->assertEqual(PropertyPath::compile('[element]any'), array(array($element, 'element'), array($any, 'any')));
		$this->expectError('Invalid "->" in in the chain');
		$this->assertEqual(PropertyPath::compile('->->property'), array(array($property, 'property')));
		$this->expectError('Unmatched brackets, missing a "]" in path: "[element"');
		$this->assertEqual(PropertyPath::compile('[element'), array(array($any, '[element')));
	}

	function test_PropertyPath_get() {
		$array = array('id' => '1');
		$object = (object) array('id' => '2');
		// autodetect type
		$this->assertEqual(PropertyPath::get($array, 'id'), '1', 'Path "id" should work on arrays');
		$this->assertEqual(PropertyPath::get($object, 'id'), '2', 'Path "id" should also work on objects');
		// force array element
		$this->assertEqual(PropertyPath::get($array, '[id]'), '1', 'Path "[id]" should work on arrays');
		$this->expectError('Unexpected type: object, expecting an array');
		$this->assertEqual(PropertyPath::get($object, '[id]'), null, 'Path "[id]" should NOT work on objects');
		// force object property
		$this->expectError('Unexpected type: array, expecting an object');
		$this->assertEqual(PropertyPath::get($array, '->id'), null, 'Path "->id" should NOT work on arrays');
		$this->assertEqual(PropertyPath::get($object, '->id'), '2', 'Path "->id" should work on objects');
		$object->property = array('id' => '3');
		$this->assertEqual(PropertyPath::get($object, 'property[id]'), '3', 'Path "property[id]" should work on objects');
		$this->assertEqual(PropertyPath::get($object, '->property[id]'), '3', 'Path "->property[id]" should work on objects');
		$object->object = (object) array('id' => '4');
		$this->assertEqual(PropertyPath::get($object, 'object->id'), '4', 'Path "object->id" should work on objects in objects');
		$this->assertEqual(PropertyPath::get($object, '->object->id'), '4', 'Path "->object->id" should work on objects in objects');
		$object->property['element'] = (object) array('id' => '5');
//		$this->assertEqual(PropertyPath::get($object, 'property[element]id'), '5'); // @todo dont allow this notation?
		$this->assertEqual(PropertyPath::get($object, 'property[element]->id'), '5');
//		$this->assertEqual(PropertyPath::get($object, '->property[element]id'), '5'); // @todo idem
		$this->assertEqual(PropertyPath::get($object, '->property[element]->id'), '5');
		$this->expectError('Unexpected type: array, expecting an object');
		$this->assertEqual(PropertyPath::get($object, '->property->element'), null);
		$array['object'] = (object) array('id' => 6);
		$this->assertEqual(PropertyPath::get($array, 'object->id'), 6);
		$this->assertEqual(PropertyPath::get($array, '[object]->id'), 6);

	}

	function test_PropertyPath_set() {
		$array = array('id' => '1');
		$object = (object) array('id' => '2');
		PropertyPath::set($array, 'id', 3);
		$this->assertEqual($array['id'], 3);
		PropertyPath::set($object, 'id', 4);
		$this->assertEqual($object->id, 4);
		PropertyPath::set($object, '->id', 5);
		$this->assertEqual($object->id, 5);
		PropertyPath::set($array, '[id]', 6);
		$this->assertEqual($array['id'], 6);
		$array['object'] = (object) array('id' => 7);
		PropertyPath::set($array, 'object->id', 8);
		$this->assertEqual($array['object']->id, 8);
		PropertyPath::set($array, '[object]->id', 9);
		$this->assertEqual($array['object']->id, 9);
		$array['element'] = array('id' => 1);
		PropertyPath::set($array, 'element[id]', 10);
		$this->assertEqual($array['element']['id'], 10);



	}



}

?>
