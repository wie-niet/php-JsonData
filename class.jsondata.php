<?php

class JsonData {
	public $orm =  JsonDataOrm;
		 
}

class JsonDataOrm {
	static private $base_dir = './data'; 	// do not change after you run addModel()
	static $quick_conf = [];		 		// runtime config	
	static $lock_ttl = 3600; 				// ignore locks that are more than lock_ttl seconds old, set to 0 to disable.

	static function setBaseDir($dir) {
		if(count(self::$quick_conf) == 0) {
			self::$base_dir = $dir;
		} else {
			throw new Exception('You must setBaseDir before you add your Models');
		}
	}
	
	
	
	static function addModel($db, $model_name, $collection_class_name=false, $item_class_name=false, $extra_path_prefix='') {
		
		// by default look for CamelCase model_name : ModelNameCollection
		if (!$collection_class_name) {
			$collection_class_name = str_replace('_', '',ucwords($model_name, "_").'Collection');
		}

		// by default look for CamelCase model_name : ModelNameItem
		if (!$item_class_name) {
			$item_class_name = str_replace('_', '',ucwords($model_name, "_").'Item');
		}
		
		// compose json/lock/temp file prefix:
		$file_path_prefix = self::$base_dir .'/'. $extra_path_prefix . $model_name;
		
		// quick_conf[$model_name] = ['model_name', 'CollectionClassName', 'ItemClassName','file_path_prefix']
		$quick_conf = [$model_name, $collection_class_name, $item_class_name, $file_path_prefix];
		
		// set the array by reference 
		self::$quick_conf[$model_name] = &$quick_conf;
		self::$quick_conf[$collection_class_name] = &$quick_conf;
		self::$quick_conf[$item_class_name] = &$quick_conf;
		
		// add instance of CollectionClass on JsonData object.
		$db->$model_name = new $collection_class_name();
		
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

	static function getFilePathPrefix($ref) {
		$ref = is_string($ref) ? $ref : get_class($ref);
		return(self::$quick_conf[$ref][3]);
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
	
	function as_json($ref, $add_meta_keys=['id','created_at']) {
		$data = [];
		
		foreach($add_meta_keys as $key) {
			$data['__meta__'][$key] = $ref->getMetaAttr($key);
		}
		
		foreach($ref as $key=>$val) {
			$data[$key] = $val;
		}
		
		
		return(json_encode($data, JSON_PRETTY_PRINT));
	}
	
	function genUuid() {
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
	function getFileTimeStamps($ref) {
		// 8	atime	time of last access (Unix timestamp)
		// 9	mtime	time of last modification (Unix timestamp)
		$file_stat = stat(self::getFilePath($ref));
		return(array('updated_at'=> $file_stat['mtime'], 'accessed_at'=> $file_stat['atime']));
		
	}
	
	function lock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');
				
		// check if lock already exists
		if (self::haslock($ref)) {
			throw new Exception("Lock file already exists for '$model_name($id)'.");
			return(false);
		}		

		// will return true on succes
		return(touch($lock_file));
	}
	
	function haslock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');
		// check if lock already exists
		if (!file_exists($lock_file)) {			
			return(false);
		}
		
		// is file older than $lock_ttl	, set to 0 or less to disable 
		if( ($lock_ttl <= 0) or time() - filemtime($lock_file) < self::$lock_ttl) {
			// age is still under ttl, we will not get the lock
			return(true);
		}
		
		// cleanup old lock
		return(unlink($lock_file));

		return(false);
	}

	function unlock($ref) {
		$lock_file = self::getFilePath($ref, 'lock');
		return(unlink($lock_file));
	}
	
	// moved ::read() to JsonDataItem->read()
	
	function write($ref) {		
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
	
	function delete($ref, $id) {
		$json_file = self::getFilePathWithId($ref, $id);
		return(unlink($json_file));
	}
	
}


class JsonDataItem {
	protected $__meta__ = ['orm'=>JsonDataOrm];
	// $__meta__['id']
	// $__meta__['orm']


	public function __construct($id=null, $read=true, $lock=false) {
		if($id === null) {
			// new item; set created_at time
			$this->setMetaAttr('created_at', time());
		} else {
			// existing item
			
			// set id  (when id=null , setId() will be used to set it before writing.
			$this->setMetaAttr('id', $id);
			
			// read item
			if ($read ) {
				$this->read($lock);
			}
		}
	}
	
	public function __toString() {
		return(sprintf("%s(%s)",get_class($this), $this->id())); 
	}
		
	public function setId($id=false) {
		if($this->id() !== null ) {
			throw new Exception("Can't change 'id' on '$this', it's already set.");
		}
		
		// set id or create new:	
		$this->setMetaAttr('id', $id ? $id : $this->__meta__['orm']::genUuid());
		
	}
	
	public function id() {
		return(isset($this->__meta__['id']) ? $this->__meta__['id'] : null );
	}
	
	public function created_at($format='c') {
		return(date($format, $this->__meta__['created_at']));
	}

	public function updated_at($format='c') {
		if($this->__meta__['updated_at'] === null ) {
			if( $this->is_new() ) { throw new Exception("There is no 'updated_at' timestamp, item is new."); }

			$time_stamps = $this->__meta__['orm']::getFileTimeStamps($this);
			$this->__meta__['updated_at'] = $time_stamps['updated_at'];
			$this->__meta__['accessed_at'] = $time_stamps['accessed_at'];
		}		
		return(date($format, $this->__meta__['updated_at']));
	}

	public function accessed_at($format='c') {
		if($this->__meta__['updated_at'] === null ) {
			if( $this->is_new() ) { throw new Exception("There is no 'accessed_at' timestamp, item is new."); }

			$time_stamps = $this->__meta__['orm']::getFileTimeStamps($this);
			$this->__meta__['updated_at'] = $time_stamps['updated_at'];
			$this->__meta__['accessed_at'] = $time_stamps['accessed_at'];
		}
		
		return(date($format, $this->__meta__['accessed_at']));
	}

	public function getMetaAttr($key, $defaul=null) {
		return(isset($this->__meta__[$key]) ? $this->__meta__[$key] : $default);
	}

	private function setMetaAttr($key, $value) {
		$this->__meta__[$key] = $value;
	}



	public function getAttr($key, $defaul=null) {
		return(isset($this->$key) ? $this->$key : $default);
	}

	public function setAttr($key, $value) {
		$this->$key = $value;
	}

	public function as_json() {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']::as_json($this));
	}
	
	public function getFilePath($type='json') {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']::getFilePath($this));
	}
	
	public function is_new() {
		return($this->id() === null or ! file_exists($this->getFilePath()));
	}
	
	function read($lock=false) {
		// obtain lock incase we read for write.
		if($lock) {
			// try to obtain lock first
			if(!$this->__meta__['orm']::lock($this)) {
				throw new Exception("Can't obtain lock for '$ref'.");
			}
		}
		
		$json_file = $this->__meta__['orm']::getFilePath($this);
		$json_data = json_decode(file_get_contents($json_file), true);

		// get meta values
		foreach($json_data['__meta__'] as $key=>$val) {
			$this->setMetaAttr($key, $val);
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
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']::write($this));
	}
	
	public function delete() {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']::delete($this, $this->id()));
	}
	
}


class JsonDataCollection {
	// public $__meta__ = [];
	protected $__meta__ = ['orm'=>JsonDataOrm];

	// $__meta__['orm']
		
	public function __construct() {
	}
	
	
	public function getMetaAttr($key, $defaul=null) {
		return(isset($this->__meta__[$key]) ? $this->__meta__[$key] : $default);
	}

	public function setMetaAttr($key, $value) {
		$this->__meta__[$key] = $value;
	}

	
	public function find($id, $read=true, $lock=false) {
		
		$json_file = $this->__meta__['orm']::getFilePathWithId($this, $id);
		if ( file_exists($json_file) ) {
			return($read ? $this->read($id, $lock) : true);
		}
		return(false);
	}
	
	public function new($data=[]) {
		// $item_classname = $this->getMetaAttr('item_classname');
		$item_classname = $this->__meta__['orm']::getItemClassName(get_class($this));
		$item = new $item_classname();
		
		// set data:
		foreach($data as $key => $val) {
			$item->setAttr($key, $val);
		}

		return($item);
	}
	
	public function read($id, $lock=false) {
		$item_classname = $this->__meta__['orm']::getItemClassName(get_class($this));
		$item = new $item_classname($id, true, $lock);
				
		return($item);
	}

	public function delete($id) {
		// pass this to JsonDataOrm::function
		return($this->__meta__['orm']::delete($this, $id));
	}
}
