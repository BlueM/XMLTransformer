CBXMLTransformer Overview
=========================

What is it?
--------------
CBXMLTransformer is a PHP class for transforming any kind of input XML into an output string. This output string does not have to be XML, but can also contain, for instance, HTML or plain text.

What kind of transformations can it perform?
----------------
CBXMLTransformer is able to …

* Remove tags, including or excluding the tag’s content
* Rename attributes
* Remove attributes
* Add attributes
* Change attributes’ values
* Insert content before and after a tag
* Insert content at the beginning or end of tag content
* Transform a tag including all of its content by passing it to a user-defined closure
* Perform any combination of the above

What is it good for?
--------------------
In my opinion, CBXMLTransformer performs very well if the input XML and the output to be produced are similarly structured. Moreover, if data from the input XML has to be processed by an existing PHP codebase, it is possibly cleaner and simpler to use CBXMLTransformer instead of XSL-T.

What is it not so good for?
----------------------------
When the input data has to be re-arranged, you are probably better off with XSL-T, as this is something that CBXMLTransformer does not provide. (Although to some extent it can be done with appropriate callback code.) Of course you are free to combine XSL-T with CBXMLTransformer to get the best of both worlds, if one is not enough.

How does it work
-----------------
You pass the input XML and the name of a callback function (or the name of a callback method or a closure) to CBXMLTransformer. For each tag (opening, closing or empty) the callback function will be called with the tag’s data as argument. The callback function returns – based on the given data – an array that contains information on the desired tag (should the tag be renamed, removed, and if the latter: with or without content?), on the desired attributes (removal, addition, renaming), on adding literal content and a closure that will be called after the transformation has been performed. All of the aforementioned return information is optional, and if you do not return anything, nothing is changed.

Examples
===========

Hello world
------------
	include_once 'CBXMLTransformer.php';
	echo CBXMLTransformer::transformString(
		'<root><element>Hello world</element></root>',
		function($tag, $attributes, $opening) {
			return array('tag'=>false);
		}
	);
	// Will output “Hello World”.
	// Explanation: Returning false for key “tag” will remove the tag,
	//              but keep its content.

Multilingual Hello world
---------------------------
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



Other examples
--------------
Coming soon …
