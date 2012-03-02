CBXMLTransformer Overview
=========================

What is it?
--------------
CBXMLTransformer is a PHP class for transforming any kind of input XML into an output string. This string does not have to be XML, but can also contain, for instance, HTML or plain text.

What can it do?
----------------
CBXMLTransformer is able to …
* Remove tags, with or without the tag’ content
* Rename attributes
* Insert attributes
* Change attributes’ values
* Insert content before and after a tag
* Insert content at the beginning or end of tag content
* Transform a tag including all of its content by passing it to a user-defined closure
* Perform any combination of the above

What is it good for?
--------------------
In my opinion, CBXMLTransformer performs very well if the input XML and the output to be produced are similarly structured. Moreover, if data from the input XML has to be processed by an existing PHP codebase, it is probably cleaner to use CBXMLTransformer instead of XSL-T.

What is it not so good for?
----------------------------
When the input data has to be re-arranged, you are probably better off with XSL-T, as this is something that CBXMLTransformer does not provide. (Although to some extent it can be done with appropriate callback code.) Of course you are free to combine XSL-T with CBXMLTransformer to get the best of both worlds, if one is not enough.

Examples
===========

Coming soon ...