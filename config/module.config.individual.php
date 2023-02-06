<?php declare(strict_types=1);

namespace AccessResource;

return [
    'api_adapters' => [
        'invokables' => [
            'access_requests' => Api\Adapter\AccessRequestAdapter::class,
            'access_resources' => Api\Adapter\AccessResourceAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
        'filters' => [
            // Override Omeka core resource visibility with a new condition.
            'resource_visibility' => Db\Filter\ReservedResourceVisibilityFilter::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'accessResourceRequestForm' => Service\ViewHelper\AccessResourceRequestFormFactory::class,
            'accessStatus' => Service\ViewHelper\AccessStatusFactory::class,
            'isUnderEmbargo' => Service\ViewHelper\IsUnderEmbargoFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\Admin\AccessRequestForm::class => Form\Admin\AccessRequestForm::class,
            Form\Admin\AccessResourceForm::class => Form\Admin\AccessResourceForm::class,
            Form\AccessRequestForm::class => Form\AccessRequestForm::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'AccessResource\Controller\Site\GuestBoard' => Controller\Site\GuestBoardController::class,
            'AccessResource\Controller\Site\Request' => Controller\Site\RequestController::class,
        ],
        'factories' => [
            'AccessResource\Controller\AccessResource' => Service\Controller\AccessResourceControllerFactory::class,
            'AccessResource\Controller\Admin\Access' => Service\Controller\AccessControllerFactory::class,
            'AccessResource\Controller\Admin\Log' => Service\Controller\LogControllerFactory::class,
            'AccessResource\Controller\Admin\Request' => Service\Controller\RequestControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'accessStatus' => Service\ControllerPlugin\AccessStatusFactory::class,
            'isForbiddenFile' => Service\ControllerPlugin\IsForbiddenFileFactory::class,
            'isUnderEmbargo' => Service\ControllerPlugin\IsUnderEmbargoFactory::class,
            'mediaFilesize' => Service\ControllerPlugin\MediaFilesizeFactory::class,
            'requestMailer' => Service\ControllerPlugin\RequestMailerFactory::class,
            // TODO Store the reserved access property id as a constant to avoid to get it each request.
            'reservedAccessPropertyId' => Service\ControllerPlugin\ReservedAccessPropertyIdFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-resource-file' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    // See module Statistics too.
                    // Manage module Archive repertory, that can use real names and subdirectories.
                    // For any filename, either use `:filename{?}`, or add a constraint `'filename' => '.+'`.
                    'route' => '/access/files/:type/:filename{?}',
                    'constraints' => [
                        'type' => '[^/]+',
                        'filename' => '.+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'AccessResource',
                        'action' => 'file',
                        'access_mode' => ACCESS_MODE_INDIVIDUAL,
                    ],
                ],
            ],
            'site' => [
                'child_routes' => [
                    'access-resource-request' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/access-resource-request',
                            'defaults' => [
                                '__NAMESPACE__' => 'AccessResource\Controller\Site',
                                'controller' => 'Request',
                                'action' => 'submit',
                            ],
                        ],
                    ],
                    'guest' => [
                        // The default values for the guest user route are kept
                        // to avoid issues for visitors when an upgrade of
                        // module Guest occurs or when it is disabled.
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/guest',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'access-resource' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/access-resource',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'AccessResource\Controller\Site',
                                        'controller' => 'GuestBoard',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'access-resource' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/access-resource',
                            'defaults' => [
                                '__NAMESPACE__' => 'AccessResource\Controller\Admin',
                                'controller' => 'access',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller[/:action]',
                                    'constraints' => [
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'AccessResource\Controller\Admin',
                                        'controller' => 'access',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller/:id[/:action]',
                                    'constraints' => [
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'AccessResource\Controller\Admin',
                                        'controller' => 'access',
                                        'action' => 'edit',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Access Resources', // @translate
                'route' => 'admin/access-resource',
                'controller' => 'access',
                'pages' => [
                    [
                        'label' => 'Access Management', // @translate
                        'route' => 'admin/access-resource',
                        'controller' => 'access',
                        'action' => 'browse',
                        'pages' => [
                            [
                                'route' => 'admin/access-resource/default',
                                'controller' => 'access',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/access-resource/id',
                                'controller' => 'access',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Requests', // @translate
                        'route' => 'admin/access-resource/default',
                        'controller' => 'request',
                        'action' => 'browse',
                        'pages' => [
                            [
                                'route' => 'admin/access-resource/default',
                                'controller' => 'request',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/access-resource/id',
                                'controller' => 'request',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Logs', // @translate
                        'route' => 'admin/access-resource/default',
                        'controller' => 'log',
                        'action' => 'browse',
                    ],
                    [
                        'route' => 'admin/access-resource',
                        'visible' => false,
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
        'access_mode' => 'individual',
        // The access right can be set via a property (curation:reserved by default)
        // to simplify some workflows, in particular for import.
        // In all cases, the access right is stored in the table "access_reserved".
        'access_via_property' => false,
        'config' => [
            // This setting is just for info: it is overridden by [accessresource][access_mode]
            // that should be set in config/local.config.php.
            'accessresource_access_mode' => ACCESS_MODE_INDIVIDUAL,
            // This setting is just for info: it is overridden by [accessresource][access_via_property]
            // that should be set in config/local.config.php.
            'accessresource_access_via_property' => false,
            'accessresource_embargo_bypass' => false,
            'accessresource_embargo_auto_update' => false,
            'accessresource_ip_item_sets' => [],
            // Hidden settings automatically filled after saving config.
            // It contains the same data than "accessresource_ip_item_sets", but
            // with numberized ip ranges (cidr) in order to do a quicker control
            // of rights.
            'accessresource_ip_reserved' => [
                /*
                '123.45.67.89' => [
                    'low' => 2066563929,
                    'high' => 2066563929,
                    'reserved' => [],
                ],
                '123.45.68.0/24' => [
                    'low' => 2066564096,
                    'high' => 2066564351,
                    'reserved' => [2],
                ],
                */
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
