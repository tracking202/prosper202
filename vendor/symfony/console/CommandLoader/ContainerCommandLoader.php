<?php
declare(strict_types=1);
namespace Symfony\Component\Console\CommandLoader;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Loads commands from a PSR-11 container.
 *
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class ContainerCommandLoader implements CommandLoaderInterface
{
    private $container;
    private $commandMap;

    /**
     * @param ContainerInterface $container  A container from which to load command services
     * @param array              $commandMap An array with command names as keys and service ids as values
     */
    public function __construct(ContainerInterface $container, array $commandMap)
    {
        $this->container = $container;
        $this->commandMap = $commandMap;
    }

    /**
     * {@inheritdoc}
     */
    public function get($name)
    {
        if (!$this->has($name)) {
            throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        }

        return $this->container->get($this->commandMap[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function has($name)
    {
        return isset($this->commandMap[$name]) && $this->container->has($this->commandMap[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getNames()
    {
        return array_keys($this->commandMap);
    }
}
