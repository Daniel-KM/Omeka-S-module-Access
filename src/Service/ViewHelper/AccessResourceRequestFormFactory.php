<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\AccessResourceRequestForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessResourceRequestFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new AccessResourceRequestForm(
            $plugins->get('api'),
            $plugins->get('accessLevel'),
            $services->get('FormElementManager')
        );
    }
}
