XMLTransformer Overview
=========================

What is it?
--------------
XMLTransformer is a PHP class for transforming any kind of input XML into an output string. This output string does not have to be XML, but can also be, for instance, HTML or plain text.


What kind of transformations can it perform?
----------------
XMLTransformer is able to …

* Remove tags, including or excluding the tag’s content
* Rename attributes
* Remove attributes
* Add attributes
* Change attributes’ values
* Insert content before and after a tag
* Insert content at the beginning or end of tag content
* Transform a tag including all of its content by passing it to a user-defined closure
* Perform any combination of the above
* Modify the content of text nodes

What is it good for?
--------------------
In my opinion, XMLTransformer performs very well if the input XML and the output to be produced are similarly structured. Moreover, if data from the input XML has to be processed by an existing PHP codebase, it is possibly cleaner and simpler to use XMLTransformer instead of XSL-T.

What is it not so good for?
----------------------------
When the input data has to be re-arranged, you are probably better off with XSL-T, as this is something that XMLTransformer does not provide. (Although to some extent it can be done with appropriate callback code.) Of course you are free to combine XSL-T with XMLTransformer to get the best of both worlds, if one is not enough.

How to use it
-------------
You pass the input XML and the name of a callback function (or the name of a callback method or a closure) to XMLTransformer. For each tag (opening, closing or empty) the callback function will be called with the tag’s data as argument and information on whether it is an opening, empty or closing tag. The callback function returns – based on the given data – an array that contains information on the desired tag (should the tag be renamed, removed, and if the latter: with or without content?), on the desired attributes (removal, addition, renaming), on adding literal content and a closure that will be called after the transformation has been performed. All of the aforementioned return information is optional, and if you do not return anything or null, nothing is changed.

If you need to perform modification of text nodes’ content, you may prefer to subclass XMLTransformer and overwrite the nodeContent() method. See below for an example.

Examples
===========

All of the examples below assume that your code includes the following lines in order to load the class and to import the namespaced class:

	require_once '/path/to/repository-clone/lib/BlueM/XMLTransformer.php';
	// Alternatively, you can use PSR-0 autoloading
	use BlueM\XMLTransformer;


Hello world
------------
	echo XMLTransformer::transformString(
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
			// We do not want the enclosing <root> tags in the output
			return array('tag'=>false);
		}
	}

	echo XMLTransformer::transformString(
		'<root><hello-world xml:lang="de" /></root>',
		'transform'
	);
	// Will output “Hallo Welt”
	
	echo XMLTransformer::transformString(
		'<root><hello-world xml:lang="en" /></root>',
		'transform'
	);
	// Will output “Hello world”

	// Explanation: In addition to the last example, we return
	//              key “insbefore”, which will insert literal content,
	//              which in this example is set based on the xml:lang
	//              attribute.


Removing tags including all of their content
--------------------------------------------
	echo XMLTransformer::transformString(
		'<root><remove>Hello</remove><keep>World</keep>.</root>',
			function($tag, $attributes, $opening) {
				switch ($tag) {
					case 'remove':
						// Returning false will remove the tag
						// and everything inside it.
						return false;
					case 'root':
					case 'keep':
						// Returning false as value of the array
						// key "tag" will remove the tag, but keep
						// its content.
						return array('tag'=>false);
						break;
					default:
						// Returning null is not necessary, as this
						// is the default behaviour. It is equivalent
						// to "Do not change anything."
						return null;
				}
			}
	);
	// Will output “World.”

Renaming attributes
-------------------
	echo XMLTransformer::transformString(
		'<root xml:id="abc"><bla xml:id="def"/></root>',
			function($tag, $attributes, $opening) {
				// The next line means "Rename the attribute from
				// 'xml:id' to 'id'
				return array('@xml:id'=>'@id');
			}
	);
	// Will output “<root id="abc"><bla id="def" /></root>”
	// Please note that empty tags will always be returned with
	// a space before the slash.


Modifying content by subclassing
--------------------------------
Some time ago, I had the task of publishing a [TEI](http://www.tei-c.org) XML document which contained characters with accents in Latin parts of the text. The accents should not be removed from the source document, but should not be presented in the resulting application. Solution: Subclass XMLTransformer, overwrite nodeContent() and perform the desired normalization, if the current element or one of its ancestors has an @xml:lang attribute value of “la”.

This is the code:

	class NoLatinAccentsXMLTransformer extends XMLTransformer {

		protected function nodeContent($content) {
	
			// Get the current node's attributes
			for ($i = count($this->stack); $i >= 0; $i --) {
				if (empty($this->stack[$i]['xml:lang'])) {
					// Keep on traversing the array until we find the
					// nearest ancestor node with an @xml:lang attribute
					continue;
				}
				break;
			}
	
			if ('la' == $this->stack[$i]['xml:lang']) {
				// We are currently in Latin context.
				// Do the normalization by modifying $content.
			}
	
			parent::nodeContent($content);
		}
	
	}

