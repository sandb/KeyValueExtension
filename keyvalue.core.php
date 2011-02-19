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

	/** The mediawiki title instance of the page where this keyvalue is located. Optional. */
	public $title;

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
	private $isRedirect = false;

	/**
	 * Constructor.
	 */
	public function __construct($articleId, $isRedirect = false) {
		$this->articleId = $articleId;
		$this->kvs = array();
		$this->isRedirect = $isRedirect;
		$this->assertTable();
	}

	/**
	 * @return The current KeyValue instance
	 */
	public static function getInstance() {
		if (self::$instance == NULL) {
			global $wgTitle;
			self::$instance = new KeyValue($wgTitle->getArticleID(), $wgTitle->isRedirect());
		}
		return self::$instance;
	}

	/**
	 * Makes sure the table required exists. Does this both for mysql and 
	 * sqlite. If table is missing it gets created.
	 */
	private function assertTable() {
		if ($this->isRedirect) return;
		global $wgDBprefix;
		$dbr = wfGetDB( DB_SLAVE );
		$tableName = $dbr->tableName( self::tableName );
		$createTable = false;
		if ($dbr instanceof DatabaseMysql) {
			$tableName = str_replace( '`', '', $tableName );
			$resultWrapper = $dbr->query("show tables like '$tableName'", "KeyValue::assertTable");
			$createTable = $resultWrapper->numRows() < 1;

		} else if ($dbr instanceof DatabaseSqlite) {
			$resultWrapper = $dbr->select("sqlite_master", "name", array("type='table'", "name='$tableName'"), "KeyValue::assertTable");
			$createTable = $resultWrapper->numRows() < 1;
		}
		if ($createTable) {
			$this->createTable();
		}
	}

	/**
	 * Creates a new keyvalue table.
	 */
	public function createTable() {
		$dbw = wfGetDB( DB_MASTER );

		$tableName = $dbw->tableName( self::tableName );
		$indexName = $dbw->tableName( self::indexName );

		$tablesql = "CREATE TABLE $tableName ( article_id INT, kvcategory VARCHAR(255), kvkey VARCHAR(255), kvvalue TEXT)";
		$indexsql = "CREATE INDEX $indexName on $tableName (article_id, kvcategory)";

		$dbw->begin();
		$dbw->query( $tablesql );
		$dbw->query( $indexsql );
		$dbw->commit();
	}

	/**
	 * Drops the current keyvalue table.
	 */
	public function dropTable() {
		$dbw = wfGetDB( DB_MASTER );
		$tableName = $dbw->tableName( self::tableName );
		$dbw->begin();
		$dbw->query("drop table $tableName", "KeyValue::dropTable()" );
		$dbw->commit();
	}

	/**
	 * Adds a key value combination to be stored for this page when store is 
	 * called.
	 */
	public function add( $category, $key, $value ) {
		if ($this->isRedirect) return;
		$this->kvs[] = new KeyValueInstance( $category, $key, $value );
	}

	private function removeDuplicates() {
		if ( count( $this->kvs ) == 0 ) {
			return;
		}
		usort( $this->kvs, array('KeyValueInstance', 'compareKey') );
		$result = array();
		$prev = $this->kvs[0];
		$result[] = $prev;
		for ( $i = 1; $i < count($this->kvs); $i++ ) {
			if ( KeyValueInstance::compareKey($prev, $this->kvs[$i]) == 0 ) {
				continue;
			}
			$prev = $this->kvs[$i];
			$result[] = $prev;
		}
		$this->kvs = $result;
	}

	/**
	 * Writes the added key/values to the database. Any keyvalues 
	 * previously stored for the specified article id will be deleted if no 
	 * longer present. 
	 */
	public function store() {
		$this->removeDuplicates();
		if ($this->isRedirect) return;
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
			'KeyValue::getCategories()',
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
	 * @param $loadTitles If true, will fill in the title property of each KeyValueInstance
	 *	with a title object matching it's page.
	 * @return an array of KeyValueInstance objects, with the title property set.
	 */
	public function getByCategory( $category, $loadTitles = true ) {
		$dbr = wfGetDB( DB_SLAVE );
		$result = array();
		
		$res = $dbr->select(
			$dbr->tableName( self::tableName ),
			array( 'article_id', 'kvcategory', 'kvkey', 'kvvalue' ),
			array( "kvcategory = \"$category\"" ),
			'KeyValue::getByCategory($category)',
			array( "ORDER BY" => "kvkey, kvvalue" )
		);

		if ( !$res ) {
			return $result;
		}

		$titles = array();
		while ( $row = $dbr->fetchRow( $res ) ) {
			$kv = new KeyValueInstance($category, $row['kvkey'], $row['kvvalue']);
			if ( $loadTitles ) {
				if ( ! isset( $titles[ $row['article_id'] ] ) ) {
					$titles[ $row['article_id'] ] = Title::newFromId( $row['article_id'] );
				}
				$kv->title = $titles[ $row['article_id'] ];
			}
			$result[] = $kv; 
		}

		return $result;
	}
}

