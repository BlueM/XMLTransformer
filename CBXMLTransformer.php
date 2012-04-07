<?php

/**
 * XML transformation class.
 *
 * Takes an input XML string and transforms it into an output string (which
 * does not have to be XML) according to "rules" defined in a callback
 * function/method/closure given by the user. Currently, it is able to:
 *  -- remove tags, preserving tag content
 *  -- remove tags including all of its content
 *  -- rename tags
 *  -- rename attributes
 *  -- remove attributes
 *  -- add attributes
 *  -- change attribute values
 *  -- insert content before and after a tag
 *  -- insert content at the beginning or end of tag content
 *  -- transform a tag including all of its content by passing it
 *     to a user-defined closure
 *  -- perform any combination of the above
 * Hence, it can be regarded as an alternative to XSL-T especially for cases
 * in which only slight modifications to the XML string are necessary.
 * See doc comments for CBXMLTransformer::transformString() for
 * information on usage.
 * @package CBXMLTransformer
 * @author Carsten Bluem <carsten@bluem.net>
 * @copyright 2008-2012 Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link http://bluem.net/
 * @version SVN: $Id: CBXMLTransformer.php 104 2012-02-26 10:25:35Z cb $
 */
class CBXMLTransformer {

	/**
	 * Flag: are we currently in a part of the XML tree that's
	 * enclosed by a tag which should be removed?
	 * @var bool
	 */
	protected $insideIgnorableTag = 0;

	/**
	 * The callback function or method
	 * @var mixed
	 */
	protected $callback = null;

	/**
	 * Indexed array keeping track of open tags, so closing nodes can
	 * "know" about the opening tags' attributes, too.
	 * @var array
	 */
	protected $stack = array();

	/**
	 * Stack for keeping track of whether there's a transformer for the current
	 * tag or not. Whenever a non-empty tag is opened, a boolean value will be
	 * added to the stack and whenever a tag is closed, the last one is removed.
	 * @var array Indexed array
	 */
	protected $transformMe = array();

	/**
	 * Stack for managing content transformation. Each item is an indexed array
	 * with indexes 0 = closure that will to do the transformation and 1 =
	 * content to be transformed.
	 * @var array Indexed array
	 */
	protected $transformerStack = array();

	/**
	 * Holds the resulting XML
	 * @var string
	 */
	protected $content = '';

	/**
	 * Performs XML transformation of the string given as argument
	 * @param string $xml Well-formed XML string to transform
	 * @param string|array|Closure $callback Name of either a callback
	 *                    function or an array with indexes 0: class and
	 *                    1: method that returns transformation info for this
	 *                    tag. (As the function is called for each opening or
	 *                    closing tag, it has to be efficient!) Function / method
	 *                    must accept 3 arguments: 1. tag name, 2. attributes as
	 *                    associative array (also provided for closing tags) and
	 *                    3. a flag that's true when this is an opening tag. The
	 *                    function must either false (in which case the tag itself
	 *                    and anything inside it is completely ignored) or an array
	 *                    with zero or more of the following keys:
	 *                    - "tag" can be a new tag name that will be used instead
	 *                       of the original one. If false, the tag will be removed,
	 *                       but its child nodes will be preserved.
	 *                    - "attr" can be an assoc. array that defines attribute
	 *                      transformations. Works as "tag" above, i.e.: returning
	 *                      null for an attribute will remove it, returning a
	 *                      string will replace the attribute name with that string
	 *                    - "insbefore" inserts PCDATA before the opening tag
	 *                    - "insstart" inserts PCDATA after the opening tag (i.e.:
	 *                      as a new first child)
	 *                    - "insend" inserts PCDATA directly before the closing tag
	 *                    - "insafter" inserts PCDATA after the closing tag
	 *                    - "transform" This can be a closure that is passed the
	 *                       transformed element including all contained elements
	 *                       as one string.
	 *                    Anything for which neither false or an appropriate array
	 *                    value is returned, is left unmodified.
	 * @return string XML string
	 * @throws InvalidArgumentException
	 */
	public static function transformString($xml, $callback) {

		$xmltr = new static;

		if (!self::checkCallback($callback)) {
			throw new InvalidArgumentException('Callback must be function, method or closure');
		}
		$xmltr->callback = $callback;

		$r = new XMLReader;
		$r->XML($xml);

		$r->setParserProperty(XMLReader::SUBST_ENTITIES, false);

		while ($r->read()) {
			switch ($r->nodeType) {
				case (XMLREADER::ELEMENT):
					$xmltr->nodeOpen($r);
					break;
				case (XMLREADER::END_ELEMENT):
					$xmltr->nodeClose($r);
					break;
				case (XMLReader::SIGNIFICANT_WHITESPACE):
				case (XMLReader::WHITESPACE):
					$xmltr->nodeContent($r->value);
					break;
				case (XMLREADER::TEXT):
					$xmltr->nodeContent(htmlspecialchars($r->value));
			}
		}

		$r->close();
		return $xmltr->content;
	}

