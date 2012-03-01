<?php
/**
 * Record functions
 *
 * @package ORM
 */
namespace SledgeHammer;

/**
 * Get a Repository by ID
 * This allows instances to reference a Repository by id instead of a full php reference. Keeping the (var_)dump clean.
 *
 * @return \Generated\DefaultRepository|Repository
 */
function getRepository($id = 'default') {
	if (isset(Repository::$instances[$id])) {
		return Repository::$instances[$id];
	}
	if ($id == 'default') {
		Repository::$instances['default'] = new Repository();
		return Repository::$instances['default'];
	}
	throw new \Exception('Repository: \SledgeHammer\Repository::$instances[\''.$id.'\'] doesn\'t exist');
}
?>
