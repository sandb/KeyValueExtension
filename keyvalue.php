<?php
# MediaWiki KeyValue extension v0.2
#
# Copyright 2011 Pieter Iserbyt <pieter.iserbyt@gmail.com>
#
# Released under GNU LGPL
#
# To install, copy the extension to your extensions directory,and 
# add the following line:
# require_once( "$IP/extensions/keyvalue/keyvalue.php" );
# to the bottom of your LocalSettings.php
# The required table structure should be auto-created on first use.
#
# requires PHP 5

# Alert the user that this is not a valid entry point to MediaWiki 
# if they try to access the special pages or extension file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is not a valid entry point to MediaWiki.' );
}

/** Tablename define. */
define( "KEYVALUE_TABLE", "keyvalue" );

# Extension info
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'KeyValue',
	'description' => 'Enables setting data in retrievable category:Key=>Value pairs',
	'url' => 'http://www.mediawiki.org/wiki/Extension:KeyValue',
	'author' => 'Pieter Iserbyt',
	'version' => '0.1',
);

# Connecting the hooks
$wgHooks['ParserFirstCallInit'][] = "keyValueParserFirstCallInit";
$wgHooks['LanguageGetMagic'][] = 'keyValueLanguageGetMagic';
$wgHooks['ArticleSaveComplete'][] = 'keyValueSaveComplete';
$wgHooks['ArticleDeleteComplete'][] = 'keyValueDeleteComplete';

/**
 * Implements the initialisation of the extension. Is connected
 * to the ParserFirstCallInit hook.
 *
 * @return true
 */
function keyValueParserFirstCallInit() {
	global $IP, $wgParser, $wgHooks, $keyValueData, $wgMessageCache;
	require_once($IP . "/includes/SpecialPage.php");
	$wgParser->setFunctionHook('define', 'keyValueRender');
	return true;
}

/**
 * Registers the magic words for this extions (in this case 'define').
 * Is connected to the LanguageGetMagic hook.
 *
 * @param $magicWords The magicWords array
 * @param $langCode Site language code
 * @return true
 */
function keyValueLanguageGetMagic( &$magicWords, $langCode ) {
	$magicWords['define'] = array( 0, 'define' );
	return true;
}
 
/**
 * Called when an article is saved, updates all the keyvalues defined in the
 * article. Is connected to the ArticleSaveComple hook.
 *
 * @param &$article The article that is being saved, used to get the id of the article.
 * @param &$user Not used.
 * @param $text The text of the article that is going to be saved.
 * @param $summary Not used.
 * @param $minoredit Not used.
 * @param $watchthis Not used.
 * @param $sectionanchor Not used.
 * @param &$flags Not used.
 * @param $revision Not used.
 * @param &$status Not used.
 * @param $baseRevId Not used.
 * @return true
 */
function keyValueSaveComplete( &$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId ) {
	$kvs = keyValueGetValues( $text );
	$kvs = keyValueMakeUnique( $kvs );
	keyValueDbTry( 'keyValueStore', $article->getID(), $kvs);
	return true;
}

/**
 * Is connected to the ArticleDeleteComplete hook.
 *
 * @param &$article The article that is being saved, used to get the id of the article.
 * @param &$user Not used.
 * @param $reason Not used.
 * @param $id Not used.
 * @return true 
 */
function keyValueDeleteComplete( &$article, &$user, $reason, $id ) {
	keyValueDbTry( 'keyValueStore', $article->getID());
	return true;
}

/**
 * Renders the keyvalue function, returns the value back as the result.
 * Is connected to the parser as the render function for the "define"
 * magic word.
 *
 * @param $parser The parser instance
 * @param $category The value for the category of the keyvalue, defaults to empty.
 * @param $key The value for the key of the keyvalue, defaults to empty.
 * @param $value The value of the keyvalue, defaults to empty
 * @return $value
 */
