<?php

class SpecialKeyValue extends SpecialPage {

	public function __construct() {
		parent::__construct( 'KeyValue' );
		wfLoadExtensionMessages('KeyValue');
	}
 
	public function execute( $par ) {
		global $wgRequest, $wgOut;
 
		$this->setHeaders();
 
		if ( !$par ) {
			return $this->executeMainPage();
		} 
		
		$csv = $wgRequest->getCheck('csv');
		if ( $csv ) {
			return $this->executeCategoryCsv($par);
		}

		return $this->executeCategoryPage($par);
		

	}

	public function executeMainPage() {
		global $wgOut;
		$wgOut->addWikiText( wfMsg( 'available_categories' ) );
		$categories = keyValueGetCategories();
		foreach ( $categories as $category ) {
			$line = '* [[';
			$line .= $this->getTitle($category->category);
			$line .= '|';
			$line .= $category->category;
			$line .= ']] (';
			$line .= $category->count;
			$line .= ' values)';
			$wgOut->addWikiText( $line );
		}
		$wgOut->setPageTitle("KeyValue categories");
	}

	public function executeCategoryPage($category) {
		global $wgOut;
		$kvis = keyValueGetByCategory($category);
		foreach ( $kvis as $kvi ) {
			$line = '* ';
			$line .= $kvi->key;
			$line .= ' = ';
			$line .= $kvi->value;
			$wgOut->addWikiText( $line );
		}
		$wgOut->setPageTitle("KeyValues for \"$category\"");
	}

	public function executeCategoryCsv( $category ) {
		global $wgOut, $wgRequest;

		$kvis = keyValueGetByCategory( $category );
		
		$delimiter = $wgRequest->getText('delimiter');
		$delimiter = $delimiter ? $delimiter : ',';

		$enclosure = $wgRequest->getText('enclosure');
		$enclosure = $enclosure ? $enclosure : ',';
		
		header( "Pragma:  no-cache" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
		header( "Cache-Control: public" );
		header( "Content-Description: File Transfer" );
		header( "Content-type: text/csv" );
		header( "Content-Transfer-Encoding: binary" ); 
		header( "Content-Disposition: attachment; filename=\"keyvalues.csv\"" );
		header( "Accept-Ranges: bytes" );  

		$file = fopen( 'php://output', 'w' );
		foreach ( $kvis as $kvi ) {
			fputcsv( 
				$file, 
				array( $kvi->category, $kvi->key, $kvi->value ),
				$delimiter,
				$enclosure
			);
		}
		fclose( $file );
		die;
	}

}
