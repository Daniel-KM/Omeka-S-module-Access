<?php declare(strict_types=1);

namespace AccessResource;

// Access mode may be "global", "ip" or "individual".
use const AccessResource\ACCESS_MODE;

if (ACCESS_MODE === 'individual') {
    return include __DIR__ . '/module.config.individual.php';
}

return [
    'entity_manager' => [
        'filters' => [
            // Override Omeka core resource visibility with a new condition.
            'resource_visibility' => Db\Filter\ReservedResourceVisibilityFilter::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'isUnderEmbargo' => Service\ViewHelper\IsUnderEmbargoFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'AccessResource\Controller\AccessResource' => Service\Controller\AccessResourceControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'isUnderEmbargo' => Mvc\Controller\Plugin\IsUnderEmbargo::class,
        ],
        'factories' => [
            'mediaFilesize' => Service\ControllerPlugin\MediaFilesizeFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Service\Property\ReservedAccess::class => Service\Property\ReservedAccess::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-resource-file' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    // The "{?}" allows to use module Archive Repertory and a full filepath.
                    'route' => '/access/files/:type/:file{?}',
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'AccessResource',
                        'action' => 'files',
                        'access_mode' => ACCESS_MODE,
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'accessresource' => [
        // Access mode may be "global", "ip" or "individual".
        'access_mode' => 'global',
        'config' => [
            // This setting is just for info: it is overridden by [accessresource][access_mode]
            // that should be set in config/local.config.php.
            'accessresource_access_mode' => 'global',
            'accessresource_embargo_bypass' => false,
            'accessresource_embargo_auto_update' => false,
            'accessresource_ip_sites' => [
            ],
            // Hidden settings automatically filled after saving config.
            // It contains the same data than "accessresource_ip_sites", but
            // with site ids (not slug) and the numberized ip ranges (cidr) in
            // order to do a quicker control of rights.
            'accessresource_ip_reserved' => [
            ],
        ],
        'settings' => [
            'accessresource_message_send' => true,
            'accessresource_message_admin_subject' => 'New request status!', //@translate
            'accessresource_message_admin_request_created' => 'User created new request to AccessResource. Please, check request dashboard.', //@translate
            'accessresource_message_admin_request_updated' => 'User request to resource access is updated.', //@translate
            'accessresource_message_user_subject' => 'New request status!', //@translate
            'accessresource_message_user_request_created' => 'Your request is sent to administrator. You will be inform when your request will change.', //@translate
            'accessresource_message_user_request_updated' => 'Your request to resource access is updated. You can check guest user requests dashboard.', //@translate
        ],
    ],
];
