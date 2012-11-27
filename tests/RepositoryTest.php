<?php
namespace Sledgehammer;
/**
 * RepositoryTest
 *
 * @package ORM
 */
class RepositoryTest extends DatabaseTestCase {

	private $applicationRepositories;

	/**
	 * @var int Number of queries it takes to inspect the test database (mysql: 5, sqlite: 11)
	 */
	private $queryCountAfterInspectDatabase;

	public function __construct() {
		parent::__construct();
		DatabaseRepositoryBackend::$cacheTimeout = false; // always inspect database
		if ($this->getDatabase()->getAttribute(\PDO::ATTR_DRIVER_NAME) == 'mysql') {
			$this->queryCountAfterInspectDatabase = 6;
		} else {
			$this->queryCountAfterInspectDatabase = 11;
		}
	}

	function setUp() {
		parent::setUp();
		if (isset(Repository::$instances)) {
			$this->applicationRepositories = Repository::$instances;
		}
	}

	/**
	 *
	 * @param Sledgehammer\Database $db
	 */
	public function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(\PDO::ATTR_DRIVER_NAME).'.sql', $error);
	}

	public function tearDown() {
		parent::tearDown();
		Repository::$instances = $this->applicationRepositories;
	}

	function test_inspectDatabase() {
		$repo = new RepositoryTester();
		$this->assertQueryCount(0, 'No queries on contruction');
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
//		$this->assertQuery('SHOW TABLES'); // sqlite and mysql use different queries
		$queryCount = $this->queryCountAfterInspectDatabase;
		$this->assertQueryCount($queryCount, 'Sanity check');
		$this->assertTrue($repo->isConfigured('Customer'));
		$this->assertTrue($repo->isConfigured('Order'));
		$this->assertQueryCount($queryCount, 'no additional queries');
	}

	function test_getWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$customer1 = $repo->getCustomer(1);
		$this->assertEquals('Bob Fanger', $customer1->name);
		$this->assertEquals('Software ontwikkelaar', $customer1->occupation);
		$order1 = $repo->getOrder(1);
		$this->assertEquals('Kop koffie', $order1->product);
	}

	function test_oneWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$bob = $repo->oneCustomer(array('name' => 'Bob Fanger'));
		$this->assertEquals('1', $bob->id);

		try {
			$bob = $repo->oneCustomer(array('id >=' => '0'));
			$this->fail('A one critery should return only 1 instance or throw an exception');
		} catch (\Exception $e) {
			$this->assertEquals('More than 1 "Customer" model matches the conditions', $e->getMessage());
		}
	}

	function test_customer_not_found_exception() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		$this->setExpectedException('Exception', 'Record "id = \'-1\'" doesn\'t exist in "customers"');
		@$repo->getCustomer('-1'); // Invalid/not-existing ID
	}

	function test_detect_id_truncation() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$customer1 = $repo->getCustomer(1);
		if ($this->getDatabase()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
			$this->markTestSkipped('SQLite doesn\'t truncate values');
		}
		$this->setExpectedException('Exception', 'The $id parameter doesn\'t match the retrieved data. {1s} != {1}');
		$customer1s = $repo->getCustomer('1s');
	}

	function test_getRepository_function() {
		$repo = getRepository(); // get an Empty (master) repository
		$this->assertFalse($repo->isConfigured('Customer'), 'Sanity check');
		try {
			$repo->getCustomer(1);
			$this->fail('An Exception should be thrown');
		} catch (\Exception $e) {
			$this->assertEquals($e->getMessage(), 'Unknown model: "Customer"', 'Repository should be empty');
		}
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		$this->assertTrue($repo->isConfigured('Customer'), 'Sanity check');

		$sameRepo = getRepository();
		$this->assertTrue($sameRepo === $repo, 'a second getRepository() call should return the same repository');
		// test_AutoGenerated class
		$repo = getRepository();
		$customer = $repo->getCustomer(1);
		$this->setExpectedException('PHPUnit_Framework_Error_Warning', 'Property "superpowers" doesn\'t exist in a Generated\Customer object'); // Show an notice when setting a non-existing property
		$customer->superpowers = true;
	}

	function test_belongsTo() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		$order2 = $repo->getOrder(2);
		$clone = clone $order2->customer;
		$this->assertLastQuery("SELECT * FROM orders WHERE id = '2'");
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'A get*() should execute max 1 query');
		$this->assertEquals($order2->product, 'Walter PPK 9mm');
		$this->assertEquals(get_class($order2->customer), 'Sledgehammer\BelongsToPlaceholder', 'The customer property should be an placeholder');
		$this->assertEquals($order2->customer->id, "2");
		$this->assertEquals(get_class($order2->customer), 'Sledgehammer\BelongsToPlaceholder', 'The placeholder should handle the "id" property');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$this->assertEquals($order2->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery("SELECT * FROM customers WHERE id = '2'");
		$this->assertFalse($order2->customer instanceof BelongsToPlaceholder, 'The placeholder should be replaced with a real object');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$order3 = $repo->getOrder(3);
		$this->assertFalse($order3->customer instanceof BelongsToPlaceholder, 'A loaded instance should be injected directly into the container object');
		$this->assertEquals($order3->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery("SELECT * FROM orders WHERE id = '3'");
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 3, 'No customer queries'); //

		$this->setExpectedException('PHPUnit_Framework_Error_Notice', 'This placeholder belongs to an other object');
		$clone->name = 'Clone';
	}

	function test_allWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$customers = $repo->allCustomers();
		$this->assertQueryCount($this->queryCountAfterInspectDatabase, 'Delay queries until collections access');
		$customerArray = iterator_to_array($customers);
		$this->assertEquals(count($customerArray), 2, 'Collection should contain all customers');
		$this->assertEquals($customerArray[0]->name, 'Bob Fanger');
		$this->assertEquals($customerArray[1]->name, 'James Bond');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Sanity check');

		$counter = 0;
		foreach ($customers as $customer) {
			$counter++;
		}
		foreach ($customers as $customer) {
			$counter++;
		}
		$this->assertEquals($counter, (2 * 2), '$collection->rewind() works as expected');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Use only 1 query for multiple loops on all customers');
		$this->assertLastQuery('SELECT * FROM customers');

		$names = $repo->allCustomers()->select('name')->toArray();
		$this->assertEquals(array(
			'Bob Fanger',
			'James Bond',
			), $names);
		$this->assertLastQuery('SELECT name FROM customers');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2, 'Bypass repository for additional performance');
		$struct = $repo->allCustomers()->select(array('name', 'occupation'), 'id')->toArray();
		$this->assertLastQuery('SELECT id, name, occupation FROM customers');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 3, 'Bypass repository for additional performance');
	}

	function test_hasManyIteratorInterface() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		// Test iterator
		$c1 = $repo->getCustomer(1);
		$this->assertTrue((gettype($c1->orders) == 'object' && get_class($c1->orders) == 'Sledgehammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		foreach ($c1->orders as $order) {
			$this->assertEquals($order->product, 'Kop koffie', 'Only 1 order expected');
		}
		$this->assertLastQuery("SELECT * FROM orders WHERE customer_id = 1");
		$this->assertInstanceOf('Sledgehammer\Collection', $c1->orders, 'The orders property should be replaced with an Collection');
		$this->assertEquals($c1->orders[0]->product, 'Kop koffie', 'Contents should match the order from customer 1');
		$this->assertEquals(count($c1->orders), 1, 'Should only contain the order from customer 1');

		// Test count
		$c2 = $repo->getCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'Sledgehammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		$this->assertEquals(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');
	}

	function test_hasManyArrayAccessInterface() {
		// Test array access
		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'Sledgehammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		$this->assertEquals($c2->orders[0]->product, 'Walter PPK 9mm', 'Get by array offset 0');
		$this->assertEquals($c2->orders[1]->product, 'Spycam', 'Get by array offset 1');
		$this->assertEquals(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');


		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'Sledgehammer\HasManyPlaceholder'), 'Sainity check');
		$this->assertTrue(isset($c2->orders[1]), 'array offset exists');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');

		$c2 = $this->getDirtyCustomer(2);
		$this->assertFalse(isset($c2->orders[3]), 'array offset doesn\'t exist');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');

		$c2 = $this->getDirtyCustomer(2);
		$c2->orders[0] = 'test';
		$this->assertEquals($c2->orders[0], 'test', 'Set by array offset');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');

		$c2 = $this->getDirtyCustomer(2);
		$clone = clone $c2;
		unset($c2->orders[0]);
		$this->assertEquals(count($c2->orders), 1, 'Unset by array offset');
		$this->assertInstanceOf('Sledgehammer\Collection', $c2->orders, 'The orders property should be replaced with an Collection');

		$this->setExpectedException('PHPUnit_Framework_Error_Notice', 'This placeholder is already replaced');
		$this->assertEquals($clone->orders[1]->product, 'Spycam');
