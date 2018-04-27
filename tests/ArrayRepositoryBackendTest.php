<?php

namespace SledgehammerTests\Orm;

use Sledgehammer\Core\Debug\ErrorHandler;
use Sledgehammer\Orm\Backend\ArrayRepositoryBackend;
use Sledgehammer\Orm\ModelConfig;
use Sledgehammer\Orm\Repository;
use SledgehammerTests\Core\TestCase;

/**
 * ArrayRepositoryBackendTest.
 */
class ArrayRepositoryBackendTest extends TestCase
{
    public function test_get()
    {
        $repo = $this->getRepo();
        $bond = $repo->getActor(0);
        $this->assertSame('Roger Moore', $bond->name);
        $terminator = $repo->getActor(1);
        $this->assertSame('Arnold Schwarzenegger', $terminator->name);
    }

    public function test_all()
    {
        $repo = $this->getRepo();
        $actors = $repo->allActors();
        $this->assertCount(2, $actors);
        $this->assertSame('Roger Moore', $actors[0]->name);
        $sorted = $actors->orderBy('name');
        $this->assertSame('Arnold Schwarzenegger', $sorted[0]->name);
    }

    public function test_update()
    {
        $repo = $this->getRepo();
        $bond = $repo->getActor(0);
        $bond->name = 'Daniel Craig';
        $repo->saveActor($bond);
        $repo->reloadActor($bond);
        $this->assertSame($bond->name, 'Daniel Craig');
    }

    public function test_add()
    {
        ErrorHandler::enable();
        $repo = $this->getRepo();
        $neo = $repo->createActor(['name' => 'Keanu Reeves']);
        $repo->saveActor($neo);
        $this->assertSame(2, $neo->id);
    }

    private function getRepo()
    {
        $backend = new ArrayRepositoryBackend(new ModelConfig('Actor'), [
            [
                'name' => 'Roger Moore',
            ],
            [
                'name' => 'Arnold Schwarzenegger',
            ],
        ]);
        $repo = new Repository();
        $repo->registerBackend($backend);

        return $repo;
    }
}
