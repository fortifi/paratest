<?php

class SkippedOrIncompleteTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @group skipped-group
     */
    public function testSkipped()
    {
        $this->markTestSkipped();
    }

    /**
     * @group incomplete-group
     */
    public function testIncomplete()
    {
        $this->markTestIncomplete();
    }

    public function dataProviderNumeric100()
    {
        $result = [];
        for ($i = 0; $i < 100; $i++) {
            $result[] = [$i, $i];
        }

        return $result;
    }

    /**
     * @group dataset-group
     * @dataProvider dataProviderNumeric100
     */
    public function testDataProviderWithSkipped($expected, $actual)
    {
        if ($expected % 3 == 0) {
            $this->markTestSkipped();
        } elseif ($expected % 3 == 1) {
            $this->markTestIncomplete();
        }

        $this->assertEquals($expected, $actual);
    }
}
