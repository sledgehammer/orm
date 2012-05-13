<?php
namespace SledgeHammer;
/**
 * ArrayRepositoryBackendTest
 */
class ArrayRepositoryBackendTest extends TestCase{

	function test_get() {
		$repo = $this->getRepo();
		$terminator = $repo->getActor(1);
		$this->assertSame('Arnold Schwarzenegger', $terminator->name);
		$bond = $repo->getActor(0);
		$this->assertSame('Roger Moore', $bond->name);
	}

	function test_all() {
		$repo = $this->getRepo();
		$actors = $repo->allActors();
		$this->assertCount(2, $actors);
		$this->assertSame('Roger Moore', $actors[0]->name);
		$sorted = $actors->orderBy('name');
		$this->assertSame('Arnold Schwarzenegger', $sorted[0]->name);
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
