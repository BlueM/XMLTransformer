<?php

require_once __DIR__.'/../lib/BlueM/XMLTransformer.php';

use BlueM\XMLTransformer;

/**
 * Test class for XMLTransformer.
 *
 * @package XMLTransformer
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD License
 */
class XMLTransformerTest extends PHPUnit_Framework_TestCase
{

    /**
     * @test
     * @expectedException PHPUnit_Framework_Error_Warning
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
     * @expectedException InvalidArgumentException
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
            'valid_function'
        );
        $this->assertSame("Callback function was called for <xml>", $actual);
    }

    /**
     * @test
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage it must have exactly 2
     */
    public function invokingTheTransformerWithAnUnusableMethodArrayThrowsAnException()
    {
        XMLTransformer::transformString(
            '<xml></xml>',
            array('stdClass')
        );
    }

    /**
     * @test
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Invalid callback method
     */
    public function invokingTheTransformerWithAnInvalidArrayThrowsAnException()
    {
        XMLTransformer::transformString(
            '<xml></xml>',
            array('TestObject', 'unaccessible')
        );
    }

    /**
     * @test
     */
    public function invokeTheTransformerWithAValidCallbackMethod()
    {
        $actual = XMLTransformer::transformString(
            '<xml></xml>',
            array('TestObject', 'transform')
        );
        $this->assertSame("Callback method was called for <xml>", $actual);
    }

    /**
     * @test
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Callback must be function, method or closure
     */
    public function invokingTheTransformerWithCrapAsCallbackThrowsAnException()
    {
        XMLTransformer::transformString('<xml></xml>', new stdClass);
    }

    /**
     * @test
     * @expectedException UnexpectedValueException
     * @expectedExceptionMessage Unexpected key “unexpected” in array returned
     */
    public function returningAnUnexpectedArrayKeyThrowsAnException()
    {
        XMLTransformer::transformString(
            '<root></root>',
            function () {
                return array(
                    'unexpected' => 'value',
                );
            }
        );
    }

