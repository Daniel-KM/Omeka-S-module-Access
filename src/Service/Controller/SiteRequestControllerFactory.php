<?php declare(strict_types=1);

namespace Access\Service\Controller;

use Access\Controller\Site\RequestController;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SiteRequestControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new RequestController(
            $services->get('Access\SpamChecker')
        );
    }
}
