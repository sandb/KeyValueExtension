<?php
# MediaWiki KeyValue extension v0.18
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

# Alert the user that this is not a valid entry point to MediaWiki 
# if they try to access the special pages or extension file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	echo ( 'This is not a valid entry point to MediaWiki.' );
	exit ( 1 );
}

# Extension info
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'KeyValue',
	'description' => 'Enables setting data in retrievable category:Key=>Value pairs',
	'url' => 'http://www.mediawiki.org/wiki/Extension:KeyValue',
	'author' => 'Pieter Iserbyt',
	'version' => '0.18',
);

# Connecting the hooks
$wgHooks['ParserFirstCallInit'][] = "keyValueParserFirstCallInit";
$wgHooks['LanguageGetMagic'][] = 'keyValueLanguageGetMagic';
$wgHooks['ArticleSave'][] = 'keyValueArticleSave';
$wgHooks['ArticleSaveComplete'][] = 'keyValueArticleSaveComplete';
$wgHooks['ArticleDeleteComplete'][] = 'keyValueArticleDeleteComplete';

# Special page initialisations
$dir = dirname(__FILE__) . '/';

# Define autoloading for classes KeyValue, KeyValueInstance and SpecialKeyValue
$wgAutoloadClasses['KeyValue'] = $dir . 'keyvalue.core.php'; 
$wgAutoloadClasses['KeyValueInstance'] = $dir . 'keyvalue.core.php'; 
$wgAutoloadClasses['SpecialKeyValue'] = $dir . 'keyvalue.body.php'; 

# Location of the messages file
$wgExtensionMessagesFiles['KeyValue'] = $dir . 'keyvalue.i18n.php'; 
# Register the special page and it's class
$wgSpecialPages['KeyValue'] = 'SpecialKeyValue'; 
# Set the group for the special page
$wgSpecialPageGroups['KeyValue'] = 'other';

$keyValueIsSaving = false;

/**
 * Implements the initialisation of the extension for parsing. Is connected
 * to the ParserFirstCallInit hook.
 *
 * @return true
 */
function keyValueParserFirstCallInit() {
	global $wgParser;
	$wgParser->setFunctionHook('keyvalue', 'keyValueRender');
	return true;
}

/**
 * Registers the magic words for this extions (in this case 'keyvalue').
 * Is connected to the LanguageGetMagic hook.
 *
 * @param $magicWords The magicWords array
 * @param $langCode Site language code
 * @return true
 */
function keyValueLanguageGetMagic( &$magicWords, $langCode ) {
	$magicWords['keyvalue'] = array( 0, 'keyvalue' );
	return true;
}

/**
 * Called before the article is being saved. Will set $keyValueIsSaving 
 * to true so keyValue will be active in remembering and storing.
 * 
 * $article: the article (Article object) being saved
 * $user: the user (User object) saving the article
 * $text: the new article text
 * $summary: the edit summary
 * $minor: minor edit flag
 * $watchthis: watch the page if true, unwatch the page if false, do nothing if null (since 1.17.0)
 * $sectionanchor: not used
 * $flags: bitfield, see documentation for details
 * &$status: not used
 */
function keyValueArticleSave( &$article, &$user, &$text, &$summary,
 $minor, $watchthis, $sectionanchor, &$flags, &$status ) { 
	global $keyValueIsSaving;
	$keyValueIsSaving = true;
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
function keyValueArticleSaveComplete( &$article, &$user, $text, $summary, 
 $minoredit, $watchthis, $sectionanchor, &$flags, $revision, 
 &$status, $baseRevId ) {
	global $keyValueIsSaving;
 	if ($keyValueIsSaving) {
		$keyValue = KeyValue::getInstance();
		$keyValue->store(); 
	}
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
function keyValueArticleDeleteComplete( &$article, &$user, $reason, $id ) {
	global $keyValueIsSaving;
 	if ($keyValueIsSaving) {
		$keyValue = KeyValue::getInstance();
		$keyValue->store(); 
	}
	return true;
}

/**
 * Renders the keyvalue function, returns the value back as the result.
 * Is connected to the parser as the render function for the "keyvalue"
 * magic word.
 *
 * @param $parser The parser instance
 * @param $category The value for the category of the keyvalue, defaults to empty.
 * @param $key The value for the key of the keyvalue, defaults to empty.
 * @param $value The value of the keyvalue, defaults to empty
 * @return $value
 */
function keyValueRender( $parser, $category = '', $key = '', $value = '' ) {
	global $keyValueIsSaving;
 	if ($keyValueIsSaving) {
		$keyValue = KeyValue::getInstance();
		$keyValue->add( $category, $key, $value ); 
	}
	return $value;
}

