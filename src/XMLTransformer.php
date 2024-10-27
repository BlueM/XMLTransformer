<?php

namespace BlueM;

/**
 * Transforms XML into output string using rules returned from a user-supplied callback.
 *
 * @author Carsten Bluem <carsten@bluem.net>
 * @license https://opensource.org/license/BSD-2-Clause BSD 2-Clause License
 */
class XMLTransformer
{
    public const ELEMENT_OPEN = 1;
    public const ELEMENT_EMPTY = 2;
    public const ELEMENT_CLOSE = 0;

    public const RULE_ADD_END = 'insend';
    public const RULE_ADD_AFTER = 'insafter';
    public const RULE_ADD_BEFORE = 'insbefore';
    public const RULE_ADD_START = 'insstart';
    public const RULE_TRANSFORM_INNER = 'transformInner';
    public const RULE_TRANSFORM_OUTER = 'transformOuter';
    public const RULE_TAG = 'tag';

    protected const ALLOWED_ATTRIBUTE_RULES = [
        self::RULE_ADD_END => true,
        self::RULE_ADD_AFTER => true,
        self::RULE_TRANSFORM_INNER => true,
        self::RULE_TRANSFORM_OUTER => true,
    ];

    /**
     * Keeps track of whether we are currently in a part of the XML tree that's
     * enclosed by a tag which should be ignored.
     */
    protected int $insideIgnorableTagCounter = 0;

    /**
     * @var callable
     */
    protected $callback;

    /**
     * @var array<array<string, mixed>>
     */
    protected array $attributesStack = [];

    /**
     * Stack for keeping track of whether there's a transformer for the current
     * tag or not. Whenever a non-empty tag is opened, a boolean value will be
     * added to the stack and whenever a tag is closed, the last one is removed.
     *
     * @var array<bool>
     */
    protected array $transformMeStack = [];

    /**
     * Stack for managing content transformation. Each item is an indexed array
     * with indexes 0 = closure that will to do the transformation, 1 =
     * content to be transformed, 2 = bool (false: outer transformation, true:
     * inner transformation), plus in case of an inner transformation:
     * 3 = strlen() of the opening tag (plus "insbefore" value, if applicable).
     *
     * @var array<array<int, mixed>>
     */
    protected array $transformerStack = [];

    /**
     * Holds the resulting XML.
     */
    protected string $content = '';

    private bool $keepCData;

    /**
     * Force static use.
     */
    private function __construct()
    {
    }

    /**
     * Performs XML transformation of the string given as argument.
     *
     * @param bool $keepCData If false, CDATA content is not retained as CDATA, but as PCDATA with < and > and & escaped
     *
     * @throws \RuntimeException
     */
    public static function transformString(string $xml, callable $callback, bool $keepCData = true): string
    {
        $transformer = new static();

        $transformer->callback = $callback;
        $transformer->keepCData = $keepCData;

        $r = \XMLReader::XML($xml);
        $r->setParserProperty(\XMLReader::SUBST_ENTITIES, true);

        $element = false;
        while ($r->read()) {
            switch ($r->nodeType) {
                case \XMLReader::ELEMENT:
                    $element = true;
                    $transformer->nodeOpen($r);
                    break;
                case \XMLReader::END_ELEMENT:
                    $transformer->nodeClose($r);
                    break;
                case \XMLReader::SIGNIFICANT_WHITESPACE:
                case \XMLReader::WHITESPACE:
                    $transformer->addNodeContent($r->value);
                    break;
                case \XMLReader::CDATA:
                    $transformer->addCDataNodeContent($r->value);
                    break;
                case \XMLReader::TEXT:
                    $transformer->addNodeContent(htmlspecialchars($r->value));
            }
        }

        if (!$element) {
            // Note: according to docs, \XMLReader::XML() should return false when opening
            // invalid XML. This is no longer the case, hence this workaround.
            throw new \RuntimeException('Looks like the input XML is empty or invalid.');
        }

        $r->close();

        return $transformer->content;
    }

