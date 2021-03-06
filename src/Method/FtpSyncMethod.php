<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\EarlyTaskExitException;
use Phabalicious\ShellProvider\ShellProviderInterface;
use Phabalicious\Utilities\AppDefaultStages;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use Symfony\Component\Console\Helper\QuestionHelper;

class FtpSyncMethod extends BaseMethod implements MethodInterface
{

    const DEFAULT_FILE_SOURCES = [
        'public' => 'filesFolder',
        'private' => 'privateFilesFolder'
    ];

    public function getName(): string
    {
        return 'ftp-sync';
    }

    public function supports(string $method_name): bool
    {
        return $method_name === $this->getName();
    }

    public function getGlobalSettings(): array
    {
        $defaults = parent::getGlobalSettings();
        $defaults['excludeFiles']['ftpSync'] = [
            '.git/',
            'node_modules/',
            'fabfile.yaml'
        ];

        return $defaults;
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $return = parent::getDefaultConfig($configuration_service, $host_config);
        $return['tmpFolder'] = '/tmp';
        $return['executables'] = [
            'lftp' => 'lftp',
        ];

        $return['deployMethod'] = 'ftp-sync';
        $return['ftp'] = [
            'port' => 21,
            'lftpOptions' => [
                '--verbose=1',
                '--no-perms',
                '--no-symlinks',
                '-P 20',
            ]
        ];

        return $return;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors);
        if (in_array('drush', $config['needs'])) {
            $errors->addError('needs', 'The method `ftp-sync` is incompatible with the `drush`-method!');
        }
        if ($config['deployMethod'] !== 'ftp-sync') {
            $errors->addError('deployMethod', 'deployMethod must be `ftp-sync`!');
        }
        $service = new ValidationService($config, $errors, 'Host-config '. $config['configName']);
        $service->isArray('ftp', 'Please provide ftp-credentials!');
        if (!empty($config['ftp'])) {
            $service = new ValidationService($config['ftp'], $errors, 'host-config.ftp '. $config['configName']);
            $service->hasKeys([
                'user' => 'the ftp user-name',
                'host' => 'the ftp host to connect to',
                'port' => 'the port to connect to',
                'rootFolder' => 'the rootfolder of your app on the remote file-system',
            ]);
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @param ShellProviderInterface $shell
     * @param string $install_dir
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws \Phabalicious\Exception\MissingScriptCallbackImplementation
     * @throws \Phabalicious\Exception\TaskNotFoundInMethodException
     */
    protected function createAppCode(
        HostConfig $host_config,
        TaskContextInterface $context,
        ShellProviderInterface $shell,
        string $install_dir
    ) {
        // First, create an app in a temporary-folder.
        $stages = $context->getConfigurationService()->getSetting(
            'appStages.createCode',
            AppDefaultStages::CREATE_CODE
        );

        $cloned_host_config = clone $host_config;
        $keys = ['rootFolder', 'composerRootFolder', 'gitRootFolder'];
        foreach ($keys as $key) {
            $cloned_host_config[$key] = $install_dir;
        }
        $shell->cd($cloned_host_config['tmpFolder']);
        $context->set('outerShell', $shell);

        AppDefaultStages::executeStages(
            $context->getConfigurationService()->getMethodFactory(),
            $cloned_host_config,
            $stages,
            'appCreate',
            $context,
            'Creating code'
        );

        // Run deploy scripts
        /** @var ScriptMethod $script_method */
        $script_method = $context->getConfigurationService()->getMethodFactory()->getMethod('script');
        $context->set('variables', [
            'installFolder' => $install_dir
        ]);
        $context->set('rootFolder', $install_dir);
        $script_method->runTaskSpecificScripts($cloned_host_config, 'deploy', $context);

        $context->setResult('skipResetStep', true);
    }
    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws \Phabalicious\Exception\MissingScriptCallbackImplementation
     * @throws \Phabalicious\Exception\TaskNotFoundInMethodException
     */
    public function deploy(HostConfig $host_config, TaskContextInterface $context)
    {
        if ($host_config['deployMethod'] !== 'ftp-sync') {
            return;
        }

        if (empty($host_config['ftp']['password'])) {
            $ftp = $host_config['ftp'];
            $ftp['password'] = $context->getPasswordManager()->getPasswordFor($ftp['host'], $ftp['port'], $ftp['user']);
            $host_config['ftp'] = $ftp;
        }

        $install_dir = $host_config['tmpFolder'] . '/' . $host_config['configName'] . '-' . time();
        $context->set('installDir', $install_dir);

        $shell = $this->getShell($host_config, $context);
        $this->createAppCode($host_config, $context, $shell, $install_dir);

        $exclude = $context->getConfigurationService()->getSetting('excludeFiles.ftpSync', []);
        $options = implode(' ', $host_config['ftp']['lftpOptions']);
        if (count($exclude)) {
            $options .= ' --exclude ' . implode(' --exclude ', $exclude);
        }

        // Now we can sync the files via FTP.
        $command_file = $host_config['tmpFolder'] . '/lftp_commands_' . time() . '.x';
        $shell->run(sprintf('touch %s', $command_file));
        $shell->run(sprintf(
            'echo "open -u %s,%s -p%s %s" >> %s',
            $host_config['ftp']['user'],
            $host_config['ftp']['password'],
            $host_config['ftp']['port'],
            $host_config['ftp']['host'],
            $command_file
        ));
        $shell->run(sprintf(
            'echo "mirror %s -c -e -R  %s %s" >> %s',
            $options,
            $install_dir,
            $host_config['ftp']['rootFolder'],
            $command_file
        ));

        $shell->run(sprintf('echo "exit" >> %s', $command_file));

        $shell->run(sprintf('#!lftp -f %s', $command_file));

        // Cleanup.
        $shell->run(sprintf('rm %s', $command_file));
        $shell->run(sprintf('rm -rf %s', $install_dir));

        // Do not run any next tasks.
        $context->setResult('runNextTasks', []);
    }
}
