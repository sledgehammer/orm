<?php
/**
 * @package ORM
 */
// Global functions inside the Sledgehammer namespace
namespace Sledgehammer;

/**
 * Get a Repository by ID
 * This allows instances to reference a Repository by id instead of a full php reference. Keeping the (var_)dump clean.
 *
 * @param string $id
 * @return \Generated\DefaultRepository|Repository
 */
function getRepository($id = 'default') {
	if ($id instanceof Repository) {
		return $id;
	}
	if (isset(Repository::$instances[$id])) {
		return Repository::$instances[$id];
	}
	if ($id == 'default') {
		Repository::$instances['default'] = new Repository();
		return Repository::$instances['default'];
	}
	throw new \Exception('Repository: \Sledgehammer\Repository::$instances[\''.$id.'\'] doesn\'t exist');
}
?>
