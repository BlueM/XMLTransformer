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

	echo CBXMLTransformer::transformString(
		'<root><hello-world xml:lang="en" /></root>',
		'transform'
	);
