[![Build Status](https://travis-ci.org/BlueM/XMLTransformer.png)](https://travis-ci.org/BlueM/XMLTransformer)
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/3f1631ee-2286-4c0e-b21c-da192bb3efba/mini.png)](https://insight.sensiolabs.com/projects/3f1631ee-2286-4c0e-b21c-da192bb3efba)

Overview
========
XMLTransformer is a PHP library for transforming any kind of input XML into an output string. This output string does not have to be XML, but can also be, for instance, HTML or plain text.


Transformations
---------------
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

When to use
-----------
In my opinion, XMLTransformer performs very well if the input XML and the output to be produced are similarly structured. Moreover, if data from the input XML has to be processed by an existing PHP codebase, it is possibly cleaner and simpler to use XMLTransformer instead of XSL-T.

When not to use
----------------------------
When the input data has to be re-arranged, you are probably better off with XSL-T, as this is something that XMLTransformer does not provide. (Although to some extent it can be done with appropriate callback code.) Of course you are free to combine XSL-T with XMLTransformer to get the best of both worlds, if one is not enough.


Installation
============
The recommended way to install this library is through [Composer](https://getcomposer.org). For this, add `"bluem/xmltransformer": "~2.0"` to the requirements in your `composer.json` file. As the library uses [semantic versioning](http://semver.org), you will get fixes and feature additions, but not changes which break the API.

Alternatively, you can clone the repository using git or download an [archived release](https://github.com/BlueM/XMLTransformer/releases).


Usage
=====
You pass the input XML and the name of a callback function or a callback method (specified as usual, using `[$object, 'methodName']` syntax) or an anonymous function / closure to `XMLTransformer`.

For each tag (opening, closing or empty) the callback function will be called with the tag’s name, its attributes and information on whether it is an opening, empty or closing tag. Now, your function / method / closure can return one of three things:

* An array (which describes what transformation(s) should be performed – see below)
* `false` (meaning: discard this tag, its attributes as well as any tags and any child elements)
* `null` (meaning: don’t modify anything – this is the default behaviour, i.e.: if the callback returns nothing, nothing is changed.


Callback function arguments
----------------------------
The callback function / method / closure is called with three arguments:

* The element / tag name
* The element / tag’s attributes (an associative array of name=>value pairs, where the name contains the namespace, if the attribute is not from the default namespace)
* An element type constant, which will be `XMLTransformer::ELEMENT_OPEN` for an opening tag, `XMLTransformer::ELEMENT_EMPTY` for an empty tag and `XMLTransformer::ELEMENT_CLOSE` for a closing tag.

Please note that the attributes will *always* be given, even for a closing tag.


The transformation description array
-------------------------------------
When you wish to perform a transformation, you must return an associative array. In this case, the following keys can be used:

* `XMLTransformer::RULE_TAG`: Returning false for key “tag” removes the tag (incl. its attributes, of course), but keeps any enclosed content. Returning a string will set the tag name to that string.
* `XMLTransformer::RULE_ADD_BEFORE`: Will insert the given string before the opening tag
* `XMLTransformer::RULE_ADD_AFTER`: Will insert the given string after the closing tag
* `XMLTransformer::RULE_ADD_START`: Will insert the given string right after the opening tag
* `XMLTransformer::RULE_ADD_END`: Will insert the given string right before the closing tag
* `XMLTransformer::RULE_TRANSFORM_OUTER`: Value must be a closure, which will be passed the element itself incl. all its content as a string. The closure’s return value will replace the element.
* `XMLTransformer::RULE_TRANSFORM_INNER`: Value must be a closure, which will be passed the element’s content as a string. The closure’s return value will replace the element.

Additionally, for handling attributes, array keys in the form of “@<name>” can be used, where <name> is the attribute name (with namespaces, if not from the default namespace). The value of such an array key can be one of:
* false: The attribute will be removed
* A string starting with “@”: The attribute will be renamed
* A string: The attribute value will be set to this string.

For instance, this return array …

```php
return [
    'tag' => 'demo',
    '@xml:id' => 'id',
    '@foo' => false,
    XMLTransformer::RULE_ADD_AFTER => '!',
];
```

… means:

* Rename the tag to “demo”
* Rename the “xml:id” attribute to “id”
* Remove the “@foo” attribute
* Insert the string “!” after the closing tag (or directly after the tag, if it’s an empty tag)

Please note that (as `XMLTransformer` is not restricted to produce XML) no automatic escaping is done to values returned by the array. Only exception: attribute values, as XMLTransformer assumes that if you set attribute values, you want XML or HTML output.


Passing attributes by reference
--------------------------------
The callback can accept the arguments’ array by reference, therefore allowing direct manipulation of the attributes. This can be handy when changing or removing a large number of attributes or when only a prefix or suffix (or namespace) of attributes’ names is known in advance.

See below for an example.


Examples
===========

All of the examples below assume that your code includes the usual boilerplate code for Composer autoloading:

```php
require 'vendor/autoload.php';
```

Hello world
------------
```php
use BlueM\XMLTransformer;

echo XMLTransformer::transformString(
    '<root><element>Hello world</element></root>',
    function($tag, $attributes, $opening) {
        return [
            XMLTransformer::RULE_TAG => false, // <-- Removes tag, but keeps content
        ];
    }
);
// Result: “Hello World”.
```

Multilingual Hello world
---------------------------
```php
use BlueM\XMLTransformer;

function transform($tag, $attributes, $opening) {
    if ('hello-world' == $tag) {
        if (isset($attributes['xml:lang']) and
            'de' == $attributes['xml:lang']) {
            $str = 'Hallo Welt';
        } else {
            $str = 'Hello world';
        }
        return [
            XMLTransformer::RULE_TAG => false, // <-- Remove the tag, keep content
            XMLTransformer::RULE_ADD_BEFORE => $str,  // <- Insert literal content
        ];
    }

    if ('root' == $tag) {
        // We do not want the enclosing <root> tags in the output
        return [XMLTransformer::RULE_TAG => false];
    }
}

echo XMLTransformer::transformString(
    '<root><hello-world xml:lang="de" /></root>',
    'transform'
);
// Result: “Hallo Welt”

echo XMLTransformer::transformString(
    '<root><hello-world xml:lang="en" /></root>',
    'transform'
);
// Result: “Hello world”
```

Removing tags including all of their content
--------------------------------------------
```php
echo XMLTransformer::transformString(
    '<root><remove>Hello </remove>World</root>',
        function($tag, $attributes, $opening) {
            switch ($tag) {
                case 'remove':
                    return false; // <-- Removes tag incl. content
                case 'root':
                case 'keep':
                    return [XMLTransformer::RULE_TAG => false]; // <-- Remove tag, keep content
                    break;
                default:
                    // Returning null is not necessary, as this
                    // is the default behaviour. It is equivalent
                    // to "Do not change anything."
                    return null;
            }
        }
);
// Result: “World”
```

Changing attribute values
-------------------------
```php
echo XMLTransformer::transformString(
    '<root abc="def"></root>',
    function($tag, $attributes, $opening) {
        return [
            '@abc' => 'xyz'
        ];
    }
);
// Result: “<root abc="xyz"></root>”
// Please note that empty tags will always be returned with
// a space before the slash.
```

Adding, renaming and removing attributes
---------------------------------------
```php
echo XMLTransformer::transformString(
    '<root xml:id="abc"><bla xml:id="def" blah="yes"/></root>',
    function($tag, $attributes, $opening) {
        return [
            '@foo'    => 'bar', // Add attribute "foo" with value "bar"
            '@blah'   => false, // Remove attribute "blah"
            '@xml:id' => '@id', // Rename attribute "xml:id" to "id"
        ];
    }
);
// Result: “<root id="abc" foo="bar"><bla id="def" foo="bar" /></root>”
// Please note that empty tags will always be returned with
// a space before the slash.
```

Modifying attributes by reference
---------------------------------
```php
echo XMLTransformer::transformString(
    '<root xml:a="a" xml:b="b" id="foo">Content</root>',
    function($tag, &$attributes, $opening) {
        foreach ($attributes as $name => $value) {
            if ('xml:' === substr($name, 0, 4)) {
                unset($attributes[$name]); // Drop attributes in "xml" namespace
            }
        }
    }
);
// Result: “<root id="foo">Content</root>”
```


Author & License
=========================
This code was written by Carsten Blüm ([www.bluem.net](http://www.bluem.net)) and licensed under the BSD2 license.


Version history
===============

## 2.0 (2018-02-12)
* BC break: minimum PHP version is 7.0
* BC break: introduced class constants for transformation rules which should be used instead of the magic strings used with version 1. This means, that in your code, you should …
    * Change string `insend` to `XMLTransformer::RULE_ADD_END`
    * Change string `insafter` to `XMLTransformer::RULE_ADD_AFTER`
    * Change string `insbefore` to `XMLTransformer::RULE_ADD_BEFORE`
    * Change string `insstart` to `XMLTransformer::RULE_ADD_START`
    * Change string `transformInner` to `XMLTransformer::RULE_TRANSFORM_INNER`
    * Change string `transformOuter` to `XMLTransformer::RULE_TRANSFORM_OUTER`
    * Change string `tag` to `XMLTransformer::RULE_TAG`
* BC break: constant `XMLTransformer::ELOPEN` was renamed to `XMLTransformer::ELEMENT_OPEN`, `XMLTransformer::ELEMPTY` was renamed to `XMLTransformer::ELEMENT_EMPTY` and `XMLTransformer::ELCLOSE` was renamed to `XMLTransformer::ELEMENT_CLOSE`.
* Code simplification, modernization

## 1.2 (2015-12-05)
* Adds missing support for handling CDATA. By default, CDATA sections are retained, but by setting the third argument to `transformString()` to false, CDATA content is replaced with but as PCDATA content with `<` and `>` and `&` escaped.

## 1.1 (2015-08-15)
* The callback function/method/closure can receive the attributes by reference. See “Passing attributes by reference” above.
* Fix for PHP 5.3 compatibility

## 1.0 (2012-12-12)
* First public version
*
