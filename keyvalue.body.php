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

/**
 * Special page implementation for the KeyValue extension
 */
class SpecialKeyValue extends SpecialPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'KeyValue' );
		wfLoadExtensionMessages('KeyValue');
	}
 
 	/**
	 * execute implementation. Depending on url, will either
	 * do the main page, a specific category, or a csv file.
	 *
	 * @param $par The name of the subpage if present.
	 */
	public function execute( $par ) {
		global $wgRequest, $wgOut;
 
		$this->setHeaders();

		# The $par parameter should be safe (comes via executePath
		# and Title::getDBKey - Title::secureAndSplit 
		if ( !$par ) {
			return $this->executeMainPage();
		} 
		
		$csv = $wgRequest->getCheck('csv');
		if ( $csv ) {
			return $this->executeCategoryCsv($par);
		}

		return $this->executeCategoryPage($par);
	}

	/**
	 * Renders the main special page, renders a list of used
	 * categories.
	 */
	public function executeMainPage() {
		global $wgOut;
		$wgOut->addWikiText( wfMsg( 'available_categories' ) );
		$categories = keyValueGetCategories();
		foreach ( $categories as $category ) {
			$line = '* [[';
			$line .= $this->getTitle( $category->category );
			$line .= '|';
			$line .= $category->category;
			$line .= ']] (';
			$line .= $category->count;
			$line .= ' ';
			$line .= wfMsg( 'values' );
			$line .= ')';
			$wgOut->addWikiText( $line );
		}
		$wgOut->setPageTitle( wfMsg( 'keyvalue_categories' ) );
	}

	/**
	 * Renders the page for a specific category.
	 *
	 * @param $category The category to render
	 */
	public function executeCategoryPage( $category ) {
		global $wgOut;

		$parentLink = substr( $this->getTitle( $category ), 0, - strlen( $category) - 1 );
		$csvLink = $this->getTitle( $category )->getFullURL( array( "csv" => "true" ) );
		
		$line = wfMsg( 'download_category_as' );
		$line .= ' [';
		$line .= $csvLink;
		$line .= ' ';
		$line .= wfMsg( 'csv_file' );
		$line .= '].';
		$wgOut->addWikiText( $line );
		
		$kvis = keyValueGetByCategory( $category );
		foreach ( $kvis as $kvi ) {
			$line = '* ';
			$line .= $kvi->key;
			$line .= ' = ';
			$line .= $kvi->value;
			$wgOut->addWikiText( $line );
		}
		
		$line = wfMsg( 'return' );
		$line .= ' [[';
		$line .= $parentLink;
		$line .= ']]';
		$wgOut->addWikiText( $line );

		$wgOut->setPageTitle( wfMsg( 'keyvalues_for' ) . " \"$category\"" );
	}

	/**
	 * Returns a csv file that gets downloaded for the specified
	 * category.
	 * 
	 * @param $category The category for the csv file
	 */
	public function executeCategoryCsv( $category ) {
		global $wgOut, $wgRequest;

		$kvis = keyValueGetByCategory( $category );
	
		# should be safe, does not end up in browser in any case
		$delimiter = $wgRequest->getText('delimiter');
		$delimiter = $delimiter ? $delimiter : ',';

		# should be safe, does not end up in browser in any case
		$enclosure = $wgRequest->getText('enclosure');
		$enclosure = $enclosure ? $enclosure : ',';
		
		header( "Pragma:  no-cache" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
		header( "Cache-Control: public" );
		header( "Content-Description: File Transfer" );
		header( "Content-type: text/csv" );
		header( "Content-Transfer-Encoding: binary" ); 
		header( "Content-Disposition: attachment; filename=\"$category.csv\"" );
		header( "Accept-Ranges: bytes" );  

		$file = fopen( 'php://output', 'w' );
		foreach ( $kvis as $kvi ) {
			fputcsv( 
				$file, 
				array( $kvi->key, $kvi->value ),
				$delimiter,
				$enclosure
			);
		}
		fclose( $file );
		die;
	}

}