    /**
     * @test
     */
    public function returningAnEmptyArrayYieldsNoModifications()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty />\n".
               "</root>";

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return array();
            }
        );

        $this->assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function returningNothingOrNullYieldsNoModifications()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty />\n".
               "</root>";

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag, $attributes, $opening) {
            }
        );
        $this->assertSame($xml, $actual);

        $actual = XMLTransformer::transformString(
            $xml,
            function () {
                return null;
            }
        );
        $this->assertSame($xml, $actual);
    }

    /**
     * @test
     */
    public function returningFalseRemovesTheTagAndItsContent()
    {
        $xml = "<root>\n".
               "<ignore>Element <em>content</em></ignore>\n".
               "<empty />\n".
               "</root>";

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('ignore' == $tag) {
                    return false;
                }
            }
        );

        $exp = "<root>\n\n".
               "<empty />\n".
               "</root>";

        $this->assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function returningFalseForTheTagRemovesTheTagButKeepsTheContent()
    {
        $xml = "<root>\n".
               "<element>Element <em>content</em></element>\n".
               "<empty />\n".
               "</root>";

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' == $tag) {
                    return array('tag' => false);
                }
            }
        );

        $exp = "<root>\n".
               "Element <em>content</em>\n".
               "<empty />\n".
               "</root>";

        $this->assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function renamingATagWithoutNamespaceWorks()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty/>\n".
               "</root>";

        $exp = "<toplevel>\n".
               "<a>Element content</a>\n".
               "<b />\n".
               "</toplevel>";

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('root' == $tag) {
                    return array('tag' => 'toplevel');
                }
                if ('element' == $tag) {
                    return array('tag' => 'a');
                }
                return array('tag' => 'b');
            }
        );

        $this->assertSame($exp, $actual);
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
               "</TEI>";

        $exp = '<TEI xmlns="http://www.tei-c.org/ns/1.0"'.
               ' xmlns:rng="http://relaxng.org/ns/structure/1.0"'.
               ' xml:lang="de">'."\n".
               "<test>Element content</test>\n".
               "<foo>Should not be changed</foo>\n".
               "</TEI>";

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('rng:foo' == $tag) {
                    return array('tag' => 'test');
                }
            }
        );

        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return false;
                }
            }
        );

        $this->assertSame($exp, $actual);
    }

    /**
     * @test
     */
    public function removingATagButKeepingItsContentWorks()
    {
        $xml = "<root>\n".
               "<element>Element content</element>\n".
               "<empty />\n".
               '</root>';

        $exp = "<root>\n".
               "Element content\n".
               "<empty />\n".
               '</root>';

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' == $tag) {
                    return array('tag' => false);
                }
            }
        );

        $this->assertSame($exp, $actual);
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
                if ('empty' == $tag) {
                    return false;
                }
            }
        );

        $this->assertSame($exp, $actual);
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
                if ('element' == $tag or
                    'empty' == $tag
                ) {
                    return array(
                        '@attr' => 'value',
                    );
                }
                return array(
                    '@xml:id' => 'abc"123',
                );
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('empty' != $tag) {
                    return array(
                        '@a' => '@newname',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return array(
                        '@a'     => '@xyz',
                        '@xml:a' => '@c',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b" xml:a="Literal">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' == $tag) {
                    return array(
                        '@xml:a' => 'Literal',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' == $tag) {
                    return array(
                        '@xml:a' => false,
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);

        $exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element xml:id="b" rs="d">Element content</element>
</TEI>
__EXP__;

        $actual = XMLTransformer::transformString(
            $xml,
            function ($tag) {
                if ('element' == $tag) {
                    return array(
                        '@a'     => '@xml:id',
                        '@xml:a' => '@rs',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                return array(
                    '@c'      => 'Literal',
                    '@a'      => 'Contains < > &',
                    '@xml:id' => 'bar',
                );
            }
        );
        $this->assertSame($exp, $actual);
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
                return array(
                    '@a' => false,
                );
            }
        );
        $this->assertSame($exp, $actual);
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
                return array(
                    '@c' => '@d',
                );
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'insbefore' => 'Content outside',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'insstart' => 'Static content + ',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'insend' => ' + Static content',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'insafter' => 'Stuff behind',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('empty' == $tag) {
                    return array(
                        'insafter' => 'Content',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ('empty' == $tag) {
                    return array(
                        'tag'       => false,
                        'insbefore' => 'Stuff before',
                    );
                }
            }
        );
        $this->assertSame($exp, $actual);
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
                if ($type === BlueM\XMLTransformer::ELOPEN) {
                    return array(
                        'tag'      => false,
                        'insstart' => "<$tag>"
                    );
                } elseif ($type === BlueM\XMLTransformer::ELCLOSE) {
                    return array(
                        'tag'    => false,
                        'insend' => "</$tag>"
                    );
                } else {
                    return array(
                        'tag'       => false,
                        'insbefore' => "<$tag/>"
                    );
                }
            }
        );
        $this->assertSame($xml, $actual);
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
                return array(
                    'tag'      => false,
                    'insstart' => 'String',
                );
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
                return array(
                    'tag'    => false,
                    'insend' => 'String',
                );
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
                if ('element' == $tag) {
                    return array(
                        'transformOuter' => function ($str) {
                            if ('<element>Element <tag>content</tag></element>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }
                            return $str;
                        },
                    );
                }
            }
        );
        $this->assertSame($xml, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'transformOuter' => function ($str) {
                            return '<foo />';
                        },
                    );
                }
            }
        );
        $this->assertSame('<root><foo /><c /></root>', $actual);
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
                if ('element' == $tag) {
                    return array(
                        'transformOuter' => function ($str) {
                            return strip_tags(str_replace('Foobar', 'Hello', $str));
                        },
                    );
                }
                if ('tag' == $tag) {
                    return array(
                        'transformOuter' => function () {
                            return 'World';
                        },
                    );
                }
                return array('tag' => false);
            }
        );
        $this->assertSame('Hello World', $actual);
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
                if ('element' == $tag) {
                    return array(
                        'transformInner' => function ($str) {
                            if ('Element <tag>content</tag>' !== $str) {
                                throw new \UnexpectedValueException('Wrong element content');
                            }
                            return $str;
                        },
                    );
                }
            }
        );
        $this->assertSame($xml, $actual);
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
                if ('element' == $tag) {
                    return array(
                        'transformInner' => function ($str) {
                            return 'Foo';
                        },
                    );
                }
            }
        );
        $this->assertSame('<root><element a="b">Foo</element><c /></root>', $actual);
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
                if ('ignore' == $tag) {
                    return false;
                }

            }
        );
        $this->assertSame($expected, $actual);
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
        $this->assertSame($expected, $actual);
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

        $this->assertSame('', trim($actual));
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

        $this->assertSame('<root><a>Works as expected</a></root>', $actual);
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
    static function transform($tag)
    {
        return array(
            'tag'      => false,
            'insstart' => "Callback method was called for <$tag>",
        );
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
    return array(
        'tag'      => false,
        'insstart' => "Callback function was called for <$tag>",
    );
}
