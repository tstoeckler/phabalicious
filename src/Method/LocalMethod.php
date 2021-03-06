<?php

namespace Phabalicious\Method;


use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\ShellProvider\LocalShellProvider;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;

class LocalMethod extends BaseMethod implements MethodInterface
{

    public function getName(): string
    {
        return 'local';
    }

    public function supports(string $method_name): bool
    {
        return $method_name == 'local';
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $result = [
            'rootFolder' => $configuration_service->getFabfilePath(),
            'shellProvider' => LocalShellProvider::PROVIDER_NAME,
        ];

        if (!empty($host_config['runLocally'])) {
            $result['needs'] = ['local'];
        }

        return $result;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        $validation = new ValidationService($config, $errors, 'host-config');
        $validation->deprecate([
            'runLocally' => 'Please add `local` to your `needs`!'
        ]);
    }

    public function createShellProvider(array $host_config)
    {
        return ShellProviderFactory::create(LocalShellProvider::PROVIDER_NAME, $this->logger);
    }

}