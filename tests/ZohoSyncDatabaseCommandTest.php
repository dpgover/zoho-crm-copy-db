<?php

namespace Wabel\Zoho\CRM\Copy;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
//use TestNamespace\ContactZohoDao;
use Wabel\Zoho\CRM\AbstractZohoDao;
use Wabel\Zoho\CRM\ZohoClient;
use Prophecy\Argument;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Wabel\Zoho\CRM\Service\EntitiesGeneratorService;
use Psr\Log\NullLogger;

class ZohoSyncDatabaseCommandTest extends \PHPUnit_Framework_TestCase
{
    private function getZohoClient()
    {
        return new ZohoClient($GLOBALS['auth_token']);
    }
    
    public function getEntitiesGeneratorService()
    {
        return new EntitiesGeneratorService($this->getZohoClient(), new NullLogger());
    }

    /**
     * Create Module and load them to use after.
     * @param EntitiesGeneratorService $generator
     */
    private function loadFilesZohoDaos(EntitiesGeneratorService $generator)
    {
        // Create Module and load them to use after.
        $generator->generateAll(__DIR__.'/generated/', 'TestNamespace');
        foreach (scandir(__DIR__.'/generated/') as $filename) {
            $path = __DIR__.'/generated/'.$filename;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }

    /**
     * @depends Wabel\Zoho\CRM\Copy\ZohoDatabaseModelSyncTest::testModelSync
     */
    public function testExecute()
    {
        $generator = $this->getEntitiesGeneratorService();
        $this->loadFilesZohoDaos($generator);

        $syncModel = $this->prophesize(ZohoDatabaseModelSync::class);
        $syncModel->setLogger(Argument::type(ConsoleLogger::class))->shouldBeCalled();
        $syncModel->synchronizeDbModel(Argument::type(AbstractZohoDao::class), true, false)->shouldBeCalled();

        $dbCopier = $this->prophesize(ZohoDatabaseCopier::class);
        $dbCopier->setLogger(Argument::type(ConsoleLogger::class))->shouldBeCalled();
        $dbCopier->fetchFromZoho(Argument::type(AbstractZohoDao::class), true, true)->shouldBeCalled();

        $pusher = $this->prophesize(ZohoDatabasePusher::class);
        $pusher->setLogger(Argument::type(ConsoleLogger::class))->shouldBeCalled();
        $pusher->pushToZoho(Argument::type(AbstractZohoDao::class))->shouldBeCalled();
        
        $application = new Application();
        $application->add(new ZohoSyncDatabaseCommand($syncModel->reveal(), $dbCopier->reveal(), $pusher->reveal(),
            $generator, __DIR__.'/generated/','TestNamespace'));
        
        $command = $application->find('zoho:sync');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array('command' => $command->getName()));
    }

    /**
     * @depends Wabel\Zoho\CRM\Copy\ZohoDatabaseModelSyncTest::testModelSync
     */
    public function testException()
    {
        $generator = $this->getEntitiesGeneratorService();
        $this->loadFilesZohoDaos($generator);
        $syncModel = $this->prophesize(ZohoDatabaseModelSync::class);
        $dbCopier = $this->prophesize(ZohoDatabaseCopier::class);
        $pusher = $this->prophesize(ZohoDatabasePusher::class);

        $application = new Application();
        $application->add(new ZohoSyncDatabaseCommand($syncModel->reveal(), $dbCopier->reveal(), $pusher->reveal(),
            $generator, __DIR__.'/generated/','TestNamespace'));

        $command = $application->find('zoho:sync');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array('command' => $command->getName(), '--fetch-only' => true, '--push-only' => true));

        $this->assertRegExp('/Options fetch-only and push-only are mutually exclusive/', $commandTester->getDisplay());
    }
}
