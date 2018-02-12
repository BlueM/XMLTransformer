<?php

namespace BlueM;

/**
 * Transforms XML into output string using rules returned from a user-supplied callback.
 *
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD 2-Clause License
 */
class XMLTransformer
{
    const ELEMENT_OPEN = 1;
    const ELEMENT_EMPTY = 2;
    const ELEMENT_CLOSE = 0;

    const RULE_ADD_END = 'insend';
    const RULE_ADD_AFTER = 'insafter';
    const RULE_ADD_BEFORE = 'insbefore';
    const RULE_ADD_START = 'insstart';
    const RULE_TRANSFORM_INNER = 'transformInner';
    const RULE_TRANSFORM_OUTER = 'transformOuter';
    const RULE_TAG = 'tag';

    const ALLOWED_ATTRIBUTE_RULES = [
        self::RULE_ADD_END => true,
        self::RULE_ADD_AFTER => true,
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
     * @var callable
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
     * Holds the resulting XML.
     *
     * @var string
     */
    protected $content = '';

    /**
     * @var bool
     */
    private $keepCData;

    /**
     * Force static use.
     */
    private function __construct()
    {
    }

    /**
     * Performs XML transformation of the string given as argument.
     *
     * @param string   $xml       XML to transform
     * @param callable $callback  Callback: function, method or closure
     * @param bool     $keepCData If false (default: true), CDATA content is not retained
     *                            as CDATA, but as PCDATA with < and > and & escaped
     *
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public static function transformString(string $xml, callable $callback, bool $keepCData = true)
    {
        $xmltr = new static();

        $xmltr->callback = $callback;
        $xmltr->keepCData = $keepCData;

        $r = new \XMLReader();
        $r->XML($xml);

        $r->setParserProperty(\XMLReader::SUBST_ENTITIES, true);

        while ($r->read()) {
            switch ($r->nodeType) {
                case \XMLReader::ELEMENT:
                    $xmltr->nodeOpen($r);
                    break;
                case \XMLReader::END_ELEMENT:
                    $xmltr->nodeClose($r);
                    break;
                case \XMLReader::SIGNIFICANT_WHITESPACE:
                case \XMLReader::WHITESPACE:
                    $xmltr->addNodeContent($r->value);
                    break;
                case \XMLReader::CDATA:
                    $xmltr->addCDataNodeContent($r->value);
                    break;
                case \XMLReader::TEXT:
                    $xmltr->addNodeContent(htmlspecialchars($r->value));
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
                ++$this->insideIgnorableTag;
            }

            return;
        }

        $attributes = $this->getAttributes($reader);

        if ($reader->isEmptyElement) {
            $type = self::ELEMENT_EMPTY;
        } else {
            // Remember the attributes, so the closing tag can access them, too
            $this->stack[] = $attributes;
            $type = self::ELEMENT_OPEN;
        }

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, $type)) {
            if (!$reader->isEmptyElement) {
                ++$this->insideIgnorableTag;
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
     * Method that will be invoked for any closing XML element.
     *
     * @param \XMLReader $reader
     */
    protected function nodeClose(\XMLReader $reader)
    {
        if ($this->insideIgnorableTag) {
            --$this->insideIgnorableTag;
        }

        if ($this->insideIgnorableTag) {
            return;
        }

        $attributes = array_pop($this->stack);
        $transformMe = array_pop($this->transformMe);

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, self::ELEMENT_CLOSE)) {
            return;
        }

        if (null === $rules) {
            $rules = [];
        }

        $tag = array_key_exists(self::RULE_TAG, $rules) ? $rules[self::RULE_TAG] : $name;
        $insertInside = $rules[static::RULE_ADD_END] ?? '';
        $insertOutside = $rules[static::RULE_ADD_AFTER] ?? '';

        if ($tag) {
            $tag = "</$tag>";
        }

        if ($transformMe) {
            // Finish this tag by transforming its content
            $transformInfo = array_pop($this->transformerStack);
            $closure = $transformInfo[0];
            $stackContent = $transformInfo[1];
            $inner = $transformInfo[2];
            if ($inner) {
                // Inner transformation
                $openingTagLen = $transformInfo[3];
                $openingTag = substr($stackContent, 0, $openingTagLen);
                $stackContent = substr($stackContent, $openingTagLen);
                $content = $openingTag.$closure($stackContent.$insertInside).$tag;
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
     * Saves the node's text content.
     *
     * @param string $content String with XML special characters esacaped
     */
    protected function addNodeContent($content)
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
     * Saves the node's text content.
     *
     * @param string $content CDATA content
     */
    protected function addCDataNodeContent($content)
    {
        if ($this->keepCData) {
            $this->addNodeContent("<![CDATA[$content]]>");
        } else {
            $this->addNodeContent(htmlspecialchars($content));
        }
    }

    /**
     * Returns the given node's attributes as an associative array.
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
     *
     * @throws \UnexpectedValueException
     */
    protected function addAttributes(string $tag, array $attributes, array $rules): string
    {
        foreach ($attributes as $attrname => $value) {
            if (array_key_exists("@$attrname", $rules)) {
                // There's a rule for this attribute
                if (false !== $rules["@$attrname"]) {
                    if (0 !== strpos($rules["@$attrname"], '@')) {
                        // Returned value does not start with "@" >> Treat as value
                        $tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($rules["@$attrname"]));
                    } else {
                        // Rename attribute
                        $tag .= sprintf(' %s="%s"', substr($rules["@$attrname"], 1), $value);
                    }
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
                if (false !== $value &&
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
     *                               added before, if defined by the processing rules
     */
    protected function updateTransformationStack(array $rules, $tagPlusContent)
    {
        if (isset($rules[self::RULE_TRANSFORM_OUTER]) &&
            $rules[self::RULE_TRANSFORM_OUTER] instanceof \Closure
        ) {
            $this->transformMe[] = true;
            $this->transformerStack[] = [$rules[self::RULE_TRANSFORM_OUTER], '', false];
        } elseif (isset($rules[self::RULE_TRANSFORM_INNER]) &&
                  $rules[self::RULE_TRANSFORM_INNER] instanceof \Closure
        ) {
            $this->transformMe[] = true;
            $this->transformerStack[] = [
                $rules[self::RULE_TRANSFORM_INNER],
                '',
                true,
                \strlen($tagPlusContent),
            ];
        } else {
            $this->transformMe[] = false;
        }
    }
}
