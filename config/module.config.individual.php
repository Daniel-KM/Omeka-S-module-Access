<?php
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
            'requestResourceAccessForm' => Service\ViewHelper\ViewHelperFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\Admin\AccessRequestForm::class => Service\Form\FormFactory::class,
            Form\Admin\AccessResourceForm::class => Service\Form\FormFactory::class,
            Form\AccessRequestForm::class => Service\Form\FormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'AccessResource\Controller\AccessResource' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Admin\Access' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Admin\Log' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Admin\Request' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Site\GuestBoard' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Site\Request' => Service\Controller\ControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'mediaFilesize' => Service\ControllerPlugin\MediaFilesizeFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Service\Property\ReservedAccess::class => Service\Property\ReservedAccess::class,
            Service\RequestMailerFactory::class => Service\RequestMailerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-resource-file' => [
                'type' => \Zend\Router\Http\Segment::class,
                'options' => [
                    // The "{?}" allows to use module Archive Repertory and a full filepath.
                    'route' => '/access/files/:type/:file{?}',
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'AccessResource',
                        'action' => 'files',
                        'access_mode' => 'individual',
                    ],
                ],
            ],
            'site' => [
                'child_routes' => [
                    'access-resource-request' => [
                        'type' => \Zend\Router\Http\Literal::class,
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
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/guest',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'access-resource' => [
                                'type' => \Zend\Router\Http\Literal::class,
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
                        'type' => \Zend\Router\Http\Literal::class,
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
                                'type' => \Zend\Router\Http\Segment::class,
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
                                'type' => \Zend\Router\Http\Segment::class,
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
        // Can be "global" or "individual".
        'access_mode' => 'individual',
        'config' => [
            // This setting is just for info: it is overridden by [accessresource][access_mode]
            // that should be set in config/local.config.php.
            'accessresource_access_mode' => 'global',
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