	/**
	 * Method that will be invoked for any opening or empty XML element
	 * @param XMLReader $r instance.
	 * @throws UnexpectedValueException
	 */
	protected function nodeOpen(XMLReader $r) {

		if ($this->insideIgnorableTag) {
			if (!$r->isEmptyElement) {
				$this->insideIgnorableTag ++;
			}
			return;
		}

		$attributes = array();
		if ($r->hasAttributes) {
			$r->moveToFirstAttribute();
			do {
				$attributes[($r->prefix ? $r->prefix.':' : '').$r->localName] = $r->value;
			} while ($r->moveToNextAttribute());
			$r->moveToElement();
		}

		if (!$r->isEmptyElement) {
			// Remember the attributes, so the closing tag can access them, too
			$this->stack[] = $attributes;
		}

		$name = $r->prefix ? $r->prefix.':'.$r->localName : $r->localName;

		if (false === $trnsf = call_user_func_array($this->callback, array($name, $attributes, true))) {
			if (!$r->isEmptyElement) {
				$this->insideIgnorableTag ++;
			}
			return; // Nothing to do
		} elseif (null === $trnsf) {
			$trnsf = array();
		}

		$tag = isset($trnsf['tag']) ? $trnsf['tag'] : $name;

		unset($trnsf['tag']); // Reminder: keep outside the "if" block in case
		                      // NULL was returned for the tag

		if (isset($trnsf['insbefore'])) {
			$insoutside = $trnsf['insbefore'];
			unset($trnsf['insbefore']);
		} else {
			$insoutside = '';
		}

		if (isset($trnsf['insstart'])) {
			$insinside = $trnsf['insstart'];
			unset($trnsf['insstart']);
		} else {
			$insinside = '';
		}

		// Add attributes
		if ($tag) {
			$tag = $this->addAttributes($tag, $attributes, $trnsf);
		}

		if ($r->isEmptyElement) {
			$tag = str_replace('>', ' />', $tag);
			$insinside .= isset($trnsf['insend']) ? $trnsf['insend'] : '';
			$insoutside .= isset($trnsf['insafter']) ? $trnsf['insafter'] : '';
		} else {
			if (isset($trnsf['transform']) and
			    $trnsf['transform'] instanceof Closure) {
				$this->transformMe[] = true;
				$this->transformerStack[] = array($trnsf['transform'], '');
			} else {
				$this->transformMe[] = false;
			}
		}

		$content = $insoutside.$tag.$insinside;

		if (0 < $count = count($this->transformerStack)) {
			// Add opening tag to stack of content to be transformed
			$this->transformerStack[$count - 1][1] .= $content;
		} else {
			// Add opening tag to "regular" content
			$this->content .= $content;
		}
	}

