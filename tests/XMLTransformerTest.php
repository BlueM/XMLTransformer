<?php

namespace BlueM;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * @author Carsten Bluem <carsten@bluem.net>
 * @license https://opensource.org/license/BSD-2-Clause BSD 2-Clause License
 */
class XMLTransformerTest extends TestCase
{
    #[Test]
    #[TestDox('General: an exception is thrown if the XML is invalid')]
    public function invalidXMLThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Looks like the input XML is empty or invalid');
        $xml = '<root><element></root>';
        error_reporting(E_ERROR); // Suppress the warning
        XMLTransformer::transformString($xml, static fn () => null);
    }

    #[Test]
    #[TestDox('General: invoking with a callable which returns null does not alter the XML')]
    public function unchangedXmlIfCallableReturnsNull(): void
    {
        $xml = '<root><element>Element content</element><empty /></root>';

        static::assertSame($xml, XMLTransformer::transformString($xml, static fn () => null));
    }

    #[Test]
    #[TestDox('General: invoking with a callable which returns an empty array does not alter the XML')]
    public function unchangedXmlIfCallableReturnsEmptyArray(): void
    {
        $xml = "<root>\n".
            "<element1>Element content</element1>\n".
            "<element2><![CDATA[This is content: < & >]]> <![CDATA[More <strong>cdata</strong>.]]></element2>\n".
            "<empty />\n".
            '</root>';

        static::assertSame($xml, XMLTransformer::transformString($xml, static fn () => []));
    }

    #[Test]
    #[TestDox('General: CDATA is escaped, if CDATA should not be preserved')]
    public function cdataCanBeEscaped(): void
    {
        $xml = "<root>\n".
            "<element1>Element content</element1>\n".
            "<element2><![CDATA[This is content: < & >]]> <![CDATA[More <strong>cdata</strong>]]></element2>\n".
            "<empty />\n".
            '</root>';

        $exp = "<root>\n".
            "<element1>Element content</element1>\n".
            "<element2>This is content: &lt; &amp; &gt; More &lt;strong&gt;cdata&lt;/strong&gt;</element2>\n".
            "<empty />\n".
            '</root>';

        static::assertSame($exp, XMLTransformer::transformString($xml, static fn () => null, false));
    }

    #[Test]
    #[TestDox('General: using an unexpected key in an array returned from the callback throws an exception')]
    public function returningAnUnexpectedArrayKeyThrowsAnException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected key “unexpected” in array returned');

        XMLTransformer::transformString(
            '<root></root>',
            static function () {
                return [
                    'unexpected' => 'value',
                ];
            }
        );
    }

    #[Test]
    #[TestDox('General: the callback closure is given the correct tag type constant as argument')]
    public function callbackArgument3IsCorrect(): void
    {
        $xml = '<root><a><b>Hello world</b></a><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function (string $tag, array $attributes, int $type) {
                if (XMLTransformer::ELEMENT_OPEN === $type) {
                    return [
                        XMLTransformer::RULE_TAG => false,
                        XMLTransformer::RULE_ADD_START => "<$tag>",
                    ];
                }

                if (XMLTransformer::ELEMENT_CLOSE === $type) {
                    return [
                        XMLTransformer::RULE_TAG => false,
                        XMLTransformer::RULE_ADD_END => "</$tag>",
                    ];
                }

                return [
                    XMLTransformer::RULE_TAG => false,
                    XMLTransformer::RULE_ADD_BEFORE => "<$tag/>",
                ];
            }
        );
        static::assertSame($xml, $actual);
    }

    #[Test]
    #[TestDox('General: entities are substituted with their value')]
    public function entitiesAreSubstituted(): void
    {
        /** @noinspection CheckDtdRefs */
        $xml = <<<__XML1__
            <!DOCTYPE dummy
            [
            <!ENTITY w "Works as expected">
            ]>
            <root><a>&w;</a></root>
__XML1__;

        static::assertSame(
            '<root><a>Works as expected</a></root>',
            XMLTransformer::transformString($xml, static fn () => null)
        );
    }

    #[Test]
    #[TestDox('Tags: returning null for the tag name does not modify the tag')]
    public function unchangedTagIfTagNameIsReturnedAsNull(): void
    {
        $xml = '<root><element>Element <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TAG => null,
                    ];
                }

                return null;
            }
        );

        static::assertSame($xml, $actual);
    }

    #[Test]
    #[TestDox('Tags: returning false removes the tag and its content')]
    public function removeTagAndContent(): void
    {
        $xml = "<root>\n".
               "<ignore>Element <em>content</em></ignore>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('ignore' === $tag) {
                    return false;
                }

                return null;
            }
        );

        $exp = "<root>\n\n".
               "<empty />\n".
               '</root>';

        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Tags: content after a tag to be removed is not removed')]
    public function keepContentAfterIgnorableTag(): void
    {
        $xml = <<<__XML1__
<root>
<a><ignore><b>Blah</b><ignore>content</ignore></ignore><ignore>content</ignore>Xyz</a>
</root>
__XML1__;

        $expected = <<<__XML2__
<root>
<a>Xyz</a>
</root>
__XML2__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('ignore' === $tag) {
                    return false;
                }

                return null;
            }
        );
        static::assertSame($expected, $actual);
    }

    #[Test]
    #[TestDox('Tags: returning false removes an empty tag')]
    public function removeEmptyTagAndContent(): void
    {
        $xml = '<root><empty /></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static fn ($tag) => 'empty' === $tag ? false : null
        );

        static::assertSame('<root></root>', $actual);
    }

    #[Test]
    #[TestDox('Tags: returning false for the tag rule removes the tag, but keeps its content')]
    public function removeTag(): void
    {
        $xml = '<root><element>Element <em>content</em></element><empty /></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [XMLTransformer::RULE_TAG => false];
                }

                return null;
            }
        );

        $expected = '<root>Element <em>content</em><empty /></root>';

        static::assertSame($expected, $actual);
    }

    #[Test]
    #[TestDox('Tags: a tag in the default namespace can be renamed')]
    public function renameTagInDefaultNamespace(): void
    {
        $xml = '<root><element>Element content</element><empty/></root>';

        $expected = '<toplevel><a>Element content</a><b /></toplevel>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('root' === $tag) {
                    return [XMLTransformer::RULE_TAG => 'toplevel'];
                }
                if ('element' === $tag) {
                    return [XMLTransformer::RULE_TAG => 'a'];
                }

                return [XMLTransformer::RULE_TAG => 'b'];
            }
        );

        static::assertSame($expected, $actual);
    }

    #[Test]
    #[TestDox('Tags: a tag with namespace can be renamed')]
    public function renameTagWithNamespace(): void
    {
        $xml = '<TEI xmlns="http://www.tei-c.org/ns/1.0" xmlns:rng="http://relaxng.org/ns/structure/1.0" xml:lang="de">'.
               "<rng:foo>Element content</rng:foo>\n".
               "<foo>Should not be changed</foo>\n".
               '</TEI>';

        $exp = '<TEI xmlns="http://www.tei-c.org/ns/1.0" xmlns:rng="http://relaxng.org/ns/structure/1.0" xml:lang="de">'.
               "<test>Element content</test>\n".
               "<foo>Should not be changed</foo>\n".
               '</TEI>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('rng:foo' === $tag) {
                    return [XMLTransformer::RULE_TAG => 'test'];
                }

                return null;
            }
        );

        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Attributes: attributes with or without namespace can be added')]
    public function addAttributes(): void
    {
        $xml = <<<__XML1__
<root>
<element>Element content</element>
<empty />
</root>
__XML1__;

        $exp = <<<__EXP1__
<root xml:id="abc&quot;123">
<element attr="value">Element content</element>
<empty attr="value" />
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag || 'empty' === $tag) {
                    return [
                        '@attr' => 'value',
                    ];
                }

                return [
                    '@xml:id' => 'abc"123',
                ];
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Attributes: an attribute in the default namespace can be renamed')]
    public function renameAttributeInDefaultNamespace(): void
    {
        $xml = <<<__XML1__
<root a="b" c="d">
<element a="b">Element content</element>
<empty c="d" />
</root>
__XML1__;

        $exp = <<<__EXP1__
<root newname="b" c="d">
<element newname="b">Element content</element>
<empty c="d" />
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('empty' !== $tag) {
                    return [
                        '@a' => '@newname',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Attributes: an attribute with namespace can be renamed')]
    public function renameAttributeWithNamespace(): void
    {
        $xml = <<<__XML1__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b" xml:a="d">Element content</element>
</TEI>
__XML1__;

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element xyz="b" c="d">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@a' => '@xyz',
                        '@xml:a' => '@c',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b" xml:a="Literal">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@xml:a' => 'Literal',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@xml:a' => false,
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element xml:id="b" rs="d">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@a' => '@xml:id',
                        '@xml:a' => '@rs',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Attributes: the value of an attribute in the default namespace can be changed')]
    public function changeValueOfAnAttributeInDefaultNamespace(): void
    {
        $xml = '<root a="change me" b="unchanged" xml:id="foo"></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function () {
                return [
                    '@a' => 'Contains < > &',
                ];
            }
        );

        static::assertSame('<root a="Contains &lt; &gt; &amp;" b="unchanged" xml:id="foo"></root>', $actual);
    }

    #[Test]
    #[TestDox('Attributes: the value of an attribute with namespace can be renamed')]
    public function changeValueOfAnAttributeWithNamespace(): void
    {
        $xml = '<root xmlns:rng="http://relaxng.org/ns/structure/1.0" a="unchanged" b="unchanged" rng:id="change me"></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function () {
                return [
                    '@rng:id' => 'Contains < > &',
                ];
            }
        );

        static::assertSame('<root xmlns:rng="http://relaxng.org/ns/structure/1.0" a="unchanged" b="unchanged" rng:id="Contains &lt; &gt; &amp;"></root>', $actual);
    }

    #[Test]
    #[TestDox('Attributes: an attribute in the default namespace can be removed')]
    public function removeAttributeInDefaultNamespace(): void
    {
        $actual = XMLTransformer::transformString(
            '<root><element a="b">Foo</element></root>',
            static function () {
                return [
                    '@a' => false,
                ];
            }
        );

        static::assertSame('<root><element>Foo</element></root>', $actual);
    }

    #[Test]
    #[TestDox('Attributes: an attribute with namespace can be removed')]
    public function removeAttributeWithNamespace(): void
    {
        $actual = XMLTransformer::transformString(
            '<root><element xml:id="foo">Foo</element></root>',
            static function () {
                return [
                    '@xml:id' => false,
                ];
            }
        );

        static::assertSame('<root><element>Foo</element></root>', $actual);
    }

    #[Test]
    #[TestDox('Attributes: when renaming attributes, no attributes are accidentally added')]
    public function onlyAttributesWhichArePresentInTheSourceTagAreRenamed(): void
    {
        $xml = <<<__XML1__
<root a="b" c="d">
<element a="b">Element content</element>
</root>
__XML1__;

        $exp = <<<__EXP1__
<root a="b" d="d">
<element a="b">Element content</element>
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function () {
                return [
                    '@c' => '@d',
                ];
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Attributes: escaped special characters remain unmodified in attribute values')]
    public function escapedCharsInAttributes(): void
    {
        $xml = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';
        $expected = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';

        static::assertSame($expected, XMLTransformer::transformString($xml, static fn () => null));
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted before an element')]
    public function addContentBeforeElement(): void
    {
        $actual = XMLTransformer::transformString(
            '<root><element>Element content</element></root>',
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_ADD_BEFORE => 'Content before',
                    ];
                }

                return null;
            }
        );

        static::assertSame('<root>Content before<element>Element content</element></root>', $actual);
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted before an element’ content')]
    public function addContentAtStartOfElement(): void
    {
        $xml = <<<__XML1__
<root>
<element>Element content</element>
</root>
__XML1__;

        $exp = <<<__EXP1__
<root>
<element>Static content + Element content</element>
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_ADD_START => 'Static content + ',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted before an element’s closing tag')]
    public function addContentAtEndOfElement(): void
    {
        $xml = <<<__XML1__
<root>
<element>Element content</element>
</root>
__XML1__;

        $exp = <<<__EXP1__
<root>
<element>Element content + Static content</element>
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_ADD_END => ' + Static content',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted after an element’s closing tag')]
    public function addContentAfterElement(): void
    {
        $xml = <<<__XML1__
<root>
<element>Element content</element>
</root>
__XML1__;

        $exp = <<<__EXP1__
<root>
<element>Element content</element>Stuff behind
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_ADD_AFTER => 'Stuff behind',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted after an empty element')]
    public function addContentAfterEmptyElement(): void
    {
        $xml = '<root><empty /></root>';
        $exp = '<root><empty />Content</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('empty' === $tag) {
                    return [
                        XMLTransformer::RULE_ADD_AFTER => 'Content',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Insertion: content can be inserted before an empty element that should be removed')]
    public function addContentBeforeEmptyElementToBeRemoved(): void
    {
        $xml = '<root><empty /></root>';
        $exp = '<root>Stuff before</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('empty' === $tag) {
                    return [
                        XMLTransformer::RULE_TAG => false,
                        XMLTransformer::RULE_ADD_BEFORE => 'Stuff before',
                    ];
                }

                return null;
            }
        );
        static::assertSame($exp, $actual);
    }

    #[Test]
    #[TestDox('Insertion: trying to insert content at the beginning of an empty tag throws an exception')]
    public function throwWhenInsertAtBeginningOfEmptyTag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('“insstart” does not make sense');

        XMLTransformer::transformString(
            '<root><empty /></root>',
            static function () {
                return [
                    XMLTransformer::RULE_TAG => false,
                    XMLTransformer::RULE_ADD_START => 'String',
                ];
            }
        );
    }

    #[Test]
    #[TestDox('Insertion: trying to insert content at the end of an empty tag throws an exception')]
    public function throwWhenInsertAtEndOfEmptyTag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('“insend” does not make sense');

        XMLTransformer::transformString(
            '<root><empty /></root>',
            static function () {
                return [
                    XMLTransformer::RULE_TAG => false,
                    XMLTransformer::RULE_ADD_END => 'String',
                ];
            }
        );
    }

    #[Test]
    #[TestDox('Transformation: an outer transformation callback gets the unmodified content as argument')]
    public function applyOuterTransformationArgument(): void
    {
        $xml = '<root><element>Element <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_OUTER => function ($str) {
                            if ('<element>Element <tag>content</tag></element>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }

                            return $str;
                        },
                    ];
                }

                return null;
            }
        );
        static::assertSame($xml, $actual);
    }

    #[Test]
    #[TestDox('Transformation: an outer transformation replaces the tag and its content')]
    public function applyOuterTransformation(): void
    {
        $xml = '<root><element a="b">Element <tag>content</tag></element><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_OUTER => function () {
                            return '<foo />';
                        },
                    ];
                }

                return null;
            }
        );
        static::assertSame('<root><foo /><c /></root>', $actual);
    }

    #[Test]
    #[TestDox('Transformation: outer transformations can be applied to nested tags')]
    public function applyOuterTransformationWithNestedTags(): void
    {
        $xml = '<root><element abc="def">Foobar <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_OUTER => function ($str) {
                            return strip_tags(str_replace('Foobar', 'Hello', $str));
                        },
                    ];
                }
                if (XMLTransformer::RULE_TAG === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_OUTER => function () {
                            return 'World';
                        },
                    ];
                }

                return [XMLTransformer::RULE_TAG => false];
            }
        );
        static::assertSame('Hello World', $actual);
    }

    #[Test]
    #[TestDox('Transformation: trying to use an outer transformation on an empty tag throws an exception')]
    public function throwUponOuterTransformForAnEmptyTag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('“transformOuter” does not work with empty tags');

        XMLTransformer::transformString(
            '<root><empty /></root>',
            static function () {
                return [
                    XMLTransformer::RULE_TRANSFORM_OUTER => static fn () => null,
                ];
            }
        );
    }

    #[Test]
    #[TestDox('Transformation: an inner transformation callback gets the unmodified content as argument')]
    public function applyInnerTransformationArgument(): void
    {
        $xml = '<root><element>Element <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_INNER => function ($str) {
                            if ('Element <tag>content</tag>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }

                            return $str;
                        },
                    ];
                }

                return null;
            }
        );
        static::assertSame($xml, $actual);
    }

    #[Test]
    #[TestDox('Transformation: an inner transformation replaces the tag and its content')]
    public function applyInnerTransformation(): void
    {
        $xml = '<root><element a="b">Element <tag>content</tag></element><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            static function ($tag) {
                if ('element' === $tag) {
                    return [
                        XMLTransformer::RULE_TRANSFORM_INNER => function () {
                            return 'Foo';
                        },
                    ];
                }

                return null;
            }
        );
        static::assertSame('<root><element a="b">Foo</element><c /></root>', $actual);
    }

    #[Test]
    #[TestDox('Transformation: an inner transformation on an empty tag replaces the tag')]
    public function emptyTagInnerTransformReplacesTheTag(): void
    {
        $actual = XMLTransformer::transformString(
            '<root><empty /></root>',
            static function () {
                return [
                    XMLTransformer::RULE_TRANSFORM_INNER => static fn () => 'Hello world',
                ];
            }
        );

        static::assertSame('<root>Hello world</root>', $actual);
    }
}