//		$this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
	}

	function test_getWildcard_preload() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$order = $repo->getOrder(2, array('preload' => true));
		$this->assertFalse($order->customer instanceof BelongsToPlaceholder, 'Should not be a BelongsToPlaceholder');
		$this->assertInstanceOf('Sledgehammer\Collection', $order->customer->orders, 'Should not be a HasManyPlaceholder');
		$this->assertInstanceOf('Sledgehammer\Collection', $order->customer->groups[0]->customers, 'Should not be a HasManyPlaceholder');
	}

	function test_removeWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$order1 = $repo->getOrder(1);
		// remove by instance
		$repo->deleteOrder($order1);
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2);
		$this->assertLastQuery('DELETE FROM orders WHERE id = 1');
		// remove by id
		$repo->deleteOrder('2');
		$this->assertLastQuery('DELETE FROM orders WHERE id = 2');
	}

	function test_saveWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		$c1 = $repo->getCustomer(1);
		$repo->saveCustomer($c1);

		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Saving an unmodified instance shouldn\'t generate a query');
		$c1->occupation = 'Webdeveloper';
		$repo->saveCustomer($c1);
		$this->assertLastQuery("UPDATE customers SET occupation = 'Webdeveloper' WHERE id = 1");
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2, 'Sanity Check');
		$repo->saveCustomer($c1); // Check if the updated data is now bound to the instance
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2, 'Saving an unmodified instance shouldn\'t generate a query');

		$order2 = $repo->getOrder(2);
		$repo->saveOrder($order2); // Don't autoload belongTo properties
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 3, 'Saving an unmodified instance shouldn\'t generate a query');
		try {
			$order2->customer->id = 1; // Changes the id inside the customer object.
			$repo->saveOrder($order2);
			$this->fail('Dangerous change should throw an Exception');
		} catch (\Exception $e) {
			$this->assertEquals($e->getMessage(), 'Change rejected, the index changed from {2} to {1}');
			// @todo check if the message indicated the id-change
		}
		$repo->validate();
		$order2->customer->id = "2"; // restore customer object
		$repo->saveOrder($order2); // The belongTo is autoloaded, but unchanged
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 4, 'Saving an unmodified instance shouldn\'t generate a query');

		$c2 = $repo->getCustomer(2);
		$this->assertEquals($c2->orders[0]->product, 'Walter PPK 9mm', 'Sanity check');
		$c2->orders[0]->product = 'Walther PPK'; // correct spelling
		$c2->orders[] = $repo->createOrder(array('product' => 'Scuba gear'));
		unset($c2->orders[1]);
		$repo->saveCustomer($c2);
		$this->assertQuery("UPDATE orders SET product = 'Walther PPK' WHERE id = 2");
		$this->assertQuery("INSERT INTO orders (customer_id, product) VALUES (2, 'Scuba gear')");
		$this->assertQuery('DELETE FROM orders WHERE id = 3');
		$this->assertEquals($c2->orders[2]->id, '4', 'The id of the instance should be the "lastInsertId()"');
	}

	function test_reloadWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

		// test reloadModal
		$c1 = $repo->getCustomer(1);
		$c1->name = "Arnold Schwarzenegger";
		try {
			$repo->reloadCustomer(1);
			$this->fail('When reloading a changed instance, an exception should be thrown');
		} catch (\Exception $e) {
			$this->assertTrue(true, 'When reloading a changed instance, an exception should be thrown');
		}
		$repo->reloadCustomer(1, array('discard_changes' => true));
		$this->assertEquals($c1->name, 'Bob Fanger');
		// test reloadPlural
		$c1->name = "Arnold Schwarzenegger";
		$c2 = $repo->getCustomer(2);
		$c2->name = "John Connor";
		try {
			$repo->reloadCustomers();
			$this->fail('When reloading a changed instance, an exception should be thrown');
		} catch (\Exception $e) {
			$this->assertTrue(true, 'When reloading a changed instance, an exception should be thrown');
		}
		$repo->reloadCustomers(array('discard_changes' => true));
		$this->assertEquals($c1->name, 'Bob Fanger');
		$this->assertEquals($c2->name, 'James Bond');
	}

	function test_AutoCompleteHelper() {
		$repoBase = new Repository();
		$repoBase->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		$filename = TMP_DIR.'Test_AutoCompleteRepository.php';
		$class = 'AutoCompleteTestRepository';
		$repoBase->writeAutoCompleteHelper($filename, $class);
		include($filename);
		$methods = array_diff(get_public_methods($class), get_public_methods('Sledgehammer\Repository'));
		sort($methods);
		$this->assertEquals($methods, array(
			'allCustomers',
			'allGroups',
			'allOrders',
			'createCustomer',
			'createGroup',
			'createOrder',
			'deleteCustomer',
			'deleteGroup',
			'deleteOrder',
			'getCustomer',
			'getGroup',
			'getOrder',
			'oneCustomer',
			'oneGroup',
			'oneOrder',
			'reloadCustomer',
			'reloadCustomers',
			'reloadGroup',
			'reloadGroups',
			'reloadOrder',
			'reloadOrders',
			'saveCustomer',
			'saveGroup',
			'saveOrder',
		));
		$repo = new \AutoCompleteTestRepository();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink)); // @todo? Write serialized backends into AutoGenerated class?

		$c1 = $repo->getCustomer(1);
		$this->assertEquals($c1->name, 'Bob Fanger');
		$c1->name = 'Charlie Fanger';
		$repo->saveCustomer($c1);
		$this->assertLastQuery("UPDATE customers SET name = 'Charlie Fanger' WHERE id = 1");
		$c1->orders = array();
		$c1->ratings = array();
		$c1->groups = array();
		$repo->saveCustomer($c1);
		$repo->deleteCustomer($c1);
		$this->assertLastQuery('DELETE FROM customers WHERE id = 1');
	}

	function test_missing_properties() {
		$php = 'class CustomerMissingAProperty extends Sledgehammer\Object {';
		$php .= 'public $id;';
		$php .= 'public $name;';
//		$php .= 'public $occupation;'; the missing property
		$php .= 'public $orders;';
		$php .= 'public $groups;';
		$php .= '}';
		eval($php);

		$backend = new DatabaseRepositoryBackend($this->dbLink);
		$backend->configs['Customer']->class = 'CustomerMissingAProperty';
		$repo = new Repository();
		$repo->registerBackend($backend);
		try {
			$repo->getCustomer(1);
			$this->fail('The missing property should have given a notice.');
		} catch (\Exception $e) {
			$this->assertEquals('Property "occupation" doesn\'t exist in a CustomerMissingAProperty object', $e->getMessage(), $e->getMessage());
		}
	}

	function test_missing_column() {
		$php = 'class CustomerWithAnExtraProperty extends Sledgehammer\Object {';
		$php .= 'public $id;';
		$php .= 'public $name;';
		$php .= 'public $occupation;';
		$php .= 'public $orders;';
		$php .= 'public $groups;';
		$php .= 'public $extra;'; // The extra property / missing column
		$php .= '}';
		eval($php);

		$backend = new DatabaseRepositoryBackend($this->dbLink);
		$backend->configs['Customer']->class = 'CustomerWithAnExtraProperty';
		$repo = new Repository();
		$repo->registerBackend($backend);
		try {
			$repo->getCustomer(1);
			$this->fail('The additional property/missing column should have given a notice.');
		} catch (\Exception $e) {
			$this->assertEquals('Missing mapping for property: \CustomerWithAnExtraProperty->extra', $e->getMessage(), $e->getMessage());
		}
	}

	function test_export() {
		$repo = new Repository();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		$c1 = $repo->getCustomer(1);
		$jsonDeep = json_encode($repo->export('Customer', $c1, true));
		$this->assertEquals('{"id":"1","name":"Bob Fanger","occupation":"Software ontwikkelaar","orders":[{"id":"1","product":"Kop koffie"}],"groups":[{"id":"1","title":"Hacker"}],"ratings":[{"id":"1","title":"Hacker","rating":"5"}]}', $jsonDeep);
		$jsonShallow = json_encode($repo->export('Customer', $c1, 0));
		$this->assertEquals('{"id":"1","name":"Bob Fanger","occupation":"Software ontwikkelaar"}', $jsonShallow);
	}

	function test_create_with_defaults() {
		$repo = new Repository();
		$backend = new DatabaseRepositoryBackend($this->dbLink);
		$backend->configs['Order']->defaults['product'] = 'Untitled';
		$backend->configs['Order']->belongsTo['customer']['default'] = 1;
		$repo->registerBackend($backend);
		$order = $repo->create('Order');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase);
		$this->assertEquals($order->customer->id, 1);
		$this->assertQueryCount($this->queryCountAfterInspectDatabase, 'Uses a BelongToPlaceholder (no queries)');
		$this->assertEquals($order->customer->name, 'Bob Fanger');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'but queries the db when needed.');
	}

	/**
	 * Get a Customer instance where all the properties are still placeholders
	 * (Slow/Expensive operation, initializes a new Repository on every call)
	 *
	 * @param string $id
	 * @return stdClass
	 */
	private function getDirtyCustomer($id) {
		$repo = new Repository();
		$repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
		return $repo->getCustomer($id);
	}

}

?>