	/**
	 * Method that will be invoked for any closing XML element
	 * @param XMLReader $r
	 */
	protected function nodeClose(XMLReader $r) {

		if ($this->insideIgnorableTag) {
			$this->insideIgnorableTag --;
		}

		if ($this->insideIgnorableTag) {
			return;
		}

		$attributes = array_pop($this->stack);
		$transformme = array_pop($this->transformMe);

		$name = $r->prefix ? $r->prefix.':'.$r->localName : $r->localName;

		if (false === $trnsf = call_user_func_array($this->callback, array($name, $attributes, false))) {
			return;
		} elseif (null === $trnsf) {
			$trnsf = array();
		}

		$tag = array_key_exists('tag', $trnsf) ? $trnsf['tag'] : $name;
		$insinside = isset($trnsf['insend']) ? $trnsf['insend'] : '';
		$insoutside = isset($trnsf['insafter']) ? $trnsf['insafter'] : '';

		if ($tag) {
			$tag = "</$tag>";
		}

		$content = $insinside.$tag.$insoutside;

		if ($transformme) {
			// Finish this tag by transforming its content
			$transform = array_pop($this->transformerStack);
			$content = $transform[0]($transform[1].$content);
		}

		if (0 < $count = count($this->transformerStack)) {
			// Add $content to stack of content to be transformed
			$this->transformerStack[$count - 1][1] .= $content;
		} else {
			// Add $content to "regular" content
			$this->content .= $content;
		}
	}

	/**
	 * Returns the node's text content
	 * @param string $content String or whitespace content, with XML
	 *                        special characters esacaped.
	 */
	protected function nodeContent($content) {

		if ($this->insideIgnorableTag) {
			return;
		}

		if (0 < $count = count($this->transformerStack)) {
			// Add content to transformation stack
			$this->transformerStack[$count - 1][1] .= $content;
		} else {
			// Add content to "regular" content
			$this->content .= $content;
		}
	}

	/**
	 * Verifies whether the callback is a valid function, an array
	 * containing a callable class and method, or a Closure.
	 * @param string|array|Closure $callback The callback given by the client
	 * @return bool
	 * @throws InvalidArgumentException
	 */
	protected static function checkCallback($callback) {

		if (is_string($callback)) {
			// Function
			if (!function_exists($callback)) {
				throw new InvalidArgumentException("Invalid callback function");
			}
			return true;
		}

		if (is_array($callback)) {
			// Method
			if (2 != count($callback)) {
				throw new InvalidArgumentException("When an array is passed as callback, it must have exactly 2 members");
			}
			list($class, $method) = $callback;
			if (!is_callable(array($class, $method))) {
				throw new InvalidArgumentException("Invalid callback method");
			}
			return true;
		}

		if (is_object($callback) and
		    is_a($callback, 'Closure')) {
			// Closure
			return true;
		}

		return false;
	}

	/**
	 * Adds attributes to the given tag and returns the resulting opening tag.
	 * @param string $tag Tag/element name.
	 * @param array $attributes Associative array of attributes
	 * @param mixed $trnsf Transformation "rules"
	 * @return string
	 * @throws UnexpectedValueException
	 */
	protected function addAttributes($tag, array $attributes, $trnsf) {
		foreach ($attributes as $attrname=>$value) {
			if (array_key_exists("@$attrname", $trnsf)) {
				// There's a rule for this attribute
				if (false === $trnsf["@$attrname"]) {
					// Skip this attribute
				} elseif(strncmp($trnsf["@$attrname"], '@', 1)) {
					// Returned value does not start with "@" >> Treat as value
					$tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($trnsf["@$attrname"]));
				} else {
					// Rename attribute
					$tag .= sprintf(' %s="%s"', substr($trnsf["@$attrname"], 1), $value);
				}
				unset($trnsf["@$attrname"]);
			} else {
				// Default behaviour: copy attribute and value
				$tag .= sprintf(' %s="%s"', $attrname, $value);
			}
		}

		// Loop over remaining keys in $attr (i.e.: attributes added
		// in the callback method)
		foreach ($trnsf as $attrname=>$value) {
			if ('@' == substr($attrname, 0, 1)) {
				if ('@' == substr($value, 0, 1)) {
					// Attribute should be renamed, but attribute was not
					// present in source tag >> nothing to rename >> ignore.
				} elseif ($value !== false) {
					// Add literal value
					$tag .= sprintf(' %s="%s"', str_replace('@', '', $attrname), htmlspecialchars($value));
				}
			} elseif ('insend' != $attrname and
					  'insafter' != $attrname and
					  'transform' != $attrname) {
				throw new UnexpectedValueException("Unexpected key \"$attrname\" in array returned by callback function for <$tag>.");
			}
		}
		return "<$tag>";
	}
}
