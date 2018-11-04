<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\EarlyTaskExitException;
use Phabalicious\Utilities\Utilities;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;

class GitMethod extends BaseMethod implements MethodInterface
{

    public function getName(): string
    {
        return 'git';
    }

    public function supports(string $method_name): bool
    {
        return $method_name === 'git';
    }

    public function getGlobalSettings(): array
    {
        return [
            'gitOptions' =>  [
                'pull' => [
                    '--no-edit',
                    '--rebase'
                ],
            ],
            'executables' => [
                'git' => 'git',
            ],
        ];
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        return [
            'branch' => 'develop',
            'gitRootFolder' => $host_config['rootFolder'],
            'ignoreSubmodules' => false,
            'gitOptions' => $configuration_service->getSetting('gitOptions', []),
        ];
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        $validation = new ValidationService($config, $errors, 'host-config');
        $validation->hasKey('gitRootFolder', 'gitRootFolder should point to your gits root folder.');
        $validation->hasKey('branch', 'git needs a branch-name so it can run deployments.');
    }

    public function getVersion(HostConfig $host_config, TaskContextInterface $context)
    {
        $host_config->shell()->cd($host_config['gitRootFolder']);
        $result = $host_config->shell()->run('#!git describe --always --tags', true);
        return $result->succeeded() ? str_replace('/', '-', $result->getOutput()[0]) : '';
    }

    public function getCommitHash(HostConfig $host_config, TaskContextInterface $context)
    {
        $host_config->shell()->cd($host_config['gitRootFolder']);
        $result = $host_config->shell()->run('#!git rev-parse HEAD', true);
        return $result->getOutput()[0];
    }

    public function isWorkingcopyClean(HostConfig $host_config, TaskContextInterface $context)
    {
        $host_config->shell()->cd($host_config['gitRootFolder']);
        $result = $host_config->shell()->run('#!git diff --exit-code --quiet', true);
        return $result->succeeded();
    }

    public function version(HostConfig $host_config, TaskContextInterface $context)
    {
        $context->set('version', $this->getVersion($host_config, $context));
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws EarlyTaskExitException
     */
    public function deploy(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $host_config->shell();
        $shell->cd($host_config['gitRootFolder']);
        if (!$this->isWorkingcopyClean($host_config, $context)) {
            $this->logger->error('Working copy is not clean, aborting');
            $result = $shell->run('#!git status');
            throw new EarlyTaskExitException();
        }

        $branch = $context->get('branch', $host_config['branch']);

        $shell->run('#!git fetch -q origin');
        $shell->run('#!git checkout ' . $branch);
        $shell->run('#!git fetch --tags');

        $git_options = implode(' ', Utilities::getProperty($host_config, 'gitOptions.pull', []));
        $shell->run('#!git pull -q ' . $git_options . ' origin ' . $branch);

        if (empty($host_config['ignoreSubmodules'])) {
            $shell->run('#!git submodule init');
            $shell->run('#!git submodule update');
            $shell->run('#!git submodule sync');
        }
    }


    public function backupPrepare(HostConfig $host_config, TaskContextInterface $context)
    {
        $hash = $this->getVersion($host_config, $context);
        if ($hash) {
            $basename = $context->getResult('basename', []);
            array_splice($basename, 1, 0, $hash);
            $context->setResult('basename', $basename);
        }
    }

    public function getMetaInformation(HostConfig $host_config, TaskContextInterface $context)
    {
        $context->addResult('meta', [
            new MetaInformation('Version', $this->getVersion($host_config, $context), true),
            new MetaInformation('Commit', $this->getCommitHash($host_config, $context), true),
        ]);
    }

}