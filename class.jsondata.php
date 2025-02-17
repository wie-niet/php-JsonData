<?php


class JsonData {
	// public $orm = JsonDataOrm;
	public $orm;

	public function __construct($orm=Null) {
		$this->orm = $orm ?? new JsonDataOrm($this, './data');
	}
}


class JsonDataOrm {
	static private $parent_db;
	static private $base_dir;	  	// do not change after you run addModel()
	static $quick_conf = [];		// runtime config
	static $lock_ttl = 3600; 		// ignore locks that are more than lock_ttl seconds old, set to 0 to disable.

	public function __construct($parent_db, $base_dir) {
		self::$base_dir = $base_dir;
		self::$parent_db = $parent_db;
	}

	static function setBaseDir($dir) {
		if(count(self::$quick_conf) == 0) {
			self::$base_dir = $dir;
		} else {
			throw new Exception('You must setBaseDir before you add your Models');
		}
	}



	public function addModel($model_name, $collection_class_name=false, $item_class_name=false, $hooks_class_name=false) {

		// by default look for CamelCase model_name : ModelNameCollection
		if (!$collection_class_name) {
			$collection_class_name = str_replace('_', '',ucwords($model_name, "_").'Collection');
		}

		// by default look for CamelCase model_name : ModelNameItem
		if (!$item_class_name) {
			$item_class_name = str_replace('_', '',ucwords($model_name, "_").'Item');
		}

		// by default look for CamelCase model_name : ModelNameHooks
		if (!$hooks_class_name) {
			$hooks_class_name = str_replace('_', '',ucwords($model_name, "_").'Hooks');
			if (!class_exists($hooks_class_name)){
				$hooks_class_name = 'jsonDataHooks';
			}
		}

		// compose json/lock/temp file prefix:
		$file_path_prefix = self::$base_dir .'/'. $model_name;

		// quick_conf[$model_name] = ['model_name', 'CollectionClassName', 'ItemClassName','HooksClassName','file_path_prefix']
		$quick_conf = [$model_name, $collection_class_name, $item_class_name, $hooks_class_name, $file_path_prefix];

		// set the array by reference
		self::$quick_conf[$model_name] = &$quick_conf;
		self::$quick_conf[$collection_class_name] = &$quick_conf;
		self::$quick_conf[$item_class_name] = &$quick_conf;

		// add instance of CollectionClass on JsonData object.
		// $this->parent_db->$model_name = new $collection_class_name($this);
		self::$parent_db->$model_name = new $collection_class_name($this);

	}

	/*
	 * Config lookup tools
	 */
	static function getModelName($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		return(self::$quick_conf[$ref][0]);
	}

	static function getCollectionClassName($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		return(self::$quick_conf[$ref][1]);
	}


	static function getItemClassName($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		return(self::$quick_conf[$ref][2]);
	}

	static function getHooksObject($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		$klass = self::$quick_conf[$ref][3];
		return(new $klass);
	}


	static function getFilePathPrefix($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		return(self::$quick_conf[$ref][4]);
	}

	/*
	 * Tools:
	 */
	static function getFilePath($ref, $type='json') {
		return(self::getFilePathPrefix($ref).'.'.$ref->id().'.'.$type);
	}

	static function getFilePathWithId($ref, $id, $type='json') {
		return(self::getFilePathPrefix($ref).'.'.$id.'.'.$type);
	}

	public function exists($ref, $id, $type='json') {
		return(file_exists(self::getFilePathPrefix($ref).'.'.$id.'.'.$type));
	}



	public function as_json($ref, $add_meta_keys=['id','created_at']) {
		$data = [];

		// $add_meta_keys = True means all:
		if ($add_meta_keys === True) {
			$add_meta_keys = ['id', 'state','created_at', 'updated_at', 'accessed_at'];
		}

		foreach($add_meta_keys as $key) {
			if ($ref->hasMetaAttr($key)) {
				$data['__meta__'][$key] = $ref->getMetaAttr($key);
			}
		}

		foreach($ref as $key=>$val) {
			$data[$key] = $val;
		}


		return(json_encode($data, JSON_PRETTY_PRINT));
	}

	public function genUuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}


	/*
	 * json | lock | temp file tools:
	 */
	public function getFileTimeStamps($ref) {
		// 8	atime	time of last access (Unix timestamp)
		// 9	mtime	time of last modification (Unix timestamp)
		$file_stat = stat(self::getFilePath($ref));
		return(array('updated_at'=> $file_stat['mtime'], 'accessed_at'=> $file_stat['atime']));
	}

	public function lock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');

		// check if lock already exists
		if (self::haslock($ref)) {
			throw new Exception("Lock file already exists for '$lock_file'.");
			return(false);
		}

		// will return true on succes
		return(touch($lock_file));
	}

	public function haslock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');
		// check if lock already exists
		if (!file_exists($lock_file)) {
			return(false);
		}

		// is file older than $lock_ttl	, set to 0 or less to disable
		if( (self::$lock_ttl <= 0) or time() - filemtime($lock_file) < self::$lock_ttl) {
			// age is still under ttl, we will not get the lock
			return(true);
		}

		// cleanup old lock
		return(unlink($lock_file));

		return(false);
	}

	public function unlock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');
		return(unlink($lock_file));
	}

	// moved ::read() to JsonDataItem->read()

	public function write($ref) {
		// put it in file

		// create: setId for new objects
		if($ref->id() === null ) {
			$ref->setId();
		}

		$json_file = self::getFilePath($ref);
		$temp_file = self::getFilePath($ref, 'temp');

		// set $no_lock if this json file is new ( == doesn't exist)
		$no_lock = !file_exists($json_file);

		// check lock , use no_lock for writing new json data
		if(!$no_lock) {
			if(!self::haslock($ref)) {
				throw new Exception("No lock on '$ref', lock() or read(lock=true) before writing.");
			}
		}


		$json_data = self::as_json($ref);
		// $json_data = $ref->as_json();

		// first write data to
		if (!file_put_contents($temp_file, $json_data)) {
			throw new Exception("Can't write to temp file for '$ref'.");
		}

		// move temp file inplace.
		if(!rename($temp_file, $json_file)) {
			throw new Exception("Can't write to data file for '$ref'.");
		}

		// unlock
		if(!$no_lock) {
			self::unlock($ref);
		}

	}

	public function delete($ref, $id) {
		$json_file = self::getFilePathWithId($ref, $id);
		return(unlink($json_file));
	}

}


class JsonDataItem {
	// protected $__meta__ = ['orm'=>JsonDataOrm];
	protected $__meta__ = [];
	// $__meta__['id']
	// $__meta__['orm']


	public function __construct($orm, $id=null, $read=true, $lock=false) {
		$this->setMetaAttr('orm', $orm);

		if($id === null) {
			// new item; set created_at time
			$this->setMetaAttr('created_at', time());

			// set state: new
			$this->setMetaAttr('state', 'new');
		} else {
			// existing item

			// set id  (when id=null , setId() will be used to set it before writing.
			$this->setMetaAttr('id', $id);

			// read item
			if ($read ) {
				$this->read($lock);
			}
		}

		// call hook on_init()
		$hooks = $this->__meta__['orm']->getHooksObject($this);
		if (method_exists($hooks, 'on_init')) { $hooks->on_init($this); }

	}

	public function __toString() {
		return(sprintf("%s(%s)",get_class($this), $this->id()));
	}

	public function setId($id=false) {
		// TODO: validate id (no dots, whitespaces ...)

		if($this->id() !== null ) {
			throw new Exception("Can't change 'id' on '$this', it's already set.");
		}

		// check if already exists
		if ($this->__meta__['orm']->exists($this, $id)) {
			throw new Exception("Can't set 'id' to '$id', it's object already exists.");
		}

		// set id or create new:
		$this->setMetaAttr('id', $id ? $id : $this->__meta__['orm']->genUuid());

	}

	public function id() {
		return(isset($this->__meta__['id']) ? $this->__meta__['id'] : null );
	}

	public function created_at($format='c') {
		return(date($format, $this->__meta__['created_at']));
	}

	public function read_timestamps_in_meta() {
		if( !$this->exists() ) { throw new Exception("There are no timestamps, item doesn't exist."); }

		$time_stamps = $this->__meta__['orm']->getFileTimeStamps($this);
		$this->setMetaAttr('updated_at', $time_stamps['updated_at']);
		$this->setMetaAttr('accessed_at', $time_stamps['accessed_at']);

		return $time_stamps;
	}

	public function updated_at($format='c') {
		if($this->__meta__['updated_at'] ?? False ) {
			$this->read_timestamps_in_meta();
		}
		return(date($format, $this->getMetaAttr('updated_at')));
	}

	public function accessed_at($format='c') {
		if($this->__meta__['accessed_at'] ?? null ) {
			$this->read_timestamps_in_meta();
		}

		return(date($format, $this->__meta__['accessed_at']));
	}

	public function hasMetaAttr($key) {
		return(isset($this->__meta__[$key]));
	}

	public function getMetaAttr($key, $default=null) {
		return(isset($this->__meta__[$key]) ? $this->__meta__[$key] : $default);
	}

	private function setMetaAttr($key, $value) {
		$this->__meta__[$key] = $value;
	}

	public function getAttr($key, $default=null) {
		return(isset($this->$key) ? $this->$key : $default);
	}

	public function setAttr($key, $value) {
		$this->$key = $value;
	}

	public function as_json($add_meta_keys=['id','created_at']) {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']->as_json($this, $add_meta_keys));
	}

	public function getFilePath($type='json') {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']->getFilePath($this));
	}

	public function exists() {
		return($this->__meta__['orm']->exists($this, $this->id()));
	}

	public function is_new() {
		return($this->getMetaAttr('state') == 'new');
	}

	public function is_created() {
		return($this->getMetaAttr('state') == 'created');
	}

	public function is_deleted() {
		return($this->getMetaAttr('state') == 'deleted');
	}

	public function read($lock=false) {
		// obtain lock incase we read for write.
		if($lock) {
			// try to obtain lock first
			if(!$this->__meta__['orm']->lock($this)) {
				throw new Exception("Can't obtain lock for '$ref'.");
			}
		}

		$json_file = $this->__meta__['orm']->getFilePath($this);
		$json_data = json_decode(file_get_contents($json_file), true);

		// verify id
		$id = $this->id();
		$file_id = $json_data['__meta__']['id'] ?? False;
		if ($id != $file_id) {
			throw new Exception("Data corrupted, id mismatch: ('$id' != '$file_id')");
		}


		// get meta values
		foreach($json_data['__meta__'] as $key=>$val) {
			if (!$this->hasMetaAttr($key)) {
				$this->setMetaAttr($key, $val);
			}
		}
		unset($json_data['__meta__']);

		// get data values:
		foreach($json_data as $key=>$val) {
			$this->$key = $val;
			// $this->setAttr($key,$val);
		}
	}

	public function update($data) {
		// update all key=>values
		$this->read(true);
		foreach($data as $key=>$val) {
			$this->$key = $val;
		}
		$this->write();
	}

	public function write() {
		// call hook on_write()
		$hooks = $this->__meta__['orm']->getHooksObject($this);
		if (method_exists($hooks, 'on_write')) { $hooks->on_write($this); }

		// used to set state
		$exists = $this->exists();

		try {
			// pass this to JsonDataOrm::function
			$this->__meta__['orm']->write($this);
		}
		catch (Exception $e) {
			if (method_exists($hooks, 'on_write_failed')) { $hooks->on_write_failed($this); }
			throw $e;
		}

		// set created if file is first written.
		if (!$exists) {
			// set state: created
			$this->setMetaAttr('state', 'created');
		}
		if (method_exists($hooks, 'on_write_success')) { $hooks->on_write_success($this); }

	}

	public function delete() {
		if(!$this->exists()) { return false; }

		// call hooks
		$hooks = $this->__meta__['orm']->getHooksObject($this);
		if (method_exists($hooks, 'on_delete')) { $hooks->on_delete($this); }

		// pass this to JsonDataOrm::function
		if($this->__meta__['orm']->delete($this, $this->id())) {
			$this->setMetaAttr('state', 'deleted');
			if (method_exists($hooks, 'on_delete_success')) { $hooks->on_delete_success($this); }

			return(true);
		} else {
			if (method_exists($hooks, 'on_delete_failed')) { $hooks->on_delete_failed($this); }
			return(false);
		}
	}

}


class JsonDataCollection {
	// public $__meta__ = [];
	// protected $__meta__ = ['orm'=>JsonDataOrm];
	protected $__meta__ = [];

	// $__meta__['orm']

	public function __construct($orm) {
		$this->setMetaAttr('orm', $orm);
	}

	public function getMetaAttr($key, $default=null) {
		return(isset($this->__meta__[$key]) ? $this->__meta__[$key] : $default);
	}

	public function setMetaAttr($key, $value) {
		$this->__meta__[$key] = $value;
	}

	public function exists($id) {
		return($this->__meta__['orm']->exists($this, $id));
	}

	public function find($id, $read=true, $lock=false) {

		$json_file = $this->__meta__['orm']->getFilePathWithId($this, $id);
		if ( file_exists($json_file) ) {
			return($read ? $this->read($id, $lock) : true);
		}
		return(false);
	}

	public function new($data=[]) {
		// $item_classname = $this->getMetaAttr('item_classname');
		$item_classname = $this->__meta__['orm']->getItemClassName(get_class($this));
		$item = new $item_classname($this->__meta__['orm']);

		// set data:
		foreach($data as $key => $val) {
			$item->setAttr($key, $val);
		}

		return($item);
	}

	public function read($id, $lock=false) {
		$item_classname = $this->__meta__['orm']->getItemClassName(get_class($this));
		$item = new $item_classname($this->__meta__['orm'] ,$id, true, $lock);

		return($item);
	}

	public function delete($id) {
		if(!$this->exists($id)) { return false; }

		// check if this Item has delete* hooks defined
		$hooks = $this->__meta__['orm']->getHooksObject($this);
		if (method_exists($hooks, 'on_delete') or method_exists($hooks, 'on_delete_success') or method_exists($hooks, 'on_delete_failed')) {
			// exec delete on item so that hooks are called:
			$item = $this->find($id);
			return($item->delete());
		} else {
			return($this->__meta__['orm']->delete($this, $id));
		}
	}
}

class jsonDataHooks {
	// Hooks are only called when they exist


	// public function on_init($ref) {
	// 	// hint use is_new() or exists()
	// }
	//
	// public function on_write($ref) {
	// 	// hint use is_new() or exists()
	// }
	//
	// public function on_write_success($ref) {
	// 	// hint use is_created()
	// }
	//
	// public function on_write_failed($ref) {
	// 	// hint use is_new() or exists()
	// }
	//
	// public function on_delete($ref) {
	// }
	//
	// public function on_delete_succes($ref) {
	// }
	//
	// public function on_delete_failed($ref) {
	// }
	//
	// // // *before on_write validate ?

}