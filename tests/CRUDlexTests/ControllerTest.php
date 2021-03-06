<?php

/*
 * This file is part of the CRUDlex package.
 *
 * (c) Philip Lehmann-Böhm <philip@philiplb.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CRUDlexTests\Silex;

use CRUDlex\Controller;
use CRUDlex\Entity;
use CRUDlex\EntityDefinitionFactory;
use CRUDlex\EntityDefinitionValidator;
use CRUDlex\Service;
use CRUDlex\MySQLDataFactory;
use CRUDlexTestEnv\TestDBSetup;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\NullAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Eloquent\Phony\Phpunit\Phony;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Translator;
use Twig_Environment;
use Twig_Loader_Filesystem;

class ControllerTest extends TestCase
{

    private $db;

    private $dataBook;

    private $dataLibrary;

    private $session;

    private $filesystemHandle;

    protected function setUp()
    {
        $config = new \Doctrine\DBAL\Configuration();
        $this->db = \Doctrine\DBAL\DriverManager::getConnection(TestDBSetup::getDBConfig(), $config);
        TestDBSetup::createDB($this->db);
    }

    protected function createController()
    {

        $translator = new Translator('en');
        $loader = new Twig_Loader_Filesystem();
        $loader->addPath(__DIR__.'/../../src/views/', 'crud');
        $twig = new Twig_Environment($loader);
        $foo = function() {
            return 'foo';
        };
        $this->session = new Session(new MockArraySessionStorage());
        $twig->addFunction(new \Twig_SimpleFunction('crudlex_getCurrentUri', $foo));
        $twig->addFunction(new \Twig_SimpleFunction('crudlex_sessionGet', $foo));
        $session = $this->session;
        $twig->addFunction(new \Twig_SimpleFunction('crudlex_sessionFlashBagGet', function($type) use ($session) {
            return $session->getFlashBag()->get($type);
        }));
        $twig->addFilter(new \Twig_SimpleFilter('trans', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_languageName', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_formatDate', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_formatDateTime', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_basename', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_float', $foo));
        $twig->addFilter(new \Twig_SimpleFilter('crudlex_arrayColumn', $foo));

        $crudFile = __DIR__.'/../crud.yml';
        $urlGeneratorMock = Phony::mock('Symfony\Component\\Routing\\Generator\\UrlGeneratorInterface');
        $urlGeneratorMock->generate->returns('redirecting');
        $dataFactory = new MySQLDataFactory($this->db);

        $this->filesystemHandle = Phony::partialMock('\\League\\Flysystem\\Filesystem', [new Local(__DIR__.'/../tmp')]);
        $filesystemMock = $this->filesystemHandle->get();

        $validator = new EntityDefinitionValidator();
        $entityDefinitionFactory = new EntityDefinitionFactory();
        $service = new Service($crudFile, null, $urlGeneratorMock->get(), $translator, $dataFactory, $entityDefinitionFactory, $filesystemMock, $validator);
        $service->setTemplate('layout', '@crud/layout.twig');
        $this->dataBook = $service->getData('book');
        $this->dataLibrary = $service->getData('library');
        return new Controller($service, $filesystemMock, $twig, $this->session, $translator);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetLocaleAndCheckEntity()
    {
        $request = new Request(['entity' => 'library']);
        $controller = $this->createController();
        $response = $controller->setLocaleAndCheckEntity($request);
        $this->assertNull($response);
        $request = new Request(['entity' => 'foo']);
        $response = $controller->setLocaleAndCheckEntity($request);
        $this->assertNotNull($response);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreate()
    {
        $controller = $this->createController();

        $request = new Request();
        $response = $controller->create($request, 'book');
        $this->assertRegexp('/Submit/', $response);
        $this->assertRegexp('/Author/', $response);
        $this->assertRegexp('/Pages/', $response);

        $request = new Request();
        $request->setMethod('POST');
        $response = $controller->create($request, 'book');
        $this->assertRegexp('/Could not create, see the red marked fields./', $response);
        $this->assertRegexp('/has-error/', $response);

        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'lib a');
        $this->dataLibrary->create($library);

        $file = __DIR__.'/../test1.xml';

        $request = new Request([], [
            'title' => 'title',
            'author' => 'author',
            'pages' => 111,
            'price' => 3.99,
            'library' => $library->get('id'),
            'secondLibrary' => '' // This might occure if the user leaves the form field empty
        ],[], [], [
            'cover' => new UploadedFile($file, 'test1.xml', 'application/xml', filesize($file), null, true)
        ]);
        $request->setMethod('POST');
        $response = $controller->create($request, 'book');
        $this->assertTrue($response->isRedirect('redirecting'));
        $flash = $this->session->getFlashBag()->get('success');
        $this->assertRegExp('/Book created with id /', $flash[0]);

        $books = $this->dataBook->listEntries();
        $this->assertCount(1, $books);

        $this->filesystemHandle->writeStream->once()->called();
        $this->filesystemHandle->readStream->never()->called();

        // Canceling events
        $before = function(Entity $entity) {
            return false;
        };
        $this->dataBook->getEvents()->push('before', 'create', $before);
        $response = $controller->create($request, 'book');
        $this->assertRegExp('/Could not create\./', $response);
        $this->dataBook->getEvents()->pop('before', 'create');

        $this->dataBook->getEvents()->push('before', 'createFiles', $before);
        $response = $controller->create($request, 'book');
        $this->assertRegExp('/Could not create\./', $response);
        $this->dataBook->getEvents()->pop('before', 'createFiles');

        // Prefilled form
        $request = new Request(['author' => 'myAuthor']);
        $response = $controller->create($request, 'book');
        $this->assertRegExp('/value="myAuthor"/', $response);
    }

    public function testShowList()
    {
        $controller = $this->createController();

        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'lib a');
        $this->dataLibrary->create($library);

        $library2 = $this->dataLibrary->createEmpty();
        $library2->set('name', 'lib b');
        $this->dataLibrary->create($library2);

        $entityBook1 = $this->dataBook->createEmpty();
        $entityBook1->set('title', 'titleA');
        $entityBook1->set('author', 'author');
        $entityBook1->set('pages', 111);
        $entityBook1->set('price', 3.99);
        $entityBook1->set('library', $library->get('id'));
        $this->dataBook->create($entityBook1);
        $entityBook1Id = $entityBook1->get('id');

        $entityBook2 = $this->dataBook->createEmpty();
        $entityBook2->set('title', 'titleB');
        $entityBook2->set('author', 'author');
        $entityBook2->set('pages', 111);
        $entityBook2->set('price', 3.99);
        $entityBook2->set('library', $library->get('id'));
        $this->dataBook->create($entityBook2);

        $library->set('libraryBook', [['id' => $entityBook1Id]]);
        $this->dataLibrary->update($library);

        $request = new Request();
        $response = $controller->showList($request, 'book');
        $this->assertRegExp('/lib a/', $response);
        $this->assertRegExp('/titleA/', $response);
        $this->assertRegExp('/titleB/', $response);

        for ($i = 0; $i < 8; ++$i) {
            $entityBookA = $this->dataBook->createEmpty();
            $entityBookA->set('title', 'titleB'.$i);
            $entityBookA->set('author', 'author'.$i);
            $entityBookA->set('pages', 111);
            $entityBookA->set('price', 3.99);
            $entityBookA->set('library', $i % 2 == 0 ? $library->get('id') : $library2->get('id'));
            $this->dataBook->create($entityBookA);
        }

        // Pagination
        $this->dataBook->getDefinition()->setPageSize(5);
        $response = $controller->showList($request, 'book');
        $this->assertRegExp('/titleA/', $response);
        $this->assertRegExp('/\>1\</', $response);
        $this->assertRegExp('/\>2\</', $response);
        $this->assertSame(strpos('>3<', $response), false);

        $request = new Request(['crudPage' => '1']);
        $response = $controller->showList($request, 'book');
        $this->assertRegExp('/titleB3/', $response);

        // Filter
        $request = new Request(['crudFiltertitle' => 'titleB']);
        $response = $controller->showList($request, 'book');
        $this->assertRegExp('/titleB/', $response);
        $this->assertRegExp('/titleB0/', $response);
        $this->assertRegExp('/titleB1/', $response);
        $this->assertRegExp('/titleB2/', $response);
        $this->assertRegExp('/titleB3/', $response);
        $this->assertNotRegExp('/titleA/', $response);

        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'lib b1');
        $library->set('isOpenOnSundays', true);
        $this->dataLibrary->create($library);
        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'lib b2');
        $library->set('isOpenOnSundays', true);
        $this->dataLibrary->create($library);

        $request = new Request(['crudFilterisOpenOnSundays' => 'true']);
        $response = $controller->showList($request, 'library');
        $this->assertRegExp('/lib b1/', $response);
        $this->assertRegExp('/lib b2/', $response);
        $this->assertNotRegExp('/lib a/', $response);

        $request = new Request(['crudFilterlibraryBook' => [$entityBook1Id]]);
        $response = $controller->showList($request, 'library');
        $this->assertRegExp('/lib a/', $response);
        $this->assertNotRegExp('/lib b1/', $response);
        $this->assertNotRegExp('/lib b2/', $response);


        $request = new Request(['crudFilterlibrary' => $library2->get('id')]);
        $response = $controller->showList($request, 'book');
        $this->assertRegExp('/titleB1/', $response);
        $this->assertRegExp('/titleB3/', $response);
        $this->assertRegExp('/titleB5/', $response);
        $this->assertRegExp('/titleB7/', $response);
        $this->assertNotRegExp('/titleB0/', $response);
        $this->assertNotRegExp('/titleB2/', $response);
        $this->assertNotRegExp('/titleB4/', $response);
        $this->assertNotRegExp('/titleB6/', $response);
        $this->assertNotRegExp('/titleB"/', $response);
        $this->assertNotRegExp('/titleA/', $response);
    }

    public function testShow()
    {
        $controller = $this->createController();

        $library = $this->dataLibrary->createEmpty();
        $library->set('name', 'lib a');
        $this->dataLibrary->create($library);

        $entityBook = $this->dataBook->createEmpty();
        $entityBook->set('title', 'titleA');
        $entityBook->set('author', 'authorA');
        $entityBook->set('pages', 111);
        $entityBook->set('release', "2014-08-31");
        $entityBook->set('library', $library->get('id'));
        $this->dataBook->create($entityBook);

        $response = $controller->show('book', '666');
        $this->assertTrue($response->isNotFound());
        $this->assertRegExp('/Instance not found/', $response->getContent());

        $response = $controller->show('book', $entityBook->get('id'));
        $this->assertRegExp('/lib a/', $response);
        $this->assertRegExp('/titleA/', $response);
        $this->assertRegExp('/authorA/', $response);
        $this->assertRegExp('/111/', $response);


        $response = $controller->show('library', $library->get('id'));
        $this->assertRegExp('/titleA/', $response);
    }

}
