<?php
/**
 * PropertyPathTests
 * @package Record
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
		$this->assertEqual(PropertyPath::get($array, '->id'), null, 'Path ".id" should NOT work on arrays');
		$this->assertEqual(PropertyPath::get($object, '->id'), '2', 'Path ".id" should work on objects');
		$object->property = array('id' => '3');
		$this->assertEqual(PropertyPath::get($object, 'property[id]'), '3', 'Path "property[id]" should work on objects');
		$this->assertEqual(PropertyPath::get($object, '->property[id]'), '3', 'Path ".property[id]" should work on objects');
		$object->object = (object) array('id' => '4');
		$this->assertEqual(PropertyPath::get($object, 'object->id'), '4', 'Path "object.id" should work on objects in objects');
		$this->assertEqual(PropertyPath::get($object, '->object->id'), '4', 'Path ".object.id" should work on objects in objects');
		$object->property['element'] = (object) array('id' => '5');
		$this->assertEqual(PropertyPath::get($object, 'property[element]id'), '5'); // @todo dont allow this notation?
		$this->assertEqual(PropertyPath::get($object, 'property[element]->id'), '5');
		$this->assertEqual(PropertyPath::get($object, '->property[element]id'), '5'); // @todo idem
		$this->assertEqual(PropertyPath::get($object, '->property[element]->id'), '5');
		$this->expectError('Unexpected type: array, expecting an object');
		$this->assertEqual(PropertyPath::get($object, '->property->element'), null);
		$array['object'] = (object) array('id' => 6);
		$this->assertEqual(PropertyPath::get($array, 'object->id'), 6);
		$this->assertEqual(PropertyPath::get($array, '[object]->id'), 6);
		
	}
	
	function test_PropertyPath_set() {
		restore_error_handler();
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
