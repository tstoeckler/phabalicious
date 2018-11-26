<?php
/**
 * Created by PhpStorm.
 * User: stephan
 * Date: 05.10.18
 * Time: 12:27
 */

namespace Phabalicious\Tests;

use Phabalicious\Command\GetPropertyCommand;
use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Method\MethodFactory;
use Phabalicious\Method\ScriptMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class GetPropertyCommandTest extends TestCase
{
    /** @var Application */
    protected $application;

    public function setup()
    {
        $this->application = new Application();
        $this->application->setVersion('3.0.0');
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();

        $configuration = new ConfigurationService($this->application, $logger);
        $method_factory = new MethodFactory($configuration, $logger);
        $method_factory->addMethod(new ScriptMethod($logger));

        $configuration->readConfiguration(getcwd() . '/assets/getproperty-tests/fabfile.yaml');

        $this->application->add(new GetPropertyCommand($configuration, $method_factory));
    }

    public function testGetProperty()
    {
        $command = $this->application->find('getProperty');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
            'property' => 'host',
            '--config' => 'testA'
        ));

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertContains('getproperty-test-host.a', $output);
    }

    public function testGetPropertyB()
    {
        $command = $this->application->find('getProperty');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
            'property' => 'host',
            '--config' => 'testB'

        ));

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertContains('getproperty-test-host.b', $output);
    }

    public function testGetPropertyFromBluePrint()
    {
        $command = $this->application->find('getProperty');
        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
            'property' => 'host',
            '--config' => 'testBlueprint',
            '--blueprint' => 'value-from-blueprint'

        ));

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertContains('getproperty-test-valuefromblueprint', $output);
    }

}