    /**
     * Method that will be invoked for any opening or empty XML element.
     *
     * @throws \RuntimeException
     */
    protected function nodeOpen(\XMLReader $reader): void
    {
        if ($this->insideIgnorableTagCounter) {
            if (!$reader->isEmptyElement) {
                ++$this->insideIgnorableTagCounter;
            }

            return;
        }

        $attributes = $this->getAttributes($reader);

        if ($reader->isEmptyElement) {
            $type = self::ELEMENT_EMPTY;
        } else {
            // Remember the attributes, so the closing tag can access them, too
            $this->attributesStack[] = $attributes;
            $type = self::ELEMENT_OPEN;
        }

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, $type)) {
            if (!$reader->isEmptyElement) {
                ++$this->insideIgnorableTagCounter;
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

        $tag = $this->getTag($name, $attributes, $rules, $reader->isEmptyElement ? self::ELEMENT_EMPTY : self::ELEMENT_OPEN);

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
     * @param string $name Tag/element name of the untransformed element
     * @param array<string, mixed> $attributes Tag attributes
     * @param array<string, mixed> $processingRules Processing rules (key "tag" will be removed, if present)
     *
     * @return string Either full opening tag incl. attributes or an empty string, in
     *                case the tag should be removed.
     */
    protected function getTag(string $name, array $attributes, array &$processingRules, int $tagType): string
    {
        $tag = $processingRules[self::RULE_TAG] ?? $name;
        unset($processingRules[self::RULE_TAG]);

        if ($tag) {
            if (self::ELEMENT_CLOSE === $tagType) {
                return "</$tag>";
            }

            $tag = $this->addAttributes($tag, $attributes, $processingRules);
            if (self::ELEMENT_EMPTY === $tagType) {
                $tag = str_replace('>', ' />', $tag);
            }

            return $tag;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $processingRules
     *
     * @throws \RuntimeException
     */
    protected function checkRules(\XMLReader $reader, string $name, array $processingRules): void
    {
        if ($reader->isEmptyElement) {
            if (array_key_exists(self::RULE_ADD_END, $processingRules)) {
                throw new \RuntimeException(
                    sprintf(
                        '“%s” does not make sense for empty tags (here: <%s/>). Use “%s”.',
                        self::RULE_ADD_END, $name, self::RULE_ADD_AFTER
                    )
                );
            }

            if (array_key_exists(self::RULE_ADD_START, $processingRules)) {
                throw new \RuntimeException(
                    sprintf(
                        '“%s” does not make sense for empty tags (here: <%s/>). Use “%s”.',
                        self::RULE_ADD_START, $name, self::RULE_ADD_BEFORE
                    )
                );
            }

            if (array_key_exists(self::RULE_TRANSFORM_OUTER, $processingRules)) {
                throw new \RuntimeException(
                    sprintf(
                        '“%s” does not work with empty tags (here: <%s/>). If you want to wrap the element '.
                        'or change it to a non-empty element, Use “%s” and “%s”. If you want to replace '.
                        'the tag with arbitray content, use “%s”',
                        self::RULE_TRANSFORM_OUTER, $name,
                        self::RULE_ADD_BEFORE,
                        self::RULE_ADD_AFTER,
                        self::RULE_TRANSFORM_INNER,
                    )
                );
            }
        }
    }

    /**
     * Method that will be invoked for any closing XML element.
     */
    protected function nodeClose(\XMLReader $reader): void
    {
        if ($this->insideIgnorableTagCounter) {
            --$this->insideIgnorableTagCounter;
        }

        if ($this->insideIgnorableTagCounter) {
            return;
        }

        $attributes = array_pop($this->attributesStack);
        $transformMe = array_pop($this->transformMeStack);

        $name = $reader->prefix ? $reader->prefix.':'.$reader->localName : $reader->localName;

        $callback = $this->callback; // Workaround for being able to pass args by ref

        if (false === $rules = $callback($name, $attributes, self::ELEMENT_CLOSE)) {
            return;
        }

        if (null === $rules) {
            $rules = [];
        }

        $tag = $this->getTag($name, $attributes, $rules, self::ELEMENT_CLOSE);
        $insertInside = $rules[static::RULE_ADD_END] ?? '';
        $insertOutside = $rules[static::RULE_ADD_AFTER] ?? '';

        if ($transformMe) {
            // Finish this tag by transforming its content
            $transformInfo = array_pop($this->transformerStack);
            [$closure, $stackContent, $inner] = $transformInfo;

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
    protected function addNodeContent(string $content): void
    {
        if ($this->insideIgnorableTagCounter) {
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
     */
    protected function addCDataNodeContent(string $content): void
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
     * @return array<string, string>
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
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $processingRules Processing rules
     *
     * @throws \UnexpectedValueException
     */
    protected function addAttributes(string $tag, array $attributes, array $processingRules): string
    {
        foreach ($attributes as $attrname => $value) {
            if (array_key_exists("@$attrname", $processingRules)) {
                // There's a rule for this attribute
                if (false !== $processingRules["@$attrname"]) {
                    if (!str_starts_with($processingRules["@$attrname"], '@')) {
                        // Returned value does not start with "@" >> Treat as value
                        $tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($processingRules["@$attrname"]));
                    } else {
                        // Rename attribute
                        $tag .= sprintf(' %s="%s"', substr($processingRules["@$attrname"], 1), $value);
                    }
                }
                unset($processingRules["@$attrname"]);
            } else {
                // Default behaviour: copy attribute and value
                $tag .= sprintf(' %s="%s"', $attrname, htmlspecialchars($value));
            }
        }

        // Loop over remaining keys in $attr (i.e.: attributes added in the callback method)
        foreach ($processingRules as $attrname => $value) {
            if (str_starts_with($attrname, '@')) {
                if (false !== $value
                    && !str_starts_with($value, '@')
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
     * @param array<string, mixed> $processingRules
     * @param string $tagPlusContent Full opening tag, including the content to be
     *                               added before, if defined by the processing rules
     */
    protected function updateTransformationStack(array $processingRules, string $tagPlusContent): void
    {
        if (isset($processingRules[self::RULE_TRANSFORM_OUTER])
            && $processingRules[self::RULE_TRANSFORM_OUTER] instanceof \Closure
        ) {
            $this->transformMeStack[] = true;
            $this->transformerStack[] = [$processingRules[self::RULE_TRANSFORM_OUTER], '', false];
        } elseif (isset($processingRules[self::RULE_TRANSFORM_INNER])
                  && $processingRules[self::RULE_TRANSFORM_INNER] instanceof \Closure
        ) {
            $this->transformMeStack[] = true;
            $this->transformerStack[] = [
                $processingRules[self::RULE_TRANSFORM_INNER],
                '',
                true,
                \strlen($tagPlusContent),
            ];
        } else {
            $this->transformMeStack[] = false;
        }
    }
}
