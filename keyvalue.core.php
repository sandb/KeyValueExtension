<?php
# MediaWiki KeyValue extension
#
# Copyright 2011 Pieter Iserbyt <pieter.iserbyt@gmail.com>
#
# Released under GNU LGPL
#

# Alert the user that this is not a valid entry point to MediaWiki 
# if they try to access the special pages or extension file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	echo ( 'This is not a valid entry point to MediaWiki.' );
	exit ( 1 );
}

/**
 * A class that holds the data for one KeyValue instance: a category, a key
 * and a value.
 */
class KeyValueInstance {

	/** The category, used to group different key values together. A string of max 255 chars. */
	public $category;

	/** The key. Used to identify a value. A string of max 255 chars. */
	public $key;

	/** The value. A string of max 4kb size. */
	public $value;

	/**
	 * Constructs a new instance, with the supplied values
	 * @param $category The category to store
	 * @param $key The key to store
	 * @param $value The value to store
	 */
	public function __construct( $category, $key, $value ) {
		$this->category = $category;
		$this->key = $key;
		$this->value = $value;
	}

	/**
	 * Returns a string readable presentation of the instance.
	 * @return a string in the format of [category->key=value].
	 */
	public function toString() {
		return "[$this->category->$this->key=$this->value]";
	}

	/**
	 * Compares two KeyValueInstances on primary key (category, key). 
	 * If the primary key is equal but the value is different this function will
	 * still consider both instances as equal.
	 *
	 * @param $kv1 The first KeyValueInstance
	 * @param $kv2 The second KeyValueInstance
	 * @return < 1 when $kv1 is smaller than $kv2, 0 if equal and > 1 if $kv1 is greater than $kv2
	 */
	static public function compareKey( $kv1, $kv2 ) {
		$result = strcmp($kv1->category, $kv2->category);
		if ($result != 0) {
			return $result;
		}
		return strcmp($kv1->key, $kv2->key);
	}

}

/**
 * Core KeyValue class, contains all functionality to write and read from db.
 */
class KeyValue{

	/** Table and index name defines. */
	const tableName = 'keyvalue';
	const indexName = 'keyvalueindex';

	private static $instance = NULL;
	private $articleId;
	private $kvs;

	/**
	 * Constructor.
	 */
	public function __construct($articleId) {
		$this->articleId = $articleId;
		$this->kvs = array();
		$this->assertTable();
	}

	/**
	 * @return The current KeyValue instance
	 */
	public static function getInstance() {
		if (self::$instance == NULL) {
			global $wgTitle;
			$articleId = $wgTitle->getArticleID();
			syslog( LOG_INFO, "ArticleID=$articleId");
			self::$instance = new KeyValue($articleId);
		}
		return self::$instance;
	}

	/**
	 * Makes sure the table required exists. Does this both for mysql and 
	 * sqlite. If table is missing it gets created.
	 */
	private function assertTable() {
		$db = wfGetDB( DB_SLAVE );
		$createTable = false;
		if ($db instanceof DatabaseMysql) {
			$resultWrapper = $db->query("show tables like '".self::tableName."'", "KeyValue::assertTable");
			$createTable = $resultWrapper->numRows() < 1;

		} else if ($db instanceof DatabaseSqlite) {
			$resultWrapper = $db->select("sqlite_master", "name", array("type='table'", "name='".self::tableName."'"), "KeyValue::assertTable");
			$createTable = $resultWrapper->numRows() < 1;
		}
		if ($createTable) {
			$this->createTable();
		}
	}

	/**
	 * Creates a new keyvalue table.
	 */
	private function createTable() {
		$dbw = wfGetDB( DB_MASTER );

		$tablename = $dbw->tableName( self::tableName );
		$indexname = $dbw->tableName( self::indexName );

		$tablesql = "CREATE TABLE $tablename ( article_id INT, kvcategory VARCHAR(255), kvkey VARCHAR(255), kvvalue TEXT)";
		$indexsql = "CREATE INDEX $indexname on $tablename (article_id, kvcategory)";

		$dbw->begin();
		$dbw->query( $tablesql );
		$dbw->query( $indexsql );
		$dbw->commit();
	}

	/**
	 * Adds a key value combination to be stored for this page when store is 
	 * called.
	 */
	public function add( $category, $key, $value ) {
		$this->kvs[] = new KeyValueInstance( $category, $key, $value );
	}

	/**
	 * Writes the added key/values to the database. Any keyvalues 
	 * previously stored for the specified article id will be deleted if no 
	 * longer present. 
	 */
	public function store() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();
		$dbw->delete( 
			$dbw->tableName( self::tableName ), 
			array( "article_id" => $this->articleId ) 
		);
		foreach($this->kvs as $kv) {
			$dbw->insert( 
				$dbw->tableName( self::tableName ), 
				array( 
					"article_id" => $this->articleId, 
					"kvcategory" => $kv->category,
					"kvkey" => $kv->key,
					"kvvalue" => $kv->value
				)
			);
		}
		$dbw->commit();
	}

	/**
	 * Returns the list of categories in use in the wiki.
	 *
	 * @return array of category objects having ::category and ::count fields.
	 */
	public function getCategories() {

		$dbr = wfGetDB( DB_SLAVE );
		$result = array();
		
		$res = $dbr->select(
			$dbr->tableName( self::tableName ),
			array( "kvcategory as category", "count(kvcategory) as count" ),
			'',
			'keyValueGetCategories',
			array( "GROUP BY" => "kvcategory" )
		);

		if ( !$res ) {
			return $result;
		}

		while ( $row = $dbr->fetchObject( $res ) ) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Returns all key-values for a given category. Results are 
	 * returned as an array of KeyValueInstance objects. No results
	 * will return an empty array. Autocreates table if missing.
	 *
	 * @param $category The category for which to return values.
	 * @return an array of KeyValueInstance objects.
	 */
	public function getByCategory( $category ) {
		$dbr = wfGetDB( DB_SLAVE );
		$result = array();
		
		$res = $dbr->select(
			$dbr->tableName( self::tableName ),
			array( 'kvcategory', 'kvkey', 'kvvalue' ),
			array( "kvcategory = \"$category\"" ),
			'keyValueGetByCategory'
		);

		if ( !$res ) {
			return $result;
		}

		while ( $row = $dbr->fetchRow( $res ) ) {
			$result[] = new KeyValueInstance($category, $row['kvkey'], $row['kvvalue']);
		}

		return $result;
	}
}

?>
