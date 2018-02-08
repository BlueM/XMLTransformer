<?php

namespace BlueM;

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
 * in which only slight modifications to the XML string are necessary. See doc
 * comments for method transformString() for information on usage.
 *
 * @package XMLTransformer
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD 2-Clause License
 */
class XMLTransformer
{
    const ELOPEN  = 1;
    const ELEMPTY = 2;
    const ELCLOSE = 0;

    const RULE_ADD_END = 'insend';
    const RULE_ADD_AFTER = 'insafter';
    const RULE_ADD_BEFORE = 'insbefore';
    const RULE_ADD_START = 'insstart';
    const RULE_TRANSFORM_INNER = 'transformInner';
    const RULE_TRANSFORM_OUTER = 'transformOuter';
    const RULE_TAG = 'tag';

    const ALLOWED_ATTRIBUTE_RULES = [
        self::RULE_ADD_END         => true,
        self::RULE_ADD_AFTER       => true,
        self::RULE_TRANSFORM_INNER => true,
        self::RULE_TRANSFORM_OUTER => true,
    ];

    /**
     * Keeps track of whether we are currently in a part of the XML tree that's
     * enclosed by a tag which should be ignored.
     *
     * @var int
     */
    protected $insideIgnorableTag = 0;

    /**
     * The callback function, method or Closure
     *
     * @var string|array|\Closure
     */
    protected $callback;

    /**
     * Indexed array keeping track of open tags, so closing nodes can
     * "know" about the opening tags' attributes, too.
     *
     * @var array
     */
    protected $stack = [];

    /**
     * Stack for keeping track of whether there's a transformer for the current
     * tag or not. Whenever a non-empty tag is opened, a boolean value will be
     * added to the stack and whenever a tag is closed, the last one is removed.
     *
     * @var array Indexed array
     */
    protected $transformMe = [];

    /**
     * Stack for managing content transformation. Each item is an indexed array
     * with indexes 0 = closure that will to do the transformation, 1 =
     * content to be transformed, 2 = bool (false: outer transformation, true:
     * inner transformation), plus in case of an inner transformation:
     * 3 = strlen() of the opening tag (plus "insbefore" value, if applicable).
     *
     * @var array Indexed array
     */
    protected $transformerStack = [];

    /**
     * Holds the resulting XML
     *
     * @var string
     */
    protected $content = '';

    /**
     * @var bool
     */
    private $keepCData;

    /**
     * Force static use
     */
    private function __construct()
    {
    }

