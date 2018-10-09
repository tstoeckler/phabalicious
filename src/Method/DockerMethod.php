<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\DockerConfig;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\ValidationFailedException;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;

class DockerMethod extends BaseMethod implements MethodInterface
{

    public function getName(): string
    {
        return 'docker';
    }

    public function supports(string $method_name): bool
    {
        return $method_name === 'docker';
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $config = parent::getDefaultConfig($configuration_service, $host_config); // TODO: Change the autogenerated stub
        $config['executables']['supervisorctl'] = 'supervisorctl';
        $config['executables']['chmod'] = 'chmod';
        if (!empty($host_config['sshTunnel']) &&
            !empty($host_config['docker']['name']) &&
            empty($host_config['sshTunnel']['destHostFromDockerContainer']) &&
            empty($host_config['sshTunnel']['destHost'])
        ) {
            $config['sshTunnel']['destHostFromDockerContainer'] = $host_config['docker']['name'];
        }
        return $config;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub
        $validation = new ValidationService($config, $errors, 'host');
        $validation->isArray('docker', 'docker configuration needs to be an array');
        if (!$errors->hasErrors()) {
            $validation = new ValidationService($config['docker'], $errors, 'host.docker');
            $validation->hasKey('name', 'name of the docker-container to inspect');
            $validation->hasKey('configuration', 'name of the docker-configuration to use');
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @return DockerConfig
     * @throws ValidationFailedException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     */
    public function getDockerConfig(HostConfig $host_config, TaskContextInterface $context)
    {
        return $context->getConfigurationService()->getDockerConfig($host_config['docker']['configuration']);
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws MethodNotFoundException
     * @throws ValidationFailedException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     * @throws \Phabalicious\Exception\MissingScriptCallbackImplementation
     */
    public function docker(HostConfig $host_config, TaskContextInterface $context)
    {
        $task = $context->get('docker_task');

        $this->runTaskImpl($host_config, $context, $task . 'Prepare', true);
        $this->runTaskImpl($host_config, $context, $task, false);
        $this->runTaskImpl($host_config, $context, $task . 'Finished', true);
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @param $task
     * @param $silent
     * @throws MethodNotFoundException
     * @throws ValidationFailedException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     * @throws \Phabalicious\Exception\MissingScriptCallbackImplementation
     */
    private function runTaskImpl(HostConfig $host_config, TaskContextInterface $context, $task, $silent)
    {
        $this->logger->info('Running docker-task `' . $task . '` on `' . $host_config['configName']);

        if (method_exists($this, $task)) {
            $this->{$task}($host_config, $context);
            return;
        }

        /** @var DockerConfig $docker_config */
        $docker_config = $this->getDockerConfig($host_config, $context);
        $tasks = $docker_config['tasks'];

        if ($silent && empty($tasks[$task])) {
            return;
        }
        if (empty($tasks[$task])) {
            throw new MethodNotFoundException('Missing task `' . $task . '`');
        }

        $script = $tasks[$task];
        $environment = $docker_config->get('environment', []);
        $callbacks = [];

        /** @var ScriptMethod $method */
        $method = $context->getConfigurationService()->getMethodFactory()->getMethod('script');
        $context->set('scriptData', $script);
        $context->set('variables', [
            'dockerHost' => $docker_config->raw(),
        ]);
        $context->set('environment', $environment);
        $context->set('callbacks', $callbacks);
        $context->set('rootFolder', $docker_config['rootFolder']);
        $context->setShell($docker_config->shell());
        $docker_config->shell()->setOutput($context->getOutput());

        $method->runScript($host_config, $context);
    }

    public function getInternalTasks()
    {
        return [
            'waitForServices',
            'copySSHKeys',
            'startRemoteAccess'
        ];
    }

    /**
     * @param HostConfig $hostconfig
     * @param TaskContextInterface $context
     */
    public function waitForServices(HostConfig $hostconfig, TaskContextInterface $context)
    {
        if ($hostconfig['executables']['supervisorctl'] === false) {
            return;
        }
        $max_tries = 10;
        $tries = 0;
        $shell = $hostconfig->shell();

        while ($tries < $max_tries) {
            $result = $shell->run('#!supervisorctl status', true);

            $count_running = 0;
            $count_services = 0;
            foreach ($result->getOutput() as $line) {
                if (trim($line) != '') {
                    $count_services++;
                    if (strpos($line, 'RUNNING')) {
                        $count_running++;
                    }
                }
            }
            if ($result->getExitCode() !== 0) {
                throw new \RuntimeException('Error running supervisorctl, check the logs');
            }
            if ($result->getExitCode() == 0 && ($count_running == $count_services)) {
                $this->logger->notice('Services up and running!');
                return;
            }
            $tries++;
            $this->logger->notice('Waiting for 5 secs and try again ...');
            sleep(5);
        }
        $this->logger->error('Supervisord not coming up at all!');
    }

    private function copySSHKeys(HostConfig $hostconfig, TaskContextInterface $context)
    {
        $files = [];
        $temp_files = [];

        if ($file = $context->getConfigurationService()->getSetting('dockerAuthorizedKeyFile')) {
            $files['~/.ssh/authorized_keys'] = [
                'source' => $file,
                'permissions' => '600',
            ];
        }
        if ($file = $context->getConfigurationService()->getSetting('dockerKeyFile')) {
            $files['~/.ssh/id_rsa'] = [
                'source' => $file,
                'permissions' => '600',
            ];
            $files['~/.ssh/id_rsa.pub'] = [
                'source' => $file . '.pub',
                'permissions' => '644',
            ];
        }

        if ($file = $context->getConfigurationService()->getSetting('dockerKnownHostsFile')) {
            $files['~/.ssh/known_hosts'] = [
                'source' => $file,
                'permissions' => '600',
            ];
        }
        if (count($files) > 0) {
            $shell = $hostconfig->shell();
            foreach ($files as $dest => $data) {
                if ((substr($data['source'], 0, 7) == 'http://') ||
                    (substr($data['source'], 0, 8) == 'https://')) {
                    $content = $context->getConfigurationService()->readHttpResource($data['source']);
                    $temp_file = tempnam("/tmp", "phabalicious");
                    file_put_contents($temp_file, $content);
                    $data['source'] = $temp_file;
                    $temp_files[] = $temp_file;
                }
                $shell->putFile($data['source'], $dest, $context);
                $shell->run('#!chmod ' . $data['permissions'] . ' ' . $dest);
            }
            $shell->run('#!chmod 700 ~/.ssh');
        }

        foreach ($temp_files as $temp_file) {
            @unlink($temp_file);
        }
    }

    public function isContainerRunning(HostConfig $docker_config, $container_name)
    {
        $shell = $docker_config->shell();
        $result = $shell->run(sprintf(
            'docker inspect -f {{.State.Running}} %s',
            $container_name
        ), true);

        $output = $result->getOutput();
        $last_line = array_pop($output);
        if (strtolower(trim($last_line)) !== 'true') {
            return false;
        }

        return true;
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @return bool|string
     * @throws ValidationFailedException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     */
    public function getIpAddress(HostConfig $host_config, TaskContextInterface $context)
    {
        $docker_config = $this->getDockerConfig($host_config, $context);
        $shell = $docker_config->shell();
        $container_name = $host_config['docker']['name'];

        if (!$this->isContainerRunning($docker_config, $container_name)) {
            return false;
        }

        $result = $shell->run(sprintf(
            'docker inspect --format "{{range .NetworkSettings.Networks}}{{.IPAddress}}\n{{end}}" %s',
            $container_name
        ), true);

        if ($result->getExitCode() === 0) {
            return str_replace(array("\\r", "\\n"), '', $result->getOutput()[0]);
        }
        return false;
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws ValidationFailedException
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     */
    public function startRemoteAccess(HostConfig $host_config, TaskContextInterface $context)
    {
        $docker_config = $this->getDockerConfig($host_config, $context);
        $context->setResult('ip', $this->getIpAddress($host_config, $context));
        if (is_a($docker_config->shell(), 'SshShellProvider')) {
            $context->setResult('config', $this->getDockerConfig($host_config, $context));
        }
    }

}