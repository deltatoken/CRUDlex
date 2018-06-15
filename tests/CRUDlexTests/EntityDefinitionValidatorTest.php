<?php

/*
 * This file is part of the CRUDlex package.
 *
 * (c) Philip Lehmann-Böhm <philip@philiplb.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CRUDlexTests;

use CRUDlex\EntityDefinitionValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class EntityDefinitionValidatorTest extends TestCase
{

    public function testValidate()
    {

        $fileContent = file_get_contents(__DIR__.'/../crud.yml');
        $validDefinition = Yaml::parse($fileContent);

        $validator = new EntityDefinitionValidator();

        $validator->validate($validDefinition);

        try {
            $validator->validate(['foo' => 'bar']);
            $this->fail();
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail();
        }

    }

}
