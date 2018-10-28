<?php

namespace Phabalicious\Command;

use Phabalicious\Exception\EarlyTaskExitException;
use Phabalicious\Method\TaskContext;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListBackupsCommand extends BaseCommand
{

    protected function configure()
    {
        $this
            ->setName('list:backups')
            ->setDescription('List all backups')
            ->setHelp('Displays a list of all backups for a givebn configuration');
        $this->addArgument(
            'what',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Filter list of backups by type, if none given, all types get displayed',
            ['files', 'db']
        );

        $this->setAliases(['listBackups']);
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Phabalicious\Exception\BlueprintTemplateNotFoundException
     * @throws \Phabalicious\Exception\FabfileNotFoundException
     * @throws \Phabalicious\Exception\FabfileNotReadableException
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     * @throws \Phabalicious\Exception\ShellProviderNotFoundException
     * @throws \Phabalicious\Exception\TaskNotFoundInMethodException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if ($result = parent::execute($input, $output)) {
            return $result;
        }

        $context = new TaskContext($this, $input, $output);
        $what = array_map(function ($elem) {
            return trim(strtolower($elem));
        }, $input->getArgument('what'));


        $this->getMethods()->runTask('listBackups', $this->getHostConfig(), $context);

        $files = $context->getResult('files');
        $files = array_filter($files, function ($file) use ($what) {
            return in_array($file['type'], $what);
        });
        uasort($files, function ($a, $b) {
            if ($a['date'] == $b['date']) {
                return strcmp($b['time'], $a['time']);
            }
            return strcmp($b['date'], $a['date']);
        });
        $table = new Table($output);
        $table->setHeaders(['Date', 'Time', 'Hash', 'File'])
            ->setRows(array_map(function ($file) {
                return [
                    $file['date'],
                    $file['time'],
                    $file['hash'],
                    $file['file']
                ];
            }, $files));
        $table->render();

        return $context->getResult('exitCode', 0);
    }

}