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
            'resource_visibility' => Db\Filter\ResourceVisibilityFilter::class,
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
        'factories' => [
            Form\Admin\AccessRequestForm::class => Service\Form\FormFactory::class,
            Form\Admin\AccessResourceForm::class => Service\Form\FormFactory::class,
            Form\AccessRequestForm::class => Service\Form\FormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'AccessResource\Controller\Admin\Access' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Admin\Request' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Admin\Log' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Download' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\GuestDashboard' => Service\Controller\ControllerFactory::class,
            'AccessResource\Controller\Request' => Service\Controller\ControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Service\Property\ReservedAccess::class => Service\Property\ReservedAccess::class,
            Service\RequestMailerFactory::class   =>  Service\RequestMailerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-resource-file-download' => [
                'type' => \Zend\Router\Http\Segment::class,
                'options' => [
                    'route' => '/access-resource/download/files/:type/:file',
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'Download',
                        'action' => 'files',
                    ],
                ],
            ],
            'access-resource-request' => [
                'type' => \Zend\Router\Http\Segment::class,
                'options' => [
                    'route' => '/access-resource/request',
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'Request',
                        'action' => 'submit',
                    ],
                ],
            ],
            'guest-user-dashboard' => [
                'type' => \Zend\Router\Http\Segment::class,
                'options' => [
                    'route' => '/access-resource/guest-dashboard',
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller'    => 'GuestDashboard',
                        'action'        => 'browse',
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
                'route' => 'admin/access-resource/default',
                'controller' => 'access',
                'action' => 'browse',
                'pages' => [
                    [
                        'label' => 'Access Management', // @translate
                        'route' => 'admin/access-resource/default',
                        'controller' => 'access',
                        'action' => 'browse',
                    ],
                    [
                        'label' => 'Requests', // @translate
                        'route' => 'admin/access-resource/default',
                        'controller' => 'request',
                        'action' => 'browse',
                    ],
                    [
                        'label' => 'Logs', // @translate
                        'route' => 'admin/access-resource/default',
                        'controller' => 'log',
                        'action' => 'browse',
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
        'settings' => [
            'accessresource_mail_subject' => 'New request status!', //@translate
            'accessresource_admin_message_request_created' => 'User created new request to AccessResource. Please, check request dashboard.', //@translate
            'accessresource_user_message_request_created' => 'Your request is sent to administrator. You will be inform when your request will change.', //@translate
            'accessresource_admin_message_request_updated' => 'User request to resource access is updated.', //@translate
            'accessresource_user_message_request_updated' => 'Your request to resource access is updated. You can check guest user requests dashboard.', //@translate
        ]
    ]
];
