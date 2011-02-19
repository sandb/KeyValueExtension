<?php
# MediaWiki KeyValue extension
#
# Copyright 2011 Pieter Iserbyt <pieter.iserbyt@gmail.com>
#
# Released under GNU LGPL

# Alert the user that this is not a valid entry point to MediaWiki 
# if they try to access the special pages or extension file directly.
if ( !defined( 'MEDIAWIKI' ) ) {
	echo ( 'This is not a valid entry point to MediaWiki.' );
	exit ( 1 );
}

# define the message array
$messages = array();

# english messages 
$messages['en'] = array(
	'keyvalue' => 'KeyValue',
	'available_categories' => 'The following categories are currently in use:',
	'values' => 'values',
	'keyvalue_categories' => 'KeyValue categories',
	'download_category_as' => 'Download the data for this category as a',
	'csv_file' => 'csv file',
	'keyvalues_for' => 'KeyValues for',
	'return' => 'Return to',
	'table_has_been_recreated' => 'The KeyValue table has been recreated. Pages containing KeyValue instances will not show up in the special pages until they are saved again.',
	'proceed' => 'Proceed'
);

