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

	private $isSysOp;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'KeyValue' );
		wfLoadExtensionMessages('KeyValue');
		global $wgUser;
		$this->isSysOp = in_array( "sysop", $wgUser->getEffectiveGroups() );
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

		if ($this->isSysOp && $wgRequest->getCheck('recreate')) {
			$keyValue = KeyValue::getInstance();
			$keyValue->dropTable();
			$keyValue->createTable();
			return $this->executeTableRecreatedPage();
		} 

		# The $par parameter should be safe (comes via executePath
		# and Title::getDBKey - Title::secureAndSplit 
		if ( !$par ) {
			return $this->executeMainPage();
		} 

		if ( $wgRequest->getCheck('csv') ) {
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
		$keyValue = KeyValue::getInstance();
		$categories = $keyValue->GetCategories();
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
		if ($this->isSysOp) {
			$this->addRecreateTableButton();
		}
		$wgOut->setPageTitle( wfMsg( 'keyvalue_categories' ) );
	}

	/**
     	 * Adds a "recreate tables" button to the output.
	 */
	public function addRecreateTableButton() {
		global $wgOut;
		$wgOut->addWikiText("\n");
		$wgOut->addHTML('<form method="post"><input type="submit" name="recreate" value="Recreate KeyValue table" /></form>');
	}

	/** 
	 * Renders the page shown after a table re-create.
	 */
	public function executeTableRecreatedPage() {
		global $wgOut;
		$wgOut->addWikiText( wfMsg( 'table_has_been_recreated' ) );
		$wgOut->addHTML( '<p><a href="" alt="'.wfMsg( 'proceed' ).'">'.wfMsg( 'proceed' ).'<a/></p>');
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
	
		$keyValue = KeyValue::getInstance();
		$kvis = $keyValue->getByCategory( $category );
		foreach ( $kvis as $kvi ) {
			$line = '* ';
			$line .= $kvi->key;
			$line .= ' = ';
			$line .= $kvi->value;
			$line .= " ''([[";
			$line .= $kvi->title->getFullText();
			$line .= "]])''";
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

		$keyValue = KeyValue::getInstance();
		$kvis = $keyValue->getByCategory( $category, false );
	
		header( "Pragma:  no-cache" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
		header( "Cache-Control: public" );
		header( "Content-Description: File Transfer" );
		header( "Content-type: text/csv" );
		header( "Content-Transfer-Encoding: binary" ); 
		header( "Content-Disposition: attachment; filename=\"$category.csv\"" );
		header( "Accept-Ranges: bytes" );  

		foreach ( $kvis as $kvi ) {
			$key = str_replace(',', '', $kvi->key);
			$value = str_replace(',', '', $kvi->value);
			echo("$key,$value\n");
		}
		die;
	}

}
