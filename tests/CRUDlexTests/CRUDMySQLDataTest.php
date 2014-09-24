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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use CRUDlexTestEnv\CRUDTestDBSetup;
use CRUDlex\CRUDEntity;

class CRUDMySQLDataTest extends \PHPUnit_Framework_TestCase {

    protected $dataBook;

    protected $dataLibrary;

    protected function setUp() {
        $crudServiceProvider = CRUDTestDBSetup::createCRUDServiceProvider();
        $this->dataBook = $crudServiceProvider->getData('book');
        $this->dataLibrary = $crudServiceProvider->getData('library');
    }

    public function testCreate() {
        $entity = $this->dataLibrary->createEmpty();
        $entity->set('name', 'name');
        $this->dataLibrary->create($entity);
        $id = $entity->get('id');
        $this->assertNotNull($id);
        $this->assertTrue($id > 0);
    }

    public function testList() {
        $entity = $this->dataLibrary->createEmpty();
        $entity->set('name', 'nameA');
        $this->dataLibrary->create($entity);
        $entity = new CRUDEntity($this->dataBook->getDefinition());
        $entity->set('name', 'nameB');
        $this->dataLibrary->create($entity);
        $list = $this->dataLibrary->listEntries();
        $read = count($list);
        $expected = 2;
        $this->assertSame($read, $expected);
    }

    public function testGet() {
        $entity = $this->dataLibrary->createEmpty();
        $entity->set('name', 'nameC');
        $this->dataLibrary->create($entity);
        $id = $entity->get('id');
        $entityRead = $this->dataLibrary->get($id);
        $read = $entityRead->get('name');
        $expected = 'nameC';
        $this->assertSame($read, $expected);

        $entity = $this->dataLibrary->get(666);
        $this->assertNull($entity);
    }

    public function testGetDefinition() {
        $definition = $this->dataLibrary->getDefinition();
        $this->assertNotNull($definition);
    }

    public function testCreateEmpty() {
        $entity = $this->dataLibrary->createEmpty();
        $read = $entity->get('id');
        $this->assertNull($read);
    }

    public function testUpdate() {
        $entity = $this->dataLibrary->createEmpty();
        $entity->set('name', 'nameUpdate');
        $this->dataLibrary->create($entity);

        $entity->set('name', 'nameUpdated!');
        $this->dataLibrary->update($entity);
        $entityWritten = $this->dataLibrary->get($entity->get('id'));
        $read = $entityWritten->get('name');
        $expected = 'nameUpdated!';
        $this->assertSame($read, $expected);
    }

