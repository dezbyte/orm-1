<?php
/**
 * Repository/DataMapper
 *
 * An API to retrieve and store models from their backends and track their changes.
 * A model is a view on top of the data the backend provides.
 *
 * @package Record
 */
namespace SledgeHammer;

class Repository extends Object {

	protected $id;
	protected $namespaces = array('', 'SledgeHammer\\');

	/**
	 * @var array  registerd models: array(model => config)
	 */
	protected $configs = array();

	/**
	 * @var array  references to instances
	 */
	protected $objects = array();

	/**
	 * @var array  references to instances that are not yet added to the backend
	 */
	protected $created = array();
	/**
	 * @var array registerd backends
	 */
	protected $backends = array();

	function __construct() {
		$this->id = uniqid('R');
		$GLOBALS['Repositories'][$this->id] = &$this;
	}

	/**
	 * Catch methods
	 * @param string $method
	 * @param array $arguments
	 * @return mixed
	 */
	function __call($method, $arguments) {
		if (preg_match('/^get(.+)Collection$/', $method, $matches)) {
			if (count($arguments) > 0) {
				notice('Too many arguments, expecting none', $arguments);
			}
			return $this->loadCollection($matches[1]);
		}
		if (preg_match('/^(get|save|remove|create)(.+)$/', $method, $matches)) {
			$method = $matches[1];
			array_unshift($arguments, $matches[2]);
			return call_user_func_array(array($this, $method), $arguments);
		}
		return parent::__call($method, $arguments);
	}

	/**
	 * Retrieve an instance from the Repository
	 *
	 * @param string $model
	 * @param mixed $id  The instance ID
	 * @param bool $preload  Load relations direct
	 * @return instance
	 */
	function get($model, $id, $preload = false) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($id, $config);
		$object = @$this->objects[$model][$index];
		if ($object !== null) {
			return $object['instance'];
		}
		$this->objects[$model][$index] = array(
			'state' => 'retrieving',
			'instance' => null,
			'data' => null,
		);
		$data = $this->_getBackend($config['backend'])->get($id, $config);
		$indexFromData = $this->resolveIndex($data, $config);
		if ($index != $indexFromData) {
			unset($this->objects[$model][$index]); // cleanup invalid entry
			throw new \Exception('The $id parameter doesn\'t match the retrieved data. '.$index.' != '.$indexFromData);
		}
		$this->objects[$model][$index]['data'] = $data;
		$this->objects[$model][$index]['state'] = 'retrieved';