    /**
     * Performs XML transformation of the string given as argument
     *
     * @param string                $xml      Well-formed XML string to transform
     * @param string|array|\Closure $callback Name of either a callback function or
     *        an array with indexes 0: class and 1: method that returns transformation
     *        info for this tag. (As the function is called for each opening or
     *        closing tag, it has to be efficient!) Function / method must accept 3
     *        arguments:
     *          1. Tag name
     *          2. Attributes as associative array (also provided for closing tags)
     *          3. One of the XMLTransformer::EL* constants to indicate the node type
     *        The function must either false (in which case the tag itself and anything
     *        inside it is completely ignored) or an array with 0 or more of these keys:
     *          - "tag" can be a new tag name that will be used instead of the
     *             original one. If false, the tag will be removed, but its child
     *             nodes will be preserved.
     *          - "@<name>" (where <name> is an attribute name) may be false (will
     *             return the attribute) or a string, either starting with "@" (will
     *             rename the attribute) or not starting with "@" (literal attr. value)
     *          - "insbefore" inserts PCDATA before the opening tag
     *          - "insstart" inserts PCDATA after the opening tag (i.e.: as a
     *            new first child)
     *          - "insend" inserts PCDATA directly before the closing tag
     *          - "insafter" inserts PCDATA after the closing tag
     *          - "transformOuter" This can be a closure that is passed the
     *            transformed element including all contained elements as a string.
     *          - "transformInner" This can be a closure that is passed the transformed
     *            element's content as a string.
     *          Anything for which neither false or an appropriate array
     *          value is returned, is left unmodified.
     * @param bool                  $keepCData If false (default: true), CDATA content
     *                                         is not retained as CDATA, but as PCDATA
     *                                         with < and > and & escaped
     *
     * @return mixed Transformation result
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function transformString(string $xml, callable $callback, bool $keepCData = true)
    {
        $xmltr = new static;

        if (!self::checkCallback($callback)) {
            throw new \InvalidArgumentException(
                'Callback must be function, method or closure'
            );
        }

        $xmltr->callback  = $callback;
        $xmltr->keepCData = $keepCData;

        $r = new \XMLReader;
        $r->XML($xml);

        $r->setParserProperty(\XMLReader::SUBST_ENTITIES, true);

        while ($r->read()) {
            switch ($r->nodeType) {
                case (\XMLReader::ELEMENT):
                    $xmltr->nodeOpen($r);
                    break;
                case (\XMLReader::END_ELEMENT):
                    $xmltr->nodeClose($r);
                    break;
                case (\XMLReader::SIGNIFICANT_WHITESPACE):
                case (\XMLReader::WHITESPACE):
                    $xmltr->nodeContent($r->value);
                    break;
                case (\XMLReader::CDATA):
                    $xmltr->cDataNodeContent($r->value);
                    break;
                case (\XMLReader::TEXT):
                    $xmltr->nodeContent(htmlspecialchars($r->value));
            }
        }

        $r->close();

        return $xmltr->content;
    }

    /**
     * Method that will be invoked for any opening or empty XML element.
     *
     * @param \XMLReader $reader
     *
     * @throws \RuntimeException
     */
    protected function nodeOpen(\XMLReader $reader)
    {
        if ($this->insideIgnorableTag) {
            if (!$reader->isEmptyElement) {
                $this->insideIgnorableTag ++;
            }
            return;
        }

        $attributes = $this->getAttributes($reader);

        if ($reader->isEmptyElement) {
            $type = self::ELEMPTY;
        } else {
            // Remember the attributes, so the closing tag can access them, too
            $this->stack[] = $attributes;
            $type          = self::ELOPEN;
        }

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, $type)) {
            if (!$reader->isEmptyElement) {
                $this->insideIgnorableTag ++;
            }

            return; // Nothing to do
        }

        if (null === $rules) {
            $rules = [];
        } else {
            $this->checkRules($reader, $name, $rules);
        }

        $insertOutside = $rules[self::RULE_ADD_BEFORE] ?? '';
        $insertInside = $rules[self::RULE_ADD_START] ?? '';
        unset($rules[self::RULE_ADD_BEFORE], $rules[self::RULE_ADD_START]);

        $tag = $this->getTag($name, $attributes, $rules, $reader->isEmptyElement);

        if ($reader->isEmptyElement) {
            $insertInside = $rules[self::RULE_ADD_AFTER] ?? '';
        } else {
            $this->updateTransformationStack($rules, $insertOutside.$tag);
        }

        if (0 < $count = \count($this->transformerStack)) {
            // Add to stack of content to be transformed
            $this->transformerStack[$count - 1][1] .= $insertOutside.$tag.$insertInside;
        } else {
            $this->content .= $insertOutside.$tag.$insertInside;
        }
    }

    /**
     * @param string $name       Tag/element name of the untransformed element
     * @param array  $attributes Tag attributes
     * @param array  $rules      Processing rules (key "tag" will be removed, if present)
     * @param bool   $empty      Whether this is an empty element
     *
     * @return string Either full opening tag incl. attributes or an empty string, in
     *                case the tag should be removed.
     *
     * @throws \UnexpectedValueException
     */
    protected function getTag($name, array $attributes, array &$rules, bool $empty): string
    {
        $tag = $rules[self::RULE_TAG] ?? $name;
        unset($rules[self::RULE_TAG]);

        if ($tag) {
            $tag = $this->addAttributes($tag, $attributes, $rules);
            if ($empty) {
                $tag = str_replace('>', ' />', $tag);
            }

            return $tag;
        }

        return '';
    }

    /**
     * @param \XMLReader $reader
     * @param string     $name
     * @param array      $rules
     *
     * @throws \RuntimeException
     */
    protected function checkRules(\XMLReader $reader, string $name, array $rules)
    {
        if ($reader->isEmptyElement) {
            if (!empty($rules[self::RULE_ADD_END])) {
                throw new \RuntimeException(
                    sprintf(
                        '“%s” does not make sense for empty tags (here: <%s/>). Use “%s”.',
                        self::RULE_ADD_END, $name, self::RULE_ADD_AFTER
                    )
                );
            }

            if (!empty($rules[self::RULE_ADD_START])) {
                throw new \RuntimeException(
                    sprintf(
                        '“%s” does not make sense for empty tags (here: <%s/>). Use “%s”.',
                        self::RULE_ADD_START, $name, self::RULE_ADD_BEFORE
                    )
                );
            }
        }
    }

    /**
     * Method that will be invoked for any closing XML element
     *
     * @param \XMLReader $reader
     */
    protected function nodeClose(\XMLReader $reader)
    {
        if ($this->insideIgnorableTag) {
            $this->insideIgnorableTag --;
        }

        if ($this->insideIgnorableTag) {
            return;
        }

        $attributes  = array_pop($this->stack);
        $transformMe = array_pop($this->transformMe);

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, self::ELCLOSE)) {
            return;
        }

        if (null === $rules) {
            $rules = [];
        }

        $tag           = array_key_exists(self::RULE_TAG, $rules) ? $rules[self::RULE_TAG] : $name;
        $insertInside  = $rules[static::RULE_ADD_END] ?? '';
        $insertOutside = $rules[static::RULE_ADD_AFTER] ?? '';

        if ($tag) {
            $tag = "</$tag>";
        }

        if ($transformMe) {
            // Finish this tag by transforming its content
            $transformInfo = array_pop($this->transformerStack);
            $closure       = $transformInfo[0];
            $stackContent  = $transformInfo[1];
            $inner         = $transformInfo[2];
            if ($inner) {
                // Inner transformation
                $openingTagLen = $transformInfo[3];
                $openingTag    = substr($stackContent, 0, $openingTagLen);
                $stackContent  = substr($stackContent, $openingTagLen);
                $content       = $openingTag.$closure($stackContent.$insertInside).$tag;
            } else {
                // Outer transformation
                $content = $closure($stackContent.$insertInside.$tag.$insertOutside);
            }
        } else {
            // No transformation
            $content = $insertInside.$tag.$insertOutside;
        }

        if (0 < $count = \count($this->transformerStack)) {
            // Add $content to stack of content to be transformed
            $this->transformerStack[$count - 1][1] .= $content;
        } else {
            $this->content .= $content;
        }
    }

    /**
     * Saves the node's text content
     *
     * @param string $content String with XML special characters esacaped
     */
    protected function nodeContent($content)
    {
        if ($this->insideIgnorableTag) {
            return;
        }

        if (0 < $count = \count($this->transformerStack)) {
            // Add content to transformation stack
            $this->transformerStack[$count - 1][1] .= $content;
        } else {
            // Add content to "regular" content
            $this->content .= $content;
        }
    }

    /**
     * Saves the node's text content
     *
     * @param string $content CDATA content
     */
    protected function cDataNodeContent($content)
    {
        if ($this->keepCData) {
            $this->nodeContent("<![CDATA[$content]]>");
        } else {
            $this->nodeContent(htmlspecialchars($content));
        }
    }

    /**
     * Verifies whether the callback is a valid function, an array
     * containing a callable class and method, or a Closure.
     *
     * @param string|array|\Closure $callback The callback given by the client
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected static function checkCallback($callback)
    {
        if (\is_string($callback)) {
            // Function
            if (!\function_exists($callback)) {
                throw new \InvalidArgumentException('Invalid callback function');
            }
            return true;
        }

        if (\is_array($callback)) {
            // Method
            if (2 !== \count($callback)) {
                throw new \InvalidArgumentException(
                    'When an array is passed as callback, it must have exactly 2 members'
                );
            }
            list($class, $method) = $callback;
            if (!\is_callable([$class, $method])) {
                throw new \InvalidArgumentException('Invalid callback method');
            }
            return true;
        }

        return is_object($callback) && is_callable($callback);
    }

    /**
     * Returns the given node's attributes as an associative array
     *
     * @param \XMLReader $reader
     *
     * @return array
     */
    protected function getAttributes(\XMLReader $reader): array
    {
        if (!$reader->hasAttributes) {
            return [];
        }

        $attributes = [];
        $reader->moveToFirstAttribute();

        do {
            $attributes[($reader->prefix ? $reader->prefix.':' : '').$reader->localName] = $reader->value;
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $attributes;
    }

    /**
     * Adds attributes to the given tag and returns the resulting opening tag.
     *
     * @param string $tag        Tag/element name
     * @param array  $attributes Associative array of attributes
     * @param array  $rules      Processing rules
     *
     * @return string
     * @throws \UnexpectedValueException
     */
    protected function addAttributes(string $tag, array $attributes, array $rules): string
    {
        foreach ($attributes as $attrname => $value) {
            if (array_key_exists("@$attrname", $rules)) {
                // There's a rule for this attribute
                if (false === $rules["@$attrname"]) {
                    // Skip this attribute
                } elseif (0 !== strpos($rules["@$attrname"], '@')) {
                    // Returned value does not start with "@" >> Treat as value
                    $tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($rules["@$attrname"]));
                } else {
                    // Rename attribute
                    $tag .= sprintf(' %s="%s"', substr($rules["@$attrname"], 1), $value);
                }
                unset($rules["@$attrname"]);
            } else {
                // Default behaviour: copy attribute and value
                $tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($value));
            }
        }

        // Loop over remaining keys in $attr (i.e.: attributes added in the callback method)
        foreach ($rules as $attrname => $value) {
            if (0 === strpos($attrname, '@')) {
                if ($value !== false &&
                    0 !== strpos($value, '@')
                ) {
                    // Add literal value
                    $tag .= sprintf(
                        ' %s="%s"',
                        str_replace('@', '', $attrname),
                        htmlspecialchars($value)
                    );
                }
            } elseif (empty(self::ALLOWED_ATTRIBUTE_RULES[$attrname])) {
                throw new \UnexpectedValueException(
                    'Unexpected key “'.$attrname."” in array returned by callback function for <$tag>."
                );
            }
        }

        return "<$tag>";
    }

    /**
     * @param array  $rules          Processing rules
     * @param string $tagPlusContent Full opening tag, including the content to be
     *                               added before, if defined by the processing rules.
     */
    protected function updateTransformationStack(array $rules, $tagPlusContent)
    {
        if (isset($rules[self::RULE_TRANSFORM_OUTER]) &&
            $rules[self::RULE_TRANSFORM_OUTER] instanceof \Closure
        ) {
            $this->transformMe[]      = true;
            $this->transformerStack[] = [$rules[self::RULE_TRANSFORM_OUTER], '', false];
        } elseif (isset($rules[self::RULE_TRANSFORM_INNER]) &&
                  $rules[self::RULE_TRANSFORM_INNER] instanceof \Closure
        ) {
            $this->transformMe[]      = true;
            $this->transformerStack[] = [
                $rules[self::RULE_TRANSFORM_INNER],
                '',
                true,
                \strlen($tagPlusContent)
            ];
        } else {
            $this->transformMe[] = false;
        }
    }
}
