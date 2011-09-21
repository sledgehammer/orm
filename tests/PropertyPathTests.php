<?php
/**
 * PropertyPathTests
 *
 */
namespace SledgeHammer;
class PropertyPathTests extends \UnitTestCase {
	
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
		$this->assertEqual(PropertyPath::get($array, '.id'), null, 'Path ".id" should NOT work on arrays');
		$this->assertEqual(PropertyPath::get($object, '.id'), '2', 'Path ".id" should work on objects');
		
		$object->property = array('id' => '3');
		$this->assertEqual(PropertyPath::get($object, 'property[id]'), '3', 'Path "property[id]" should work on objects');
		$this->assertEqual(PropertyPath::get($object, '.property[id]'), '3', 'Path ".property[id]" should work on objects');
		$object->object = (object) array('id' => '4');
		$this->assertEqual(PropertyPath::get($object, 'object.id'), '4', 'Path "object.id" should work on objects in objects');
		$this->assertEqual(PropertyPath::get($object, '.object.id'), '4', 'Path ".object.id" should work on objects in objects');
		$object->property['element'] = (object) array('id' => '5');
		$this->assertEqual(PropertyPath::get($object, 'property[element]id'), '5'); // @todo dont allow this notation?
		$this->assertEqual(PropertyPath::get($object, 'property[element].id'), '5');
		$this->assertEqual(PropertyPath::get($object, '.property[element]id'), '5'); // @todo idem
		$this->assertEqual(PropertyPath::get($object, '.property[element].id'), '5');
		$this->expectError('Unexpected type: array, expecting an object');
		$this->assertEqual(PropertyPath::get($object, '.property.element'), null);
		

		


		// 
		// Test escape 
		// '\\[key]\\'
	}
}

?>
