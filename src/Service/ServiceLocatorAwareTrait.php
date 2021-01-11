<?php declare(strict_types=1);

namespace AccessResource\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;

trait ServiceLocatorAwareTrait
{
    /**
     * @var  ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Get the service locator.
     */
    public function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->serviceLocator;
    }

    /**
     * Set the service locator.
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator): self
    {
        $this->serviceLocator = $serviceLocator;
        return $this;
    }
}
