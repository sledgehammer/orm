<?php

namespace Sledgehammer;

use Sledgehammer;

/**
 * Get a Repository by ID
 * This allows instances to reference a Repository by id instead of a full php reference. Keeping the (var_)dump clean.
 *
 * @param string $id
 *
 * @return \Generated\DefaultRepository|Repository
 */
function getRepository($id = 'default')
{
    \Sledgehammer\deprecated('Sledgehammer\getRepository() is deprecated in favor of Repository::instance()');

    return Repository::instance($id);
}
