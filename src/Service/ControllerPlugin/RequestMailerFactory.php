<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\RequestMailer;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class RequestMailerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $repository = $services->get('Omeka\EntityManager')->getRepository(\Omeka\Entity\User::class);
        $adminUser = $repository->findOneByRole('site_admin') ?: $repository->findOneByRole('global_admin');
        return new RequestMailer(
            $services->get('Omeka\Mailer'),
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\Settings'),
            $services->get('Config'),
            $adminUser
        );
    }
}
