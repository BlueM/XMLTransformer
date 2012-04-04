#!/usr/local/bin/php
<?php

	include_once 'CBXMLTransformer.php';


	echo CBXMLTransformer::transformString(
		'<root xml:id="abc"><bla xml:id="def"/></root>',
			function($tag, $attributes, $opening) {
				return array('@xml:id'=>'@id');
			}
	);
	// Will output “<root id="abc"><bla id="def" /></root>”

	// Explanation: In addition to the last example, we return
	//              key “insbefore”, which will insert literal content,
	//              which in this example is set based on the xml:lang
	//              attribute.
