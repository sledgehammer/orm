<?php
namespace Sledgehammer;
/**
 * ArrayRepositoryBackendTest
 */
class ArrayRepositoryBackendTest extends TestCase{

	function test_get() {
		$repo = $this->getRepo();
		$bond = $repo->getActor(0);
		$this->assertSame('Roger Moore', $bond->name);
		$terminator = $repo->getActor(1);
		$this->assertSame('Arnold Schwarzenegger', $terminator->name);
	}

	function test_all() {
		$repo = $this->getRepo();
		$actors = $repo->allActors();
		$this->assertCount(2, $actors);
		$this->assertSame('Roger Moore', $actors[0]->name);
		$sorted = $actors->orderBy('name');
		$this->assertSame('Arnold Schwarzenegger', $sorted[0]->name);
	}

	function test_update() {
		$repo = $this->getRepo();
		$bond = $repo->getActor(0);
 		$bond->name = 'Daniel Craig';
		$repo->saveActor($bond);
		$repo->reloadActor($bond);
		$this->assertSame($bond->name, 'Daniel Craig');
	}

	function test_add() {
		Framework::$errorHandler->init();
		$repo = $this->getRepo();
		$neo = $repo->createActor(array('name' => 'Keanu Reeves'));
		$repo->saveActor($neo);
		$this->assertSame(2, $neo->id);
	}

	private function getRepo() {
		$backend = new ArrayRepositoryBackend(new ModelConfig('Actor'), array(
			array(
				'name' => 'Roger Moore',
			),
			array(
				'name' => 'Arnold Schwarzenegger',
			),
		));
		$repo = new Repository();
		$repo->registerBackend($backend);
		return $repo;


	}
}

?>