    public function testDelete() {
        $entity = $this->dataLibrary->createEmpty();
        $entity->set('name', 'nameDelete');
        $this->dataLibrary->create($entity);

        $deleted = $this->dataLibrary->delete($entity->get('id'));
        $read = $this->dataLibrary->get($entity->get('id'));
        $this->assertTrue($deleted);
        $this->assertNull($read);

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'nameParentTestDelete');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $deleted = $this->dataLibrary->delete($entityLibrary->get('id'));
        $this->assertFalse($deleted);
        $deleted = $this->dataBook->delete($entityBook->get('id'));
        $this->assertTrue($deleted);
        $deleted = $this->dataLibrary->delete($entityLibrary->get('id'));
        $this->assertTrue($deleted);
    }

    public function testGetReferences() {
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'A');
        $this->dataLibrary->create($library);
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'B');
        $this->dataLibrary->create($library);
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'C');
        $this->dataLibrary->create($library);

        $table = $this->dataBook->getDefinition()->getReferenceTable('library');
        $nameField = $this->dataBook->getDefinition()->getReferenceNameField('library');
        $read = $this->dataBook->getReferences($table, $nameField);
        $expected = array(
            '1' => 'A',
            '2' => 'B',
            '3' => 'C',
        );
        $this->assertSame($read, $expected);
    }

    public function testCountBy() {
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'A');
        $this->dataLibrary->create($library);
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'B');
        $this->dataLibrary->create($library);
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'C');
        $this->dataLibrary->create($library);

        $this->dataLibrary->delete($library->get('id'));

        $table = $this->dataLibrary->getDefinition()->getTable();

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 1),
                array('id' => '='),
                false
            );
        $expected = 1;
        $this->assertSame($read, $expected);

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 1),
                array('id' => '!='),
                false
            );
        $expected = 2;
        $this->assertSame($read, $expected);

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 1, 'name' => 'A'),
                array('id' => '=', 'name' => '='),
                false
            );
        $expected = 1;
        $this->assertSame($read, $expected);

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 1, 'name' => 'B'),
                array('id' => '=', 'name' => '='),
                false
            );
        $expected = 0;
        $this->assertSame($read, $expected);

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 3),
                array('id' => '='),
                false
            );
        $expected = 1;
        $this->assertSame($read, $expected);

        $read = $this->dataLibrary->countBy(
                $table,
                array('id' => 3),
                array('id' => '='),
                true
            );
        $expected = 0;
        $this->assertSame($read, $expected);
    }

    public function testFetchReferences() {
        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);


        $read = $entityBook->get('library');
        $expected = '1';
        $this->assertSame($read, $expected);

        $this->dataBook->fetchReferences($entityBook);
        $read = $entityBook->get('library');
        $expected = array('id' => '1', 'name' => 'lib');
        $this->assertSame($read, $expected);

        $this->dataBook->fetchReferences(null);
    }

    public function testBoolHandling() {
        $libraryA = $this->dataLibrary->createEmpty();
        $libraryA->set('name', 'lib');
        $this->dataLibrary->create($libraryA);

        $read = $this->dataLibrary->get($libraryA->get('id'))->get('isOpenOnSundays');
        $this->assertFalse($read);

        $libraryB = $this->dataLibrary->createEmpty();
        $libraryB->set('name', 'lib');
        $libraryB->set('isOpenOnSundays', '1');
        $this->dataLibrary->create($libraryB);

        $read = $this->dataLibrary->get($libraryB->get('id'))->get('isOpenOnSundays');
        $this->assertTrue($read);

        $libraryA->set('isOpenOnSundays', '1');
        $this->dataLibrary->update($libraryA);

        $read = $this->dataLibrary->get($libraryA->get('id'))->get('isOpenOnSundays');
        $this->assertTrue($read);

        $libraryB->set('isOpenOnSundays', null);
        $this->dataLibrary->update($libraryB);

        $read = $this->dataLibrary->get($libraryB->get('id'))->get('isOpenOnSundays');
        $this->assertFalse($read);
    }

    public function testCreateFiles() {

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $request = new Request(array(), array(
            'title' => 'title',
            'author' => 'author',
            'pages' => 111,
            'library' => $entityLibrary->get('id')
        ), array(), array(), array(
            'cover' => new UploadedFile(__DIR__.'/../test.xml', 'test.xml')
        ));

        $fileProcessor = CRUDTestDBSetup::getFileProcessor();
        $fileProcessor->reset();

        $this->dataBook->createFiles($request, $entityBook, 'book');

        $this->assertTrue($fileProcessor->isCreateFileCalled());
        $this->assertFalse($fileProcessor->isUpdateFileCalled());
        $this->assertFalse($fileProcessor->isDeleteFileCalled());
        $this->assertFalse($fileProcessor->isRenderFileCalled());

        $fileProcessor->reset();
    }

    public function testUpdateFiles() {

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $request = new Request(array(), array(
            'title' => 'title',
            'author' => 'author',
            'pages' => 111,
            'library' => $entityLibrary->get('id')
        ), array(), array(), array(
            'cover' => new UploadedFile(__DIR__.'/../test.xml', 'test.xml')
        ));

        $fileProcessor = CRUDTestDBSetup::getFileProcessor();
        $fileProcessor->reset();

        $this->dataBook->updateFiles($request, $entityBook, 'book');

        $this->assertFalse($fileProcessor->isCreateFileCalled());
        $this->assertTrue($fileProcessor->isUpdateFileCalled());
        $this->assertFalse($fileProcessor->isDeleteFileCalled());
        $this->assertFalse($fileProcessor->isRenderFileCalled());

        $fileProcessor->reset();
    }

    public function testDeleteFile() {

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $fileProcessor = CRUDTestDBSetup::getFileProcessor();
        $fileProcessor->reset();

        $this->dataBook->deleteFile($entityBook, 'book', 'cover');

        $this->assertFalse($fileProcessor->isCreateFileCalled());
        $this->assertFalse($fileProcessor->isUpdateFileCalled());
        $this->assertTrue($fileProcessor->isDeleteFileCalled());
        $this->assertFalse($fileProcessor->isRenderFileCalled());

        $fileProcessor->reset();
    }

    public function testDeleteFiles() {

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $fileProcessor = CRUDTestDBSetup::getFileProcessor();
        $fileProcessor->reset();

        $this->dataBook->deleteFiles($entityBook, 'book');

        $this->assertFalse($fileProcessor->isCreateFileCalled());
        $this->assertFalse($fileProcessor->isUpdateFileCalled());
        $this->assertTrue($fileProcessor->isDeleteFileCalled());
        $this->assertFalse($fileProcessor->isRenderFileCalled());

        $fileProcessor->reset();
    }

    public function testRenderFile() {

        $entityLibrary = $this->dataLibrary->createEmpty();
        $entityLibrary->set('name', 'lib');
        $this->dataLibrary->create($entityLibrary);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'title');
        $entityBook->set('author', 'author');
        $entityBook->set('pages', 111);
        $entityBook->set('library', $entityLibrary->get('id'));
        $this->dataBook->create($entityBook);

        $fileProcessor = CRUDTestDBSetup::getFileProcessor();
        $fileProcessor->reset();

        $this->dataBook->renderFile($entityBook, 'book', 'cover');

        $this->assertFalse($fileProcessor->isCreateFileCalled());
        $this->assertFalse($fileProcessor->isUpdateFileCalled());
        $this->assertFalse($fileProcessor->isDeleteFileCalled());
        $this->assertTrue($fileProcessor->isRenderFileCalled());

        $fileProcessor->reset();
    }

}
