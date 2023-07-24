<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\AccessRequest;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessRequestFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $helpers = $services->get('ViewHelperManager');
        return new AccessRequest(
            $helpers->get('isAccessRequestable'),
            $services->get('FormElementManager')
        );
    }
}
