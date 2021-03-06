<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\TaskNotFoundInMethodException;
use Psr\Log\LoggerInterface;

class MethodFactory
{

    /**
     * @var MethodInterface[]
     */
    protected $methods = [];

    /**
     * @var \Phabalicious\Configuration\ConfigurationService
     */
    protected $configuration;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $lookupCache = [];

    /**
     * MethodFactory constructor.
     *
     * @param \Phabalicious\Configuration\ConfigurationService $configuration
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(ConfigurationService $configuration, LoggerInterface $logger)
    {
        $this->configuration = $configuration;
        $configuration->setMethodFactory($this);

        $this->logger = $logger;
    }

    /**
     * Add a method.
     *
     * @param \Phabalicious\Method\MethodInterface $method
     */
    public function addMethod(MethodInterface $method)
    {
        $this->methods[$method->getName()] = $method;
    }

    /**
     * Get a method by name.
     *
     * @param string $name
     *
     * @return \Phabalicious\Method\MethodInterface
     * @throws \Phabalicious\Exception\MethodNotFoundException
     */
    public function getMethod(string $name): MethodInterface
    {
        if (isset($this->lookupCache[$name])) {
            return $this->lookupCache[$name];
        }

        foreach ($this->methods as $method) {
            if ($method->supports($name)) {
                $this->lookupCache[$name] = $method;
                return $method;
            }
        }

        throw new MethodNotFoundException('Could not find implementation for method ' . $name);
    }

    /**
     * Run a task.
     *
     * @param string $task_name
     * @param \Phabalicious\Configuration\HostConfig $configuration
     * @param \Phabalicious\Method\TaskContextInterface|NULL $context
     * @param array $nextTasks
     *
     * @return \Phabalicious\Method\TaskContext|\Phabalicious\Method\TaskContextInterface
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    public function runTask(
        string $task_name,
        HostConfig $configuration,
        TaskContextInterface $context = null,
        $nextTasks = []
    ) {
        $context->setResult('runNextTasks', $nextTasks);
        $this->preflight('preflight', $task_name, $configuration, $context);
        $this->runTaskImpl($task_name . 'Prepare', $configuration, $context, false);
        $this->runTaskImpl($task_name, $configuration, $context, true);

        $nextTasks = $context->getResult('runNextTasks', []);
        if (!empty($nextTasks)) {
            foreach ($nextTasks as $next_task_name) {
                $this->runTask($next_task_name, $configuration, $context);
            }
        }
        $this->runTaskImpl($task_name . 'Finished', $configuration, $context, false);
        $this->preflight('postflight', $task_name, $configuration, $context);

        return $context;
    }

    /**
     * Run a task (implementation).
     *
     * @param $task_name
     * @param HostConfig $configuration
     * @param \Phabalicious\Method\TaskContextInterface $context
     * @param $fallback_allowed
     *
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    protected function runTaskImpl(
        string $task_name,
        HostConfig $configuration,
        TaskContextInterface $context,
        $fallback_allowed
    ) {
        $fn_called = false;

        if (!$context->get('quiet')) {
            $this->logger->debug('Running task ' . $task_name . ' on configuration ' . $configuration['configName']);
        }

        foreach ($configuration['needs'] as $method_name) {
            $method = $this->getMethod($method_name);
            if (method_exists($method, $task_name)) {
                $fn_called = true;
                $this->callImpl($method, $task_name, $configuration, $context, true);
            }
        }

        if (!$fn_called && $fallback_allowed) {
            foreach ($configuration['needs'] as $method_name) {
                $this->getMethod($method_name)->fallback($task_name, $configuration, $context);
            }
        }
    }

    /**
     * Call a method (implementation).
     *
     * @param \Phabalicious\Method\MethodInterface $method
     * @param string $task_name
     * @param \Phabalicious\Configuration\HostConfig $configuration
     * @param \Phabalicious\Method\TaskContextInterface $in_context
     * @param bool $optional
     * @throws MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    private function callImpl(
        MethodInterface $method,
        string $task_name,
        HostConfig $configuration,
        TaskContextInterface $in_context,
        bool $optional
    ) {
        $context = clone $in_context;
        $overrides = [];
        foreach ($configuration['needs'] as $method_name) {
            if ($overridden_name = $this->getMethod($method_name)->getOverriddenMethod()) {
                $overrides[$overridden_name] = $method_name;
            }
        }
        $method_name = $method->getName();
        $context->set('currentMethod', $method_name);

        if (isset($overrides[$method_name])) {
            $this->logger->info('Use override ' . $overrides[$method_name] . ' for ' . $method_name);
            $method = $this->getMethod($overrides[$method_name]);
        }
        $this->logger->debug('Call task ' . $task_name . ' on method ' . $method_name);

        if (method_exists($method, $task_name)) {
            $method->{$task_name}($configuration, $context);
            $in_context->mergeResults($context);
        } elseif (!$optional) {
            throw new TaskNotFoundInMethodException(
                'Could not find task `' . $task_name . '` in method `' . $method_name . '`'
            );
        }
    }

    /**
     * Call a task on a specifc method.
     *
     * @param string $method_name
     * @param string $task_name
     * @param \Phabalicious\Configuration\HostConfig $configuration
     * @param \Phabalicious\Method\TaskContextInterface $context
     *
     * @return \Phabalicious\Method\TaskContextInterface
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    public function call(
        string $method_name,
        string $task_name,
        HostConfig $configuration,
        TaskContextInterface $context
    ): TaskContextInterface {
        $method = $this->getMethod($method_name);
        $this->preflight('preflight', $task_name, $configuration, $context);
        $this->callImpl($method, $task_name, $configuration, $context, false);
        $this->preflight('postflight', $task_name, $configuration, $context);

        return $context;
    }

    /**
     * Run preflight/ postflight-step
     *
     * @param string $step_name
     * @param string $task_name
     * @param HostConfig $configuration
     * @param \Phabalicious\Method\TaskContextInterface $context
     *
     * @throws \Phabalicious\Exception\MethodNotFoundException
     */
    private function preflight(
        string $step_name,
        string $task_name,
        HostConfig $configuration,
        TaskContextInterface $context
    ) {
        foreach ($configuration['needs'] as $method_name) {
            $method = $this->getMethod($method_name);
            $method->{$step_name . "Task"}($task_name, $configuration, $context);
        }
    }

    /**
     * Get all registered methods.
     *
     * @return \Phabalicious\Method\MethodInterface[]
     */
    public function all()
    {
        return $this->methods;
    }

    /**
     * Get a subset of methods.
     *
     * @param array $needs
     *
     * @return \Phabalicious\Method\MethodInterface[]
     */
    public function getSubset(array $needs)
    {
        return array_map(function ($elem) {
            return $this->getMethod($elem);
        }, $needs);
    }
}
