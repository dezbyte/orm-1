<?php
/**
 * Extract the key/value of the collection based on a propertypath.
 *
 * @package Record
 */

namespace SledgeHammer;

class CollectionView extends Collection {

	protected $valueField = null;
	protected $keyField = null;

	function __construct($iterator, $valueField, $keyField = null) {
		$this->valueField = $valueField;
		$this->keyField = $keyField;
		parent::__construct($iterator);
	}

	public function key() {
		$key = parent::key();
		if ($this->keyField === null) {
			return $key;
		}
		return PropertyPath::get(parent::current(), $this->keyField);
	}

	function current() {
		$value = parent::current();
		if ($this->valueField === null) {
			return $value;
		}
		return PropertyPath::get($value, $this->valueField);
	}

	public function where($conditions) {
		if ($this->valueField === null && $this->iterator instanceof Collection) { // The valueField is not set, pass the conditions to the original collection. 
			return new CollectionView($this->iterator->where($conditions), null, $this->keyField);
		}
		return parent::where($conditions);
	}

}

?>
