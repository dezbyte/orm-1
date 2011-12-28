<?php
/**
 * RepositoryTest
 */
namespace SledgeHammer;

class RepositoryTests extends DatabaseTestCase {

	private $applicationRepositories;

	/**
	 * @var int Number of queries it takes to inspect the test database (mysql: 3, sqlite: 5)
	 */
	private $queryCountAfterInspectDatabase;

	public function __construct() {
		parent::__construct('sqlite');
//		parent::__construct('mysql');
		if ($this->getDatabase()->getAttribute(\PDO::ATTR_DRIVER_NAME) == 'mysql') {
			$this->queryCountAfterInspectDatabase = 3;
		} else {
			$this->queryCountAfterInspectDatabase = 5;
		}
	}

	function setUp() {
		parent::setUp();
		if (isset($GLOBALS['Repositories'])) {
			$this->applicationRepositories = $GLOBALS['Repositories'];
		}
	}

	/**
	 *
	 * @param SledgeHammer\Database $db
	 */
	public function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(\PDO::ATTR_DRIVER_NAME).'.sql', $error);
	}

	public function tearDown() {
		parent::tearDown();
		$GLOBALS['Repositories'] = $this->applicationRepositories;
	}

	function test_inspectDatabase() {
		$repo = new RepositoryTester();
		$this->assertQueryCount(0, 'No queries on contruction');
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
//		$this->assertQuery('SHOW TABLES'); // sqlite and mysql use different queries
		$queryCount = $this->queryCountAfterInspectDatabase;
		$this->assertQueryCount($queryCount, 'Sanity check');
		$this->assertTrue($repo->isConfigured('Customer'));
		$this->assertTrue($repo->isConfigured('Order'));
		$this->assertQueryCount($queryCount, 'no additional queries');
	}

	function test_getWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		$customer1 = $repo->getCustomer(1);
		$this->assertEqual($customer1->name, "Bob Fanger");
		$this->assertEqual($customer1->occupation, "Software ontwikkelaar");
		$order1 = $repo->getOrder(1);
		$this->assertEqual($order1->product, 'Kop koffie');

		$driver = $this->getDatabase()->getAttribute(\PDO::ATTR_DRIVER_NAME);

		// Invalid/not-existing ID
		try {
			$this->expectError('Row not found');
			$repo->getCustomer('-1');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Failed to retrieve "id = \'-1\'" from "customers"');
		}
		// id truncation
		try {
			if ($driver == 'sqlite') {
				$this->expectError('Row not found');
			}
			$customer1s = $repo->getCustomer('1s');
			if ($customer1s !== $customer1) {
				$this->fail('id was truncated, but not detected');
			} else {
				$this->fail('id was truncated, but index was corrected');
			}
		} catch (\Exception $e) {
			if ($driver === 'mysql') {
				$this->assertEqual($e->getMessage(), 'The $id parameter doesn\'t match the retrieved data. {1s} != {1}');
			} else {
				$this->assertEqual($e->getMessage(), 'Failed to retrieve "id = \'1s\'" from "customers"');
			}
		}
	}

	function test_getRepository_function() {
		$repo = getRepository(); // get an Empty (master) repository
		$this->assertFalse($repo->isConfigured('Customer'), 'Sanity check');
		try {
			$repo->getCustomer(1);
			$this->fail('An Exception should be thrown');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Unknown model: "Customer"', 'Repository should be empty');
		}
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		$this->assertTrue($repo->isConfigured('Customer'), 'Sanity check');

		$sameRepo = getRepository();
		$this->assertTrue($sameRepo === $repo, 'a second getRepository() call should return the same repository');
		// test_AutoGenerated class
		$repo = getRepository();
		$customer = $repo->getCustomer(1);
		$this->expectError('Property: "superpowers" doesn\'t exist in a "Generated\Customer" object.', 'Show an notice when setting a non-existing property');
		$customer->superpowers = true;
	}

	function test_belongsTo() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		$order2 = $repo->getOrder(2);
		$clone = clone $order2;
		$this->assertLastQuery('SELECT * FROM orders WHERE id = 2');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'A get*() should execute max 1 query');
		$this->assertEqual($order2->product, 'Walter PPK 9mm');
		$this->assertEqual(get_class($order2->customer), 'SledgeHammer\BelongsToPlaceholder', 'The customer property should be an placeholder');
		$this->assertEqual($order2->customer->id, "2");
		$this->assertEqual(get_class($order2->customer), 'SledgeHammer\BelongsToPlaceholder', 'The placeholder should handle the "id" property');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$this->assertEqual($order2->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery('SELECT * FROM customers WHERE id = 2');
		$this->assertFalse($order2->customer instanceof BelongsToPlaceholder, 'The placeholder should be replaced with a real object');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 2, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$order3 = $repo->getOrder(3);
		$this->assertFalse($order3->customer instanceof BelongsToPlaceholder, 'A loaded instance should be injected directly into the container object');
		$this->assertEqual($order3->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery('SELECT * FROM orders WHERE id = 3');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 3, 'No customer queries'); //

		$this->expectError('This placeholder belongs to an other (cloned?) container');
		$this->assertEqual($clone->customer->name, 'James Bond');
		//	$this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
	}

	function test_allWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		$customers = $repo->allCustomers();
		$this->assertQueryCount($this->queryCountAfterInspectDatabase, 'Delay queries until collections access');
		$this->assertEqual(count($customers), 2, 'Collection should contain all customers');
		$customerArray = iterator_to_array($customers);
		$this->assertEqual($customerArray[0]->name, 'Bob Fanger');
		$this->assertEqual($customerArray[1]->name, 'James Bond');

		$counter = 0;
		foreach ($customers as $customer) {
			$counter++;
		}
		foreach ($customers as $customer) {
			$counter++;
		}
		$this->assertEqual($counter, (2 * 2), '$collection->rewind() works as expected');
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 1, 'Use only 1 query for multiple loops on all customers');
		$this->assertLastQuery('SELECT * FROM customers');
	}

	function test_hasManyIteratorInterface() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		// Test iterator
		$c1 = $repo->getCustomer(1);
		$this->assertTrue((gettype($c1->orders) == 'object' && get_class($c1->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		foreach ($c1->orders as $order) {
			$this->assertEqual($order->product, 'Kop koffie', 'Only 1 order expected');
		}
		$this->assertLastQuery('SELECT * FROM orders WHERE customer_id = 1');
		$this->assertIsA($c1->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');
		$this->assertEqual($c1->orders[0]->product, 'Kop koffie', 'Contents should match the order from customer 1');
		$this->assertEqual(count($c1->orders), 1, 'Should only contain the order from customer 1');

		// Test count
		$c2 = $repo->getCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		$this->assertEqual(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');

	}

	function test_hasManyArrayAccessInterface() {
		// Test array access
		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');

		$this->assertEqual($c2->orders[0]->product, 'Walter PPK 9mm', 'Get by array offset');
		$this->assertEqual($c2->orders[1]->product, 'Spycam', 'Get by array offset 1');
		$this->assertEqual(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');


		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'Sainity check');
		$this->assertTrue(isset($c2->orders[1]), 'array offset exists');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');

		$c2 = $this->getDirtyCustomer(2);
		$this->assertFalse(isset($c2->orders[3]), 'array offset doesn\'t exist');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');

		$c2 = $this->getDirtyCustomer(2);
		$c2->orders[0] = 'test';
		$this->assertEqual($c2->orders[0], 'test', 'Set by array offset');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');


		$c2 = $this->getDirtyCustomer(2);
		$clone = clone $c2;
		unset($c2->orders[0]);
		$this->assertEqual(count($c2->orders), 1, 'Unset by array offset');
		$this->assertIsA($c2->orders, 'SledgeHammer\Collection', 'The orders property should be replaced with an Collection');

		$this->expectError('This placeholder belongs to an other (cloned?) container');
		$this->assertEqual($clone->orders[1]->product, 'Spycam');
		//	$this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
	}

	function test_getWildcard_preload() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		$order = $repo->getOrder(2, true);
		$this->assertFalse($order->customer instanceof BelongsToPlaceholder, 'Should not be a BelongsToPlaceholder');
		$this->assertIsA($order->customer->orders, 'SledgeHammer\Collection', 'Should not be a HasManyPlaceholder');
	}

	function test_removeWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

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
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

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
			$this->assertEqual($e->getMessage(), 'Change rejected, the index changed from {2} to {1}');
			// @todo check if the message indicated the id-change
		}
		$repo->validate();
		$order2->customer->id = "2"; // restore customer object
		$repo->saveOrder($order2); // The belongTo is autoloaded, but unchanged
		$this->assertQueryCount($this->queryCountAfterInspectDatabase + 4, 'Saving an unmodified instance shouldn\'t generate a query');

		$c2 = $repo->getCustomer(2);
		$this->assertEqual($c2->orders[0]->product, 'Walter PPK 9mm', 'Sanity check');
		$c2->orders[0]->product = 'Walther PPK'; // correct spelling
		$c2->orders[] = $repo->createOrder(array('product' => 'Scuba gear'));
		unset($c2->orders[1]);
		$repo->saveCustomer($c2);
		$this->assertQuery("UPDATE orders SET product = 'Walther PPK' WHERE id = 2");
		$this->assertQuery("INSERT INTO orders (customer_id, product) VALUES (2, 'Scuba gear')");
		$this->assertQuery('DELETE FROM orders WHERE id = 3');
		$this->assertEqual($c2->orders[2]->id, '4', 'The id of the instance should be the "lastInsertId()"');
	}

	function test_AutoCompleteHelper() {
		$repoBase = new Repository();
		$repoBase->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		$filename = TMP_DIR.'Test_AutoCompleteRepository.php';
		$class = 'AutoCompleteTestRepository';
		$repoBase->writeAutoCompleteHelper($filename, $class);
		include($filename);
		$methods = array_diff(get_public_methods($class), get_public_methods('SledgeHammer\Repository'));
		$this->assertEqual($methods, array(
			'getCustomer',
			'allCustomers',
			'saveCustomer',
			'createCustomer',
			'deleteCustomer',
			'getOrder',
			'allOrders',
			'saveOrder',
			'createOrder',
			'deleteOrder',
		));
		$repo = new \AutoCompleteTestRepository();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink)); // @todo? Write serialized backends into AutoGenerated class?

		$c1 = $repo->getCustomer(1);
		$this->assertEqual($c1->name, 'Bob Fanger');
		$c1->name = 'Charlie Fanger';
		$repo->saveCustomer($c1);
		$this->assertLastQuery("UPDATE customers SET name = 'Charlie Fanger' WHERE id = 1");
		$c1->orders = array();
		$repo->saveCustomer($c1);
		$repo->deleteCustomer($c1);
		$this->assertLastQuery('DELETE FROM customers WHERE id = 1');
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
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		return $repo->getCustomer($id);
	}

}

?>