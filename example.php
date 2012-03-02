#!/usr/local/bin/php
<?php

include_once 'CBXMLTransformer.php';

echo CBXMLTransformer::transformString(
	'<root><element>Hello world</element></root>',
	function($tag, $attributes, $opening) {
		return array('tag'=>false);
	}
);

