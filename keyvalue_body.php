<?php

class SpecialMyExtension extends SpecialPage {

	function __construct() {
		parent::__construct( 'MyExtension' );
		wfLoadExtensionMessages('MyExtension');
	}
 
	function execute( $par ) {
		global $wgRequest, $wgOut;
 
		$this->setHeaders();
 
		# Get request data from, e.g.
		$param = $wgRequest->getText('param');
 
		# Do stuff
		# ...
		$output="Hello world!";
		$wgOut->addWikiText( $output );
	}

}
