<?php

/**
 * A Repository with additional validation.
 */

namespace SledgehammerTests\Orm\Support;

use Sledgehammer\Orm\Repository;

class RepositoryTester extends Repository
{
    public $autoValidation = true;

    public function validate()
    {
        $this->validateObjects();
    }

    public function validateObjects()
    {
        $validStates = array('new', 'retrieved', 'saved', 'deleted');
        foreach ($this->objects as $model => $objects) {
            foreach ($objects as $index => $object) {
                if (in_array($object['state'], $validStates) == false) {
                    warning('Invalid state "'.$object['state'].'" for '.$model.' '.$index);
                }
            }
        }
    }

    public function __destruct()
    {
        if ($this->autoValidation) {
            $this->validate();
        }
    }
}
