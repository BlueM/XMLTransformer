#!/usr/local/bin/php
<?php

	include_once 'CBXMLTransformer.php';

	function transform($tag, $attributes, $opening) {
		if ('hello-world' == $tag) {
			if (isset($attributes['xml:lang']) and
				'de' == $attributes['xml:lang']) {
				$str = 'Hallo Welt';
			} else {
				$str = 'Hello world';
			}
			return array(
				'tag'=>false,
				'insbefore'=>$str,
			);
		}

		if ('root' == $tag) {
			// We do not want ent enclosing <root> tags in the output
			return array('tag'=>false);
		}
	}

	echo CBXMLTransformer::transformString(
		'<root><hello-world xml:lang="de" /></root>',
		'transform'
	);
	// Will output “Hallo Welt”

	echo CBXMLTransformer::transformString(
		'<root><hello-world xml:lang="en" /></root>',
		'transform'
	);
	// Will output “Hello world”

	// Explanation: In addition to the last example, we return
	//              key “insbefore”, which will insert literal content,
	//              which in this example is set based on the xml:lang
	//              attribute.
