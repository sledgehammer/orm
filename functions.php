<?php
namespace SledgeHammer;

/**
 * Get a Repository by ID
 * This allows instances to reference a Repository by id instead of a full php reference. Keeping the (var_)dump clean. 
 *
 * @return Repository 
 */
function getRepository($id = 'master') {
	if (isset($GLOBALS['Repositories'][$id])) {
		return $GLOBALS['Repositories'][$id];
	}
	if ($id == 'master') {
		$GLOBALS['Repositories']['master'] =  new Repository();
		return $GLOBALS['Repositories']['master'];
	}
	throw new \Exception('Repository: $GLOBALS[\'Repositories\'][\''.$id.'\'] doesn\'t exist');
}
?>
