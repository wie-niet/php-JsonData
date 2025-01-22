# php-JsonData

Simple json datastore written in php.

With basic CRUD functions, *create*, read, update, delete.
read and write with write-lock capabilities. uses standard uuid.


### setup your Models

```php
// mymodels.php

include('class.jsondata.php');

/*
 * define your Model
 */

class PersonItem extends jsonDataItem {
	// dress up this ModelItem however you like, but you don't need to.
}

class PersonCollection extends jsonDataCollection {
	// dress up this ModelCollection however you like, but you don't need to.
}


/*
 * initialize your JsonData Store/DB/big/small/data thing..
 */

$db = new JsonData();

// $db->setBaseDir('./data'); // by default it will use ./data,
// make sure the 'data' directory exists and is writable for you php script.

$db->orm->addModel('person'); // this configures the orm
// and initialize a new PersonCollection() on $db->person.
```

### create/write/insert new item
All data items will get an UUID by assigned when written, you can overwrite this with $item->setId($id).
You always need to obtain an lock for writing unless the item is new.

```php
// include('mymodels.php'); ...

$person = $db->person->new();
$person->name = 'Kamile';
$person->instagram = '@poes_kamille';
$person->write();

// you can also use setAttr($key, $val)
// $person->setAttr('name', 'Kamille');   // another way to set attributes


printf('person id: %s\n',$person->id()); // will output the UUID of this object

// example output# person id: f440c6b9-3f62-408d-a65d-9bcf6122386f
```

### find item by id
find will get the item by id, by default it will be read without creating an write lock, this can be changed by setting the arguments.

```php
//
$person = $db->person->find('f440c6b9-3f62-408d-a65d-9bcf6122386f');
$person->name;                    //  Kamile
$person->instagram;               //  @poes_kamille


// you can also use getAttr($key, $default=none)
// $person->getAttr('name');      //  Kamile

// using default
// $person->getAttr('specie', 'Unknown');  //  'Unknown'

```
### update
By default read() will read the item data without creating an write lock, unless $lock is set to true.
With update() you don't need to obtain a lock, it will do that for you.


```php

// open for writing:
$person->read(true); //  $lock argument = true
$person->specie = 'cat';
$person->write();


// you can do the same with update:
$person->update(['specie' => 'cat']);

```

### delete
```php
// delete using PersonItem method delete()
$person->delete();


// delete using PersonCollection method delete($id)
$db->person->find('f440c6b9-3f62-408d-a65d-9bcf6122386f');
```

### meta info
```php

// does item exist on disk (state is not new or deleted)
$person->exists();

// state is new when it's never saved using write() or update()
$person->is_new();

// state is created when it was first saved using write() or update()
$person->is_created();

// state is deleted after it was deleted using delete()
$person->is_deleted();


```


### hooks
```php
class PersonHooks {
	// Hooks are only called when they exist: only create those you need.

	function on_init($person) {
		// hint use is_new() or exists()
		if($person->is_new())
		{
			print("trying out hooks: on_init new person item");
		} else {
			print("trying out hooks: on_init existing person item");
		}

	}

	//
	// during write() the Hooks object will persist.
	//
	public function on_write($ref) {
		// hint use is_new() or exists()
		if($person->is_new())
		{
			print("trying out hooks: on_write new person item");
		} else {
			print("trying out hooks: on_write existing person item");
		}

	}

	public function on_write_success($ref) {
		// hint use is_created()
		if($person->is_created())
		{
			print("trying out hooks: on_write_success new/created person item");
		} else {
			print("trying out hooks: on_write_success existing person item");
		}
	}

	public function on_write_failed($ref) {
		// hint use is_new() or exists()
		if($person->is_new())
		{
			print("trying out hooks: on_write_failed new person item");
		} else {
			print("trying out hooks: on_write_failed existing person item");
		}
	}


	//
	// during delete() the Hooks object will persist.
	//
	public function on_delete($ref) {
	}

	public function on_delete_succes($ref) {
	}

	public function on_delete_failed($ref) {
	}

}
```


## classes and function

<!-- // ouput of "egrep 'class | function ' class.jsondata.php  | tr '{' ' '" -->
```
class JsonData
	public function __construct($orm=Null)
	public function addModel($model_name, $collection_class_name=false, $item_class_name=false, $hooks_class_name=false)

class JsonDataOrm
	static function setBaseDir($dir)
	public function addModel($db, $model_name, $collection_class_name=false, $item_class_name=false, $hooks_class_name=false)
	static function getModelName($ref)
	static function getCollectionClassName($ref)
	static function getItemClassName($ref)
	static function getHooksObject($ref)
	static function getFilePathPrefix($ref)
	static function getFilePath($ref, $type='json')
	static function getFilePathWithId($ref, $id, $type='json')
	public function exists($ref, $id, $type='json')
	public function as_json($ref, $add_meta_keys=['id','created_at'])
	public function genUuid()
	public function getFileTimeStamps($ref)
	public function lock($ref)
	public function haslock($ref)
	public function unlock($ref)
	public function write($ref)
	public function delete($ref, $id)

class JsonDataItem
	public function __construct($orm, $id=null, $read=true, $lock=false)
	public function __toString()
	public function setId($id=false)
	public function id()
	public function created_at($format='c')
	public function read_timestamps_in_meta()
	public function updated_at($format='c')
	public function accessed_at($format='c')
	public function hasMetaAttr($key)
	public function getMetaAttr($key, $defaul=null)
	private function setMetaAttr($key, $value)
	public function getAttr($key, $defaul=null)
	public function setAttr($key, $value)
	public function as_json($add_meta_keys=['id','created_at'])
	public function getFilePath($type='json')
	public function exists()
	public function is_new()
	public function is_created()
	public function is_deleted()
	public function read($lock=false)
	public function update($data)
	public function write()
	public function delete()

class JsonDataCollection
	public function __construct($orm)
	public function getMetaAttr($key, $defaul=null)
	public function setMetaAttr($key, $value)
	public function exists($id)
	public function find($id, $read=true, $lock=false)
	public function new($data=[])
	public function read($id, $lock=false)
	public function delete($id)

class jsonDataHooks
	// public function on_init($ref)
	// public function on_write($ref)
	// public function on_write_success($ref)
	// public function on_write_failed($ref)
	// public function on_delete($ref)
	// public function on_delete_succes($ref)
	// public function on_delete_failed($ref)

```
