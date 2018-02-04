<?php

namespace BlueM;

use PHPUnit\Framework\TestCase;

/**
 * Tests for XMLTransformer
 *
 * @package XMLTransformer
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD 2-Clause License
 */
class XMLTransformerTest extends TestCase
{
    /**
     * @test
     * @expectedException \PHPUnit\Framework\Error\Warning
     */
    public function invokingTheTransformerWithInvalidXmlProducesAnError()
    {
        XMLTransformer::transformString(
            '<xml></xl>',
            function () {
            }
        );
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid callback function
     */
    public function invokingTheTransformerWithAnInvalidCallbackFunctionThrowsAnException()
    {
        XMLTransformer::transformString(
            '<xml></xml>',
            'nonexistentfunction'
        );
    }

    /**
     * @test
     */
    public function invokingTheTransformerWithAValidCallbackFunctionWorks()
    {
        $actual = XMLTransformer::transformString(
            '<xml></xml>',
            __NAMESPACE__.'\valid_function'
        );
        static::assertSame('Callback function was called for <xml>', $actual);
    }

    /**
     * @test
     */
    public function invokingTheTransformerWithAValidCallbackFunctionWorksWhenArgumentsAreReferences()
    {
        if (version_compare(PHP_VERSION, '5.4') < 0) {
            $this->markTestSkipped('Test not applicable when on PHP 5.3');
        } else {
            $actual = XMLTransformer::transformString(
                '<xml foo="bar"></xml>',
                __NAMESPACE__.'\valid_function_by_ref'
            );

            static::assertSame('<xml></xml>', $actual);
        }
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage it must have exactly 2
     */
    public function invokingTheTransformerWithAnUnusableMethodArrayThrowsAnException()
    {
        XMLTransformer::transformString(
            '<xml></xml>',
            ['stdClass']
        );
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid callback method
     */
    public function invokingTheTransformerWithAnInvalidArrayThrowsAnException()
    {
        XMLTransformer::transformString(
            '<xml></xml>',
            [__NAMESPACE__.'TestObject', 'unaccessible']
        );
    }

    /**
     * @test
     */
    public function invokeTheTransformerWithAValidCallbackMethod()
    {
        $actual = XMLTransformer::transformString(
            '<xml></xml>',
            [__NAMESPACE__.'\TestObject', 'transform']
        );
        static::assertSame('Callback method was called for <xml>', $actual);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Callback must be function, method or closure
     */
    public function invokingTheTransformerWithCrapAsCallbackThrowsAnException()
    {
        XMLTransformer::transformString('<xml></xml>', new class {});
    }

    /**
     * @test
     * @expectedException \UnexpectedValueException
     * @expectedExceptionMessage Unexpected key “unexpected” in array returned
     */
    public function returningAnUnexpectedArrayKeyThrowsAnException()
    {
        XMLTransformer::transformString(
            '<root></root>',
            function () {
                return [
                    'unexpected' => 'value',
                ];
            }
        );
    }

    /**
     * @test
     */
    public function returningAnEmptyArrayYieldsNoModifications()
    {
        $xml = "<root>\n".
               "<element1>Element content</element1>\n".
               "<element2><![CDATA[This is content: < & >]]> <![CDATA[More <strong>cdata</strong>.]]></element2>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return [];
            }
        );

        static::assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function returning_an_empty_array_only_escapes_CDATA_if_CDATA_should_not_be_preserved()
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

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return [];
            },
            false
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function returningNothingOrNullYieldsNoModifications()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag, $attributes, $opening) {
            }
        );
        static::assertSame($xml, $actual);

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return null;
            }
        );
        static::assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function returningFalseRemovesTheTagAndItsContent()
    {
        $xml = "<root>\n".
               "<ignore>Element <em>content</em></ignore>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('ignore' === $tag) {
                    return false;
                }
            }
        );

        $exp = "<root>\n\n".
               "<empty />\n".
               '</root>';

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function returningFalseForTheTagRemovesTheTagButKeepsTheContent()
    {
        $xml = "<root>\n".
               "<element>Element <em>content</em></element>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return ['tag' => false];
                }
            }
        );

        $exp = "<root>\n".
               "Element <em>content</em>\n".
               "<empty />\n".
               '</root>';

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function renamingATagWithoutNamespaceWorks()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty/>\n".
               '</root>';

        $exp = "<toplevel>\n".
               "<a>Element content</a>\n".
               "<b />\n".
               '</toplevel>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('root' === $tag) {
                    return ['tag' => 'toplevel'];
                }
                if ('element' === $tag) {
                    return ['tag' => 'a'];
                }
                return ['tag' => 'b'];
            }
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function renamingATagWithNamespacesWorks()
    {
        $xml = '<TEI xmlns="http://www.tei-c.org/ns/1.0"'.
               ' xmlns:rng="http://relaxng.org/ns/structure/1.0"'.
               ' xml:lang="de">'."\n".
               "<rng:foo>Element content</rng:foo>\n".
               "<foo>Should not be changed</foo>\n".
               '</TEI>';

        $exp = '<TEI xmlns="http://www.tei-c.org/ns/1.0"'.
               ' xmlns:rng="http://relaxng.org/ns/structure/1.0"'.
               ' xml:lang="de">'."\n".
               "<test>Element content</test>\n".
               "<foo>Should not be changed</foo>\n".
               '</TEI>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('rng:foo' === $tag) {
                    return ['tag' => 'test'];
                }
            }
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function removingATagIncludingContentWorks()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty />\n".
               '</root>';

        $exp = "<root>\n".
               "\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return false;
                }
            }
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function removingATagButKeepingItsContentWorks()
    {
        $xml = "<root>\n".
               "<element1>Element content</element1>\n".
               "<element2><![CDATA[Hello world < & >]]></element2>\n".
               "<empty />\n".
               '</root>';

        $exp = "<root>\n".
               "Element content\n".
               "<![CDATA[Hello world < & >]]>\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element1' === $tag || 'element2' === $tag) {
                    return ['tag' => false];
                }
            }
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function removingAnEmptyTagWorks()
    {
        $xml = "<root>\n".
               "<empty />\n".
               '</root>';

        $exp = "<root>\n".
               "\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('empty' === $tag) {
                    return false;
                }
            }
        );

        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function addingAttributesWithAndWithoutNamespacesWorks()
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
            function ($tag) {
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

    /**
     * @test
     */
    public function renamingAnAttributeWorks()
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
            function ($tag) {
                if ('empty' !== $tag) {
                    return [
                        '@a' => '@newname',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function renamingAnAttributeWithNamespacesWorks()
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@a'     => '@xyz',
                        '@xml:a' => '@c',
                    ];
                }
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@xml:a' => 'Literal',
                    ];
                }
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@xml:a' => false,
                    ];
                }
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        '@a'     => '@xml:id',
                        '@xml:a' => '@rs',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function changingAttributeValuesWithAndWithoutNamespaceWorks()
    {
        $xml = '<root a="b" c="d" xml:id="foo"></root>';
        $exp = '<root a="Contains &lt; &gt; &amp;" c="Literal" xml:id="bar"></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return [
                    '@c'      => 'Literal',
                    '@a'      => 'Contains < > &',
                    '@xml:id' => 'bar',
                ];
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function removingAnAttributeWorks()
    {
        $xml = '<root><element a="b">Foo</element></root>';
        $exp = '<root><element>Foo</element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return [
                    '@a' => false,
                ];
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function onlyAttributesWhichArePresentInTheSourceTagAreRenamed()
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
            function () {
                return [
                    '@c' => '@d',
                ];
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function contentCanBeInsertedBeforeAnElement()
    {
        $xml = <<<__XML1__
<root>
<element>Element content</element>
</root>
__XML1__;

        $exp = <<<__EXP1__
<root>
Content outside<element>Element content</element>
</root>
__EXP1__;

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'insbefore' => 'Content outside',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function contentCanBePrependedToAnElementsContent()
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'insstart' => 'Static content + ',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function contentCanBeAppendedToAnElementsContent()
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'insend' => ' + Static content',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function contentCanBeInsertedAfterANonEmptyElement()
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
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'insafter' => 'Stuff behind',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     * @ticket 2
     */
    public function contentCanBeInsertedAfterAnEmptyElement()
    {
        $xml = '<root><empty /></root>';
        $exp = '<root><empty />Content</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('empty' === $tag) {
                    return [
                        'insafter' => 'Content',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function contentCanBeInsertedBeforeAnEmptyElementThatShouldBeRemoved()
    {
        $xml = '<root><empty /></root>';
        $exp = '<root>Stuff before</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('empty' === $tag) {
                    return [
                        'tag'       => false,
                        'insbefore' => 'Stuff before',
                    ];
                }
            }
        );
        static::assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function theCallbackClosureIsGivenTheCorrectTagTypeConstantAsArgument()
    {
        $xml = '<root><a><b>Hello world</b></a><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag, $attributes, $type) {
                if ($type === XMLTransformer::ELOPEN) {
                    return [
                        'tag'      => false,
                        'insstart' => "<$tag>"
                    ];
                } elseif ($type === XMLTransformer::ELCLOSE) {
                    return [
                        'tag'    => false,
                        'insend' => "</$tag>"
                    ];
                } else {
                    return [
                        'tag'       => false,
                        'insbefore' => "<$tag/>"
                    ];
                }
            }
        );
        static::assertSame($xml, $actual);
    }

    /**
     * @test
     * @expectedException RuntimeException
     * @expectedExceptionMessage “insstart” does not make sense
     */
    public function tryingToInsertContentAtTheBeginningOfAnEmptyTagThrowsAnException()
    {
        XMLTransformer::transformString(
            '<root><empty /></root>',
            function () {
                return [
                    'tag'      => false,
                    'insstart' => 'String',
                ];
            }
        );
    }

    /**
     * @test
     * @expectedException RuntimeException
     * @expectedExceptionMessage “insend” does not make sense
     */
    public function tryingToInsertContentAtTheEndOfAnEmptyTagThrowsAnException()
    {
        XMLTransformer::transformString(
            '<root><empty /></root>',
            function () {
                return [
                    'tag'    => false,
                    'insend' => 'String',
                ];
            }
        );
    }

    /**
     * @test
     */
    public function anOuterTransformationCallbackGetsTheUnmodifiedContentAsArgument()
    {
        $xml = '<root><element>Element <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'transformOuter' => function ($str) {
                            if ('<element>Element <tag>content</tag></element>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }
                            return $str;
                        },
                    ];
                }
            }
        );
        static::assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function anOuterTransformationReplacesTheTagAndItsContent()
    {
        $xml = '<root><element a="b">Element <tag>content</tag></element><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'transformOuter' => function ($str) {
                            return '<foo />';
                        },
                    ];
                }
            }
        );
        static::assertSame('<root><foo /><c /></root>', $actual);
    }

    /**
     * @test
     */
    public function outerContentTransformationWorksWithNestedTagsToBeTransformed()
    {
        $xml = '<root><element abc="def">Foobar <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'transformOuter' => function ($str) {
                            return strip_tags(str_replace('Foobar', 'Hello', $str));
                        },
                    ];
                }
                if ('tag' === $tag) {
                    return [
                        'transformOuter' => function () {
                            return 'World';
                        },
                    ];
                }
                return ['tag' => false];
            }
        );
        static::assertSame('Hello World', $actual);
    }

    /**
     * @test
     */
    public function anInnerTransformationCallbackGetsTheUnmodifiedContentAsArgument()
    {
        $xml = '<root><element>Element <tag>content</tag></element></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'transformInner' => function ($str) {
                            if ('Element <tag>content</tag>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }
                            return $str;
                        },
                    ];
                }
            }
        );
        static::assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function anInnerTransformationKeepsTheTagButReplacesItsContent()
    {
        $xml = '<root><element a="b">Element <tag>content</tag></element><c/></root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' === $tag) {
                    return [
                        'transformInner' => function () {
                            return 'Foo';
                        },
                    ];
                }
            }
        );
        static::assertSame('<root><element a="b">Foo</element><c /></root>', $actual);
    }

    /**
     * @test
     */
    public function contentBehindNestedIgnorableTagsIsNotRemoved()
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
            function ($tag) {
                if ('ignore' === $tag) {
                    return false;
                }

            }
        );
        static::assertSame($expected, $actual);
    }

    /**
     * @test
     */
    public function escapedSpecialCharactersRemainUnmodifiedInAttributeValues()
    {
        $xml      = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';
        $expected = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';
        $actual   = XMLTransformer::transformString(
            $xml,
            function ($tag, $attributes, $opening) {
                // No modification
            }
        );
        static::assertSame($expected, $actual);
    }

    /**
     * @test
     */
    public function removingTagsCompletelyWorksWithNestedTags()
    {
        $xml = <<<__XML1__
<a>
<b><c>X</c></b>
</a>
__XML1__;

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                switch ($tag) {
                    case 'a':
                    case 'c':
                        return false;
                }
            }
        );

        static::assertSame('', trim($actual));
    }

    /**
     * @test
     */
    public function entitiesGetSubstituted()
    {
        $xml = <<<__XML1__
<!DOCTYPE dummy
[
<!ENTITY w "Works as expected">
]>
<root><a>&w;</a></root>
__XML1__;

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return null; // Do not modify anthing
            }
        );

        static::assertSame('<root><a>Works as expected</a></root>', $actual);
    }
}

/**
 * Dummy class used to test using a method as callback
 */
class TestObject
{

    protected function unaccessible()
    {
    }

    /**
     * Dummy method
     *
     * @param $tag
     *
     * @return array
     */
    public static function transform($tag)
    {
        return [
            'tag'      => false,
            'insstart' => "Callback method was called for <$tag>",
        ];
    }
}

/**
 * Dummy function used to test using a function as callback
 *
 * @param $tag
 *
 * @return array
 */
function valid_function($tag)
{
    return [
        'tag'      => false,
        'insstart' => "Callback function was called for <$tag>",
    ];
}

/**
 * Dummy function used to test using a function as callback
 *
 * @param $tag
 *
 * @return array
 */
function valid_function_by_ref($tag, &$attributes)
{
    $attributes = [];
    return null;
}
