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
 * Holds the KeyValue core functions. All of this class functions are
 * static.
 */
class KeyValue {

	/** Tablename define. */
	const tableName = 'keyvalue';
	const indexName = 'keyvalueindex';

	/**
	 * Takes the text of an article and parses it to find all the keyvalue
	 * functions. Returns all keyvalue's found as an array of 
	 * keyValueInstance objects, or an empty array if none found.
	 *
	 * @param &$text The article text
	 * @param an array with zero or more keyValueInstances
	 */
	public static function getValues( &$text ) {
		$result = array();
		preg_match_all( '/{{\s*#keyvalue:\s*([^}]*)\s*}}/', $text, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
		
			# if no params present, badly defined, skip
			if ( count($match) < 2 ) {
				continue;
			}
			
			$params = preg_split( '/\s*\|\s*/', trim($match[1]) );
			
			# if not exactly 3 params present, badly defined, skip
			if ( count($params) != 3 ) {
				continue;
			}
			
			$result[] = new KeyValueInstance( $params[0], $params[1], $params[2] );
		}
		return $result;
	}

	/**
	 * Wrapper for database functions, that will try to auto-create the 
	 * keyvalue table and call the supplied function a second time, if
	 * an error occured. This causes this extension to have auto-create 
	 * table functionality without any performance penalty.
	 *
	 * @param $function The function to call. Should have at least expect a database as first parameter.
	 * @param $dbtype Index of the connection to get. Same as for wfGetDB, either DB_MASTER or DB_SLAVE.
	 * @param ... Any extra parameters will be passed on to the supplied function
	 * @return The result of the function called.
	 */
	private static function dbTry($function, $dbtype ) {

		$db = wfGetDB( $dbtype );

		# all functions called should be static functions in this class.
		# so adding the 'self::' prefix
		$function = "self::$function";

		# Get all params from the function as an array. Remove two parameters at the 
		# front ($function and $db) and add the database link.
		$args = func_get_args();
		$args = array_slice( $args, 2 );
		$args = array_merge( array( $db ), $args );

		# set the db to ignore errors
		$oldIgnore = $db->ignoreErrors( true );
		
		# call the requested function with the db and the requested args
		$result = call_user_func_array($function, $args);

		# if no error occured, return the result
		if ( ! $db->lastErrno() ) {
			# restore previous error ignore status
			$db->ignoreErrors( $oldIgnore );

			#return the result
			return $result;
		}
		# !! an error occured, try again, after trying to create the table
			
		# save the current error
		$lastError = $db->lastError();
		$lastErrno = $db->lastErrno();
		$lastQuery = $db->lastQuery();

		# This is weird, but the only way i found to reset the error state of the database:
		# the loadbalancer is returned by wfGetLB, and this instance manages connections to
		# the database. We close the current connection to the database by calling it's
		# "closeConnecton" (yes, with a spelling error) to remove the connection from it's
		# pool. After that we again ask for a connection to the db, and a new, error-state
		# free connection is created.
		wfGetLB()->closeConnecton($db);
		$db = wfGetDB( $dbtype );
		$args[0] = $db;
		
		# since new connection; reset the new db connection to ignore errors again
		$oldIgnore = $db->ignoreErrors( true );

		# on error, maybe the table had not yet been created, 
		# so try to auto-create table 
		self::createTable( $db );
		
		# restore previous error ignore status
		$db->ignoreErrors( $oldIgnore );

		# and try calling the function again, maybe it works now
		$result = call_user_func_array($function, $args);

		return $result;
	}

	/**
	 * Writes an array of keyValue instances to the database. Autocreates the
	 * table if it's missing. Any keyvalues store for the specified article id
	 * will be deleted if no longer present. Autocreates table if missing.
	 * 
	 * @param $articleId The id of the article to register the keyvalues under
	 * @param $keyValues An array of zero or more keyvalues to write to the db.
	 */
	public static function store( $articleId, $keyValues = array() ) {
		return self::dbTry( 'storeFromDb', DB_MASTER, $articleId, $keyValues );
	}

	/**
	 * Writes an array of keyValue instances to the database. Autocreates the
	 * table if it's missing. Any keyvalues store for the specified article id
	 * will be deleted if no longer present.
	 * 
	 * @param $dbw The database to write to
	 * @param $articleId The id of the article to register the keyvalues under
	 * @param $keyValues An array of zero or more keyvalues to write to the db.
	 */
	private static function storeFromDb( $dbw, $articleId, $keyValues ) {
		$dbw->begin();
		$dbw->delete( 
			$dbw->tableName( self::tableName ), 
			array( "article_id" => $articleId ) 
		);
		foreach($keyValues as $kv) {
			$dbw->insert( 
				$dbw->tableName( self::tableName ), 
				array( 
					"article_id" => $articleId, 
					"kvcategory" => $kv->category,
					"kvkey" => $kv->key,
					"kvvalue" => $kv->value
				)
			);
		}
		$dbw->commit();
	}

	/**
	 * Returns the list of categories in use in the wiki. Autocreates table if missing.
	 *
	 * @return array of category objects having ::category and ::count fields.
	 */
	public static function getCategories() {
		return self::dbTry( 'getCategoriesFromDb', DB_SLAVE );
	}

	/**
	 * Returns the list of categories in use in the wiki.
	 *
	 * @param $dbr The database to read from
	 * @return array of category objects having ::category and ::count fields.
	 */
	private static function getCategoriesFromDb( $dbr ) {

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
	public static function getByCategory( $category ) {
		return self::dbTry( 'getByCategoryFromDb', DB_SLAVE, $category );
	}

	/**
	 * Returns all key-values for a given category. Results are 
	 * returned as an array of KeyValueInstance objects. No results
	 * will return an empty array.
	 *
	 * @param $dbr The database to read from
	 * @param $category The category for which to return values.
	 * @return an array of KeyValueInstance objects.
	 */
	private static function getByCategoryFromDb( $dbr, $category ) {
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

	/**
	 * Creates a new keyvalue table.
	 *
	 * @param $dbw The database to create the table in
	 */
	private static function createTable( $dbw ) {

		$tablename = $dbw->tableName( self::tableName );
		$indexname = $dbw->tableName( self::indexName );

		$tablesql = "CREATE TABLE $tablename ( article_id INT, kvcategory VARCHAR(255), kvkey VARCHAR(255), kvvalue TEXT)";
		$indexsql = "CREATE INDEX $indexname on $tablename (article_id, kvcategory)";

		$dbw->begin();
		$dbw->query($tablesql);
		$dbw->query($indexsql);
		$dbw->commit();
	}

}

?>