function keyValueRender( $parser, $category = '', $key = '', $value = '' ) {
	return $value;
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
 * Takes the text of an article and parses it to find all the keyvalue
 * functions. Returns all keyvalue's found as an array of 
 * keyValueInstance objects, or an empty array if none found.
 *
 * @param &$text The article text
 * @param an array with zero or more keyValueInstances
 */
function keyValueGetValues( &$text ) {
	$result = array();
	preg_match_all( '/{{\s*#define:\s*([^}]*)\s*}}/', $text, $matches, PREG_SET_ORDER );
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
 * Prevents any duplicate keys (category, key) in the array.
 *
 * @param $keyValues an array of KeyValueInstance objects
 * @return an array of keyValueInstance objects that contain no duplicate keys.
 */
function keyValueMakeUnique( $keyValues ) {
	$result = array();
	if ( count( $keyValues ) === 0 ) {
		return $result;
	}

	usort($keyValues, array('KeyValueInstance', 'compareKey'));

	$last = array_shift( $keyValues );
	$result[] = $last;
	foreach ($keyValues as $kv) {
		if ( KeyValueInstance::compareKey( $last, $kv ) === 0 ) {
			continue;
		}
		$last = $kv;
		$result[] = $last;
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
 * @param ... Any extra parameters will be passed on to the supplied function
 * @return The result of the function called.
 */
function keyValueDbTry($function) {

	# get extra arguments and replace the first one (the 
	# function) with the database.
	$args = func_get_args();
	$dbw = wfGetDB( DB_MASTER );
	$args[0] = $dbw;

	# set the db to ignore errors
	$oldIgnore = $dbw->ignoreErrors( true );
	
	# call the requested function with the db and the requested args
	$result = call_user_func_array($function, $args);
	
	# if an error occured, try again, after trying to create the table
	if ( $dbw->lastErrno() ) {
		# on error, maybe the table had not yet been created, 
		# so try to auto-create table 
		keyValueCreateTable( $dbw );
		
		# and try calling the function again, maybe it works now
		$result = call_user_func_array($function, $args);
	}

	# restore previous error ignore status
	$dbw->ignoreErrors( $oldIgnore );

	# if an error occured after retry, push the error onwards
	if ( $dbw->lastErrno() ) {
		$dbw->reportQueryError( 
			$dbw->lastError(), 
			$dbw->lastErrno(), 
			$dbw->lastQuery(), 
			$function );
	}

	return $result;
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
function keyValueStore( $dbw, $articleId, $keyValues ) {
	$dbw->begin();
	$dbw->delete( 
		$dbw->tableName( KEYVALUE_TABLE ), 
		array( "article_id" => $articleId ) 
	);
	foreach($keyValues as $kv) {
		$dbw->insert( 
			$dbw->tableName( KEYVALUE_TABLE ), 
			array( 
				"article_id" => $articleId, 
				"category" => $kv->category,
				"key" => $kv->key,
				"value" => $kv->value
			)
		);
	}
	$dbw->commit();
}

/**
 * Creates a new keyvalue table.
 *
 * @param $dbw The database to create the table in
 */
function keyValueCreateTable( $dbw ) {
	$sql = 'CREATE TABLE ';
	$sql .= $dbw->tableName( KEYVALUE_TABLE );
	$sql .= ' ( article_id INT, category VARCHAR(255), key VARCHAR(255), value TEXT, PRIMARY KEY(article_id, category, key))';
	$dbw->begin();
	$dbw->query($sql);
	$dbw->commit();
}

/**
 * Helper function, turns an array of keyValueInstances into a printable
 * string.
 *
 * @param &$keyValueInstanceArray an array of keyValueInstance objects
 * @return a string 
 */
function keyValueIaToString( &$keyValueInstanceArray ) {
	$result = array(); 
	foreach ( $keyValueInstanceArray as $kv ) {
		$result[] = $kv->toString();
	}
	$result = 'Size: ' . count( $keyValueInstanceArray ) . ' - ' . implode( ', ', $result );
	return $result;
}

?>