		$instance = $this->convertToInstance($data, $config, $index);
		$this->objects[$model][$index]['instance'] = $instance;
		if ($preload) {
			if (isset($config['belongsTo'])) {
				foreach ($config['belongsTo'] as $property => $relation) {
					$value = $instance->$property;
					if ($value instanceof BelongsToPlaceholder) {
						$this->loadAssociation($model, $instance, $property, true);
					}
				}
			}
			if (isset($config['hasMany'])) {
				foreach ($config['hasMany'] as $property => $relation) {
					$value = $instance->$property;
					if ($value instanceof HasManyPlaceholder) {
						$this->loadAssociation($model, $instance, $property, true);
					}
				}
			}
		}
		return $instance;
	}

	/**
	 * Create a instance from existing $data.
	 * This won't store the data. For storing data use $repository->save($instance)
	 *
	 * @param string $model
	 * @param array/object $data Raw data from the backend
	 * @param bool $preload  Load relations direct
	 * @return instance
	 */
	function convert($model, $data, $preload = false) {
		if ($data === null) {
			throw new \Exception('Parameter $data is required');
		}
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($data, $config);

		$object = @$this->objects[$model][$index];
		if ($object !== null) {
			// @todo validate $data against $object['data']
			return $object['instance'];
		}
		$this->objects[$model][$index] = array(
			'state' => 'retrieved',
			'instance' => null,
			'data' => $data,
		);
		$instance = $this->convertToInstance($data, $config);
		$this->objects[$model][$index]['instance'] = $instance;
		if ($preload) {
			warning('Not implemented');
		}
		return $instance;
	}

	/**
	 *
	 * @param string $model
	 * @return Collection
	 */
	function loadCollection($model) {
		$config = $this->_getConfig($model);
		$collection = $this->_getBackend($config['backend'])->all($config);
		$collection->bind($model, $this->id);
		return $collection;
	}

	function loadAssociation($model, $instance, $property, $preload = false) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($instance, $config);

		$object = @$this->objects[$model][$index];
		if ($object === null || ($instance !== $object['instance'])) {
			throw new \Exception('Instance not bound to this repository');
		}
		$belongsTo = @$config['belongsTo'][$property];
		if ($belongsTo !== null) {
			$id = $object['data'][$belongsTo['reference']];
			if ($id === null) {
				dump($object);
				throw new \Exception('Now what?');
			}
			$instance->$property = $this->get($belongsTo['model'], $id, $preload);
			return;
		}
		$hasMany = @$config['hasMany'][$property];
		if ($hasMany !== null) {
			if (count($config['id']) != 1) {
				throw new \Exception('Complex keys not (yet) supported for hasMany relations');
			}
			$id = $instance->{$config['id'][0]};
			$collection = $this->loadCollection($hasMany['model'])->where(array($hasMany['reference'] => $id));
			$items = $collection->asArray();
			$this->objects[$model][$index]['hadMany'][$property] = $items; // Add a copy for change detection
			$instance->$property = $items;
			return;
		}
		throw new \Exception('No association found for  '.$model.'->'.$property);
	}

	/**
	 * Remove an instance
	 *
	 * @param string $model
	 * @param instance|id $mixed  The instance or id
	 */
	function remove($model, $mixed) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($mixed, $config);
		$object = @$this->objects[$model][$index];
		if ($object === null) {
			if (is_object($mixed)) {
				throw new \Exception('The instance is not bound to this Repository');
			}
			// The parameter is the id
			if (is_array($mixed)) {
				$data = $mixed;
			} else {
				$data = array($config['id'][0] => $mixed); // convert the id to array-notation
			}
		} elseif ($object['state'] == 'new') { // The instance issn't stored in the backend and only exists in-memory?
			throw new \Exception('Removing instance failed, the instance issn\'t stored in the backend');
		} else {
			$data = $object['data'];
		}
		$this->_getBackend($config['backend'])->remove($data, $config);
		$this->objects[$model][$index]['state'] = 'removed';
	}

	/**
	 * Create an in-memory instance of the model, ready to be save()d.
	 *
	 * @param string $model
	 * @param array $data  Initial contents of the object (optional)
	 * @return object
	 */
	function create($model, $values = array()) {
		$config = $this->_getConfig($model);
		$values = array_merge($config['defaults'], $values);
		$index = uniqid('TMP-');
		$class = $config['class'];
		$instance = new $class;
		// Apply initial values
		foreach ($values as $property => $value) {
			// @todo Support complex mapping
			$instance->$property = $value;
		}
		$this->objects[$model][$index] = array(
			'state' => 'new',
			'instance' => $instance,
			'data' => null,
		);
		$this->created[$model][$index] = $instance;
		return $instance;
	}

	/**
	 * Store the instance
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @param array $options
	 *   'ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances,
	 *   'add_unknown_instance' => bool, false: Reject unknown instances. (use $Repository->add())
	 *   'reject_unknown_related_instances' => bool, false: Auto adds unknown instances
	 *   'keep_missing_related_instances' => bool, false: Auto deletes removed instances
	 * }
	 */
	function save($model, $instance, $options = array()) {
		$relationSaveOptions = $options;
		$relationSaveOptions['add_unknown_instance'] = (value($option['reject_unknown_related_instances']) == false);
		$config = $this->_getConfig($model);
		$data = array();
		$index = null;
		$object = null;
		$index = $this->resolveIndex($instance, $config);

//		try {
//			$index = $this->resolveIndex($instance, $config);
//		} catch (\Exception $e) {
//			if (value($options['add_unknown_instance']) == false) {
//				throw $e;
//			}
//			notice('Unable to dermine index, probably a new instance (use Repository->add()) for those', $e->getMessage());
//			throw $e;
//
////			ErrorHandler::handle_exception($e);
////			throw new \Exception('Reimplement add');
//			// @todo Check if the instance is bound to another $index
//		}
		$object = @$this->objects[$model][$index];
		if ($object === null) {
			// @todo Check if the instance is bound to another $index
			throw new \Exception('The instance is not bound to this Repository');
		}
		$previousState = $object['state'];
		try {
			if ($object['state'] == 'saving') { // Voorkom oneindige recursion
				return;
			}
			if ($object['instance'] !== $instance) {
				// id/index change-detection
				foreach ($this->objects[$model] as $object) {
					if ($object['instance'] === $instance) {
						throw new \Exception('Change rejected, the index changed from '.$this->resolveIndex($object['data'], $config).' to '.$index);
					}
				}
				throw new \Exception('The instance is not bound to this Repository');
			}
			$this->objects[$model][$index]['state'] = 'saving';

			// Save belongsTo
			if (isset($config['belongsTo']) && value($options['ignore_relations']) == false) {
				foreach ($config['belongsTo'] as $property => $belongsTo) {

					if ($instance->$property !== null && ($instance->$property instanceof BelongsToPlaceholder) == false) {
						$this->save($belongsTo['model'], $instance->$property, $relationSaveOptions);
					}
				}
			}

			// Save instance
			$data = $this->convertToData($object['instance'], $config);
			if ($previousState == 'new') {
				$object['data'] = $this->_getBackend($config['backend'])->add($data, $config);
				$changes = array_diff($object['data'], $data);
				if (count($changes) > 0) {
					foreach ($changes as $column => $value) {
						$instance->$column = $value; // @todo reversemap the column to the property
					}
					unset($this->objects[$model][$index]);
					$index = $this->resolveIndex($object['data'], $config);
					// @todo check if index already exists?
					$this->objects[$model][$index] = $object;
				}

			} else {
				$this->objects[$model][$index]['data'] = $this->_getBackend($config['backend'])->update($data, $object['data'], $config);
			}

			// Save hasMany
			if (isset($config['hasMany']) && value($options['ignore_relations']) == false) {
				foreach ($config['hasMany'] as $property => $hasMany) {
					if ($instance->$property instanceof HasManyPlaceholder) {
						continue; // No changes (It's not even accessed)
					}
					$relationConfig = $this->_getConfig($hasMany['model']);
					$collection = $instance->$property;
					if ($collection instanceof \Iterator) {
						$collection = iterator_to_array($collection);
					}
					if ($collection === null) {
						notice('Expecting an array for property "'.$property.'"');
						$collection = array();
					}
					foreach ($collection as $item) {
						// Connect the item to this instance
						foreach ($relationConfig['belongsTo'] as $property2 => $belongsTo) {
							if ($hasMany['reference'] == $belongsTo['reference']) { // @todo Een van deze "reference" moet eingenlijk anders heten. id/foreign_key/reference
								$item->$property2 = $instance;
							}
						}
						$this->save($hasMany['model'], $item, $relationSaveOptions);
					}
					if (value($options['keep_missing_related_instances']) == false) {
						// Delete items that are no longer in the relation
						$old = @$this->objects[$model][$index]['hadMany'][$property];
						if ($old !== null) {
							if ($collection === null && count($old) > 0) {
								notice('Unexpected type NULL for property "'.$property.'", expecting an array or Iterator');
							}
							foreach ($old as $item) {
								if (array_search($item, $collection, true) === false) {
									$this->remove($hasMany['model'], $item);
								}
							}
						}
					}
				}
			}
			$this->objects[$model][$index]['state'] = 'saved';
		} catch (\Exception $e) {
			$this->objects[$model][$index]['state'] = $previousState; // @todo Or is an error state more appropriate?
			throw $e;
		}
	}

	function registerBackend($backend, $id = null) {
		if ($id === null) {
			$id = uniqid('B');
		}
		$this->backends[$id] = $backend;
		$configs = $backend->getModels();
		foreach ($configs as $model => $config) {
			$config['backend'] = $id;
			$this->register($model, $config);
		}
	}

	function isConfigured($model) {
		return isset($this->configs[$model]);
	}

	/**
	 * Get the unsaved changes.
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @return array
	 */
	function diff($model, $instance) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($instance, $config);
		$new = $this->convertToData($instance, $config);
		$object = $this->objects[$model][$index];
		if ($object['state'] == 'new') {
			$old = $config['defaults'];
		} else {
			$old = $object['data'];
		}
		$diff = array_diff_assoc($new, $old);
		$changes = array();
		foreach ($diff as $key => $value) {
			if ($object['state'] == 'new') {
				$changes[$key]['next'] = $value;
			} else {
				$changes[$key]['previous'] = $old[$key];
				$changes[$key]['next'] = $value;
			}
		}
		return $changes;
