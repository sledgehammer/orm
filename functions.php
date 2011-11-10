<?php
/**
 * Record functions
 *
 * @package Record
 */
namespace SledgeHammer;

/**
 * Get a Repository by ID
 * This allows instances to reference a Repository by id instead of a full php reference. Keeping the (var_)dump clean.
 *
 * @return \Generated\DefaultRepository
 */
function getRepository($id = 'default') {
	if (isset($GLOBALS['Repositories'][$id])) {
		return $GLOBALS['Repositories'][$id];
	}
	if ($id == 'default') {
		$GLOBALS['Repositories']['default'] = new Repository();
		return $GLOBALS['Repositories']['default'];
	}
	throw new \Exception('Repository: $GLOBALS[\'Repositories\'][\''.$id.'\'] doesn\'t exist');
}
?>
