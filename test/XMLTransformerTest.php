<?php

require_once __DIR__.'/../lib/BlueM/XMLTransformer.php';

use BlueM\XMLTransformer;

/**
 * Test class for XMLTransformer.
 * @package XMLTransformer
 * @author Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD License
 */
class XMLTransformerTest extends PHPUnit_Framework_TestCase {

	/**
	 * @test
 	 * @expectedException PHPUnit_Framework_Error_Warning
	 */
	function invokingTheTransformerWithInvalidXmlProducesAnError() {
		XMLTransformer::transformString(
			'<xml></xl>',
			function() { }
		);
	}

	/**
	 * @test
	 * @expectedException InvalidArgumentException
	 * @expectedExceptionMessage Invalid callback function
	 */
	function invokingTheTransformerWithAnInvalidCallbackFunctionThrowsAnException() {
		XMLTransformer::transformString(
			'<xml></xml>',
			'nonexistentfunction'
		);
	}

	/**
	 * @test
	 */
	function invokeTheTransformerWithAValidCallbackFunction() {
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
	function invokingTheTransformerWithAnUnusableMethodArrayThrowsAnException() {
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
	function invokingTheTransformerWithAnInvalidArrayThrowsAnException() {
		XMLTransformer::transformString(
			'<xml></xml>',
			array('TestObject', 'unaccessible')
		);
	}

	/**
	 * @test
	 */
	function invokeTheTransformerWithAValidCallbackMethod() {
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
	function invokingTheTransformerWithCrapAsCallbackThrowsAnException() {
		XMLTransformer::transformString('<xml></xml>', new stdClass);
	}

	/**
	 * @test
	 * @expectedException UnexpectedValueException
	 * @expectedExceptionMessage Unexpected key "unexpected" in array returned
	 */
	function returningAnUnexpectedArrayKeyThrowsAnException() {
		XMLTransformer::transformString(
			'<root></root>',
			function($tag, $attributes, $opening) {
				return array(
					'unexpected'=>'value',
				);
			}
		);
	}

	/**
	 * @test
	 */
	function returningAnEmptyArrayYieldsNoModifications() {
		$xml = "<root>\n".
		       "<element>Element content</element>\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				return array();
			}
		);

		$this->assertSame($xml, $actual);
	}

	/**
	 * @test
	 */
	function returningNothingOrNullYieldsNoModifications() {

		$xml = "<root>\n".
		       "<element>Element content</element>\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) { }
		);
		$this->assertSame($xml, $actual);

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {return null;}
		);
		$this->assertSame($xml, $actual);
	}

	/**
	 * @test
	 */
	function returningFalseRemovesTheTagAndItsContent() {

		$xml = "<root>\n".
		       "<ignore>Element <em>content</em></ignore>\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
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
	function returningFalseForTheTagRemovesTheTagButKeepsTheContent() {
		$xml = "<root>\n".
		       "<element>Element <em>content</em></element>\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array('tag'=>false);
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
	function renameTheTag() {
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
			function($tag, $attributes, $opening) {
				if ('root' == $tag) {
					return array('tag'=>'toplevel');
				}
				if ('element' == $tag) {
					return array('tag'=>'a');
				}
				return array('tag'=>'b');
			}
		);

		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function renameTheTagWithNamespaces() {
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
			function($tag, $attributes, $opening) {
				if ('rng:foo' == $tag) {
					return array('tag'=>'test');
				}
			}
		);

		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function removeATagIncludingContent() {
		$xml = "<root>\n".
		       "<element>Element content</element>\n".
		       "<empty />\n".
		       "</root>";

		$exp = "<root>\n".
		       "\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
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
	function removeATagWithoutContent() {
		$xml = "<root>\n".
		       "<element>Element content</element>\n".
		       "<empty />\n".
		       "</root>";

		$exp = "<root>\n".
		       "Element content\n".
		       "<empty />\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array('tag'=>false);
				}
			}
		);

		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function removeAnEmptyTag() {
		$xml = "<root>\n".
		       "<empty />\n".
		       "</root>";

		$exp = "<root>\n".
		       "\n".
		       "</root>";

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
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
	function renameTheAttributes() {

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
			function($tag, $attributes, $opening) {
				if ('empty' != $tag) {
					return array(
						'@a'=>'@newname',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function renameTheAttributesWithNamespaces() {

		$xml = <<<__XML1__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b" xml:a="d">Element content</element>
</TEI>
__XML1__;

		$exp = <<<__EXP__
<TEI xmlns="http://www.tei-c.org/ns/1.0">
<element a="b" c="d">Element content</element>
</TEI>
__EXP__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'@xml:a'=>'@c',
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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'@xml:a'=>'Literal',
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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'@xml:a'=>false,
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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'@a'=>'@xml:id',
						'@xml:a'=>'@rs',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function changeAnAttributeValue() {

		$xml = <<<__XML1__
<root a="b" c="d">
</root>
__XML1__;

		$exp = <<<__EXP1__
<root a="Contains &lt; &gt; &amp;" c="Literal">
</root>
__EXP1__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				return array(
					'@c'=>'Literal',
					'@a'=>'Contains < > &',
				);
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function removeAnAttributeValue() {

		$xml = <<<__XML1__
<root>
<element a="b">Foo</element>
</root>
__XML1__;

		$exp = <<<__EXP1__
<root>
<element>Foo</element>
</root>
__EXP1__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				return array(
					'@a'=>false,
				);
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function makeSureOnlyAttributesThatArePresentInTheSourceTagAreRenamed() {

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
			function($tag, $attributes, $opening) {
				return array(
					'@c'=>'@d',
				);
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function insertContentBeforeAnElement() {

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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'insbefore'=>'Content outside',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function insertContentAfterAnElement() {

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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'insstart'=>'Static content + ',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function insertContentInsideAtTheEnd() {

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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'insend'=>' + Static content',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function insertContentOutsideAtTheEnd() {

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
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'insafter'=>'Stuff behind',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function insertContentBehindAnEmptyElement() {

		$xml = '<root><element /></root>';
		$exp = '<root><element />Behind</root>';

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag) {
				if ('element' == $tag) {
					return array(
						'insafter'=>'Behind',
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
	function insertContentAfterAnEmptyTag() {

		$xml = '<root><empty /></root>';
		$exp = '<root><empty />Content</root>';

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag) {
				if ('empty' == $tag) {
					return array(
						'insafter'=>'Content',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 * @expectedException RuntimeException
	 * @expectedExceptionMessage “insstart” does not make sense
	 */
	function tryingToInsertContentAtTheBeginningOfAnEmptyTagThrowsAnException() {

		XMLTransformer::transformString(
			'<root><empty /></root>',
			function($tag, $attributes, $opening) {
				return array(
					'tag'=>false,
					'insstart'=>'String',
				);
			}
		);
	}

	/**
	 * @test
	 * @expectedException RuntimeException
	 * @expectedExceptionMessage “insend” does not make sense
	 */
	function tryingToInsertContentAtTheEndOfAnEmptyTagThrowsAnException() {

		XMLTransformer::transformString(
			'<root><empty /></root>',
			function($tag, $attributes, $opening) {
				return array(
					'tag'=>false,
					'insend'=>'String',
				);
			}
		);
	}

	/**
	 * @test
	 */
	function insertContentBeforeAnEmptyTagToBeRemoved() {

		$xml = '<root><empty /></root>';
		$exp = '<root>Stuff before</root>';

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('empty' == $tag) {
					return array(
						'tag'=>false,
						'insbefore'=>'Stuff before',
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function testTransformation() {

		$xml = <<<__XML1__
<root>
<element>Element content</element>
</root>
__XML1__;

		$exp = <<<__EXP1__
<root>
<element>Transformed</element>
</root>
__EXP1__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'transform'=>function($str) {
							return preg_replace(
								'#<element>.*?</element>#',
								'<element>Transformed</element>',
								$str
							);
						},
					);
				}
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function testTransformationInputByNotModifyingTheTransformationInputString() {

		$xml = <<<__XML1__
<root>
<element>Element <tag>content</tag></element>
</root>
__XML1__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				if ('element' == $tag) {
					return array(
						'transform'=>function($str) {
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
	function testTransformationWithContentBehinNestedIgnorableTags() {

		$xml = <<<__XML1__
<root>
<a><ignore><b>Blah</b><ignore>content</ignore></ignore><ignore>content</ignore>Hallo Welt</a>
</root>
__XML1__;

		$expected = <<<__XML2__
<root>
<a>Hallo Welt</a>
</root>
__XML2__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
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
	function testIfEscapedSpecialCharactersRemainUnmodifiedInAttributeValues() {

		$xml = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';
		$expected = '<root><test attr="&amp; &lt; &gt;">Foo</test></root>';
		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				// No modification
			}
		);
		$this->assertSame($expected, $actual);
	}

	/**
	 * @test
	 */
	function addAnAttribute() {

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
			function($tag, $attributes, $opening) {
				if ('element' == $tag or
				    'empty' == $tag) {
				    return array(
						'@attr'=>'value',
					);
				}
				return array(
					'@xml:id'=>'abc"123',
				);
			}
		);
		$this->assertSame($exp, $actual);
	}

	/**
	 * @test
	 */
	function testWithNestedTagsThatShouldBeRemovedCompletely() {

		$xml = <<<__XML1__
<a>
<b><c>X</c></b>
</a>
__XML1__;

		$actual = XMLTransformer::transformString(
			$xml,
			function($tag, $attributes, $opening) {
				switch ($tag) {
					case 'a':
					case 'c':
					    return false;
				}
			}
		);

		$this->assertSame('', trim($actual));
	}

}

class TestObject {

	protected function unaccessible() { }

	static function transform($tag, $attributes, $opening) {
		return array(
			'tag'=>false,
			'insstart'=>"Callback method was called for <$tag>",
		);
	}
}

function valid_function($tag, $attributes, $opening) {
	return array(
		'tag'=>false,
		'insstart'=>"Callback function was called for <$tag>",
	);
}