//		foreach ($config['mapping'] as $property => $relation) {
//			$value = $instance->$property;
//			if ($object !== null) {
//				if (is_string($relation)) {
//					$previous = $object['data'][$relation];
//				} else {
//					// @todo
//					$previous = $value;
//				}
//				if ($previous !== $value && $object['state'] != 'new') {
//					$changes[$property]['previous'] = $previous;
//				}
//			} else {
//				$previous = null;
//			}
//			if ($previous !== $value) {
//				$changes[$property]['next'] = $value;
//			}
//		}
//		return $changes;
	}

	protected function convertToInstance($data, $config, $index = null) {
		$class = $config['class'];
		$to = new $class;
		$from = $data;
		$model = $config['model'];
		if ($index === null) {
			$index = $this->resolveIndex($data, $config);
		} elseif (empty($this->objects[$model][$index])) {
			throw new \Exception('Invalid index: "'.$index.'"');		} else {
		}
		// Map the data onto the instance
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) {
				$to->$property = $from[$relation];
			} else {
				// @todo implement complex mappings
				throw new \Exception('Invalid mapping type: "'.$relation['type'].'"');
			}
		}
		if (isset($config['belongsTo'])) {
			foreach ($config['belongsTo'] as $property => $relation) {
				$belongsToId = $from[$relation['reference']];
				if ($belongsToId !== null) {
					if (empty($relation['model'])) {
						warning('Unable to determine model for property "'.$property.'"');
					}
					$belongsToIndex = $this->resolveIndex($belongsToId);
					$belongsToInstance = @$this->objects[$relation['model']][$belongsToIndex]['instance'];
					if ($belongsToInstance !== null) {
						$to->$property = $belongsToInstance;
					} else {
						$fields = array(
							$relation['id'] => $belongsToId, // @todo reverse mapping
						);
						$to->$property = new BelongsToPlaceholder(array(
							'repository' => $this->id,
							'fields' => $fields,
							'model' => $config['model'],
							'property' => $property,
							'container' => $to,
						));
					}
				}
			}
		}
		if (isset($config['hasMany'])) {
			foreach ($config['hasMany'] as $property => $relation) {
				$to->$property = new HasManyPlaceholder(array(
					'repository' => $this->id,
					'model' => $config['model'],
					'property' => $property,
					'container' => $to,
				));
			}
		}
		return $to;
	}

	/**
	 *
	 *
	 * @param stdClass $from  The instance
	 * @param array $to  The raw data
	 * @param array $config
	 */
	protected function convertToData($instance, $config) {
		$to = array();
		$from = $instance;
		// Put the belongsTo columns at the beginning of the array
		if (isset($config['belongsTo'])) {
			foreach ($config['belongsTo'] as $property => $relation) {
				$to[$relation['reference']] = null;  // Dont set the value yet. (could be overwritten with an mapping?)
			}
		}
		// Map to data
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) { // direct property to column mapping
				if (property_exists($instance, $property)) {
					$to[$relation] = $from->$property;
				} else {
					throw new \Exception('Invalid mapping type');

				}
			}
		}
		// Map the belongTo to the "*_id" columns.
		if (isset($config['belongsTo'])) {
			foreach ($config['belongsTo'] as $property => $relation) {
				$belongsTo = $from->$property;
				if ($belongsTo === null) {
					$to[$relation['reference']] = null;
				} else {
					$idProperty = $relation['id']; // @todo reverse mapping
					$to[$relation['reference']] = $from->$property->$idProperty;
				}
			}
		}
		return $to;
	}

	/**
	 * Add an configution for a model
	 *
	 * @param string $model
	 * @param array $config
	 */
	protected function register($model, $config) {
		$config['model'] = $model;
		if (empty($config['class'])) {
			$config['class'] = 'stdClass'; // @todo generate custom class, based on mapping
		}
		$AutoLoader = $GLOBALS['AutoLoader'];

		foreach ($this->namespaces as $namespace) {
			$class = $namespace.$model;
			if (class_exists($class, false) || $AutoLoader->getFilename($class) !== null) { // Is the class known?
//				@todo class compatibility check (Reflection?)
//				@todo import config from class?
				$config['class'] = $class;
			}
		}
		$this->configs[$model] = $config;
	}

	private function _getBackend($backend) {
		$backendObject = @$this->backends[$backend];
		if ($backendObject !== null) {
			return $backendObject;
		}
		throw new \Exception('Backend "'.$backend.'" not registered');
	}

	private function _getConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "'.$model.'" not configured');
	}

	/**
	 * Get an object from the $this->object array based on id
	 *
	 * @param array $config
	 * @param mixed $id
	 * @throws Exception when the object is not found
	 * @return array array('instance' => ?, 'data' => ?, 'state' => ?)
	 */
	private function _getObjectByIndex($id) {
		if ($id === null) {
			throw new \Exception('Parameter $id is required');
		}
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, model: "'.$config['model'].'" requires: "'.human_implode('", "', $config['id']).'"');
			}
			$keys = array();
			foreach ($config['id'] as $column) {
				if (isset($id[$column]) == false) {
					throw new \Exception('Field: "'.$column.'" missing from id');
				}
				$keys[$column] = $id[$column];
			}
			$index = implode('+', $keys);
		} elseif (count($config['id']) == 1) {
			$index = (string) $id;
		} else {
			throw new \Exception('Invalid $id');
		}
		return @$this->objects[$config['model']][$key];
	}

	/**
	 * Return the (objects) index
	 *
	 * @param mixed $from
	 * @param mixed $idConfig
	 */
	private function resolveIndex($from, $config = array()) {
		if ((is_string($from) && $from != '') || is_int($from)) {
			return '{'.$from.'}';
		}
		$key = false;
		if (isset($config['id']) && count($config['id']) == 1) {
			$key = $config['id'][0];
		}
		if (is_array($from)) {
			if (count($from) == 1 && $key !== false) {
				if (isset($from[$key])) {
					return $this->resolveIndex($from[$key]);
				}
				throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
			}
			if (is_array(value($config['id']))) {
				if (count($config['id']) == 1) {
					$field = $config['id'][0];
					if (isset($from[$key])) {
						return $this->resolveIndex($from[$key]);
					}
					throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
				}
				$index ='{';
				foreach ($config['id'] as $field) {
					if (isset($from[$field])) {
						$value = $from[$field];
						if ((is_string($value) && $value != '') || is_int($value)) {
							$index .= $field.':'.$value;
						} else {
							throw new \Exception('Failed to resolve index, invalid value for: "'.$field.'"');
						}
					} else {
						throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
					}
				}
				$index .= '}';
				return $index;
			}
		}
		if (is_object($from)) {
			if ($key !== false) {
				$id = $from->$key; // @todo check $config['mapping']
				if ($id === null) {
					foreach ($this->created[$config['model']] as $index => $created) {
						if ($from === $created) {
							return $index;
						}
					}
					throw new \Exception('Failed to resolve index, missing property: "'.$key.'"');
				}
				return $this->resolveIndex($from->$key);
			}
			throw new \Exception('Not implemented');
		}
		throw new \Exception('Failed to resolve index');
	}
}

?>