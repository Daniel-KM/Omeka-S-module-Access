<?php declare(strict_types=1);

namespace Access\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Access'; // @translate

    protected $elementGroups = [
        'access' => 'Access', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'access')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'access_message_send',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Send email to admin and user or visitor on access request or update', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_send',
                ],
            ])

            ->add([
                'name' => 'access_message_admin_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Admin email subject', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_admin_subject',
                ],
            ])
            ->add([
                'name' => 'access_message_admin_request_created',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to admin for new request', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource} , {resources} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_admin_request_created',
                ],
            ])
            ->add([
                'name' => 'access_message_admin_request_updated',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to admin for updated request', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource} , {resources} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_admin_request_updated',
                ],
            ])

            ->add([
                'name' => 'access_message_user_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'User email subject', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_subject',
                ],
            ])
            ->add([
                'name' => 'access_message_user_request_created',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to user for new request', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_request_created',
                ],
            ])
            ->add([
                'name' => 'access_message_user_request_accepted',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to user for request accepted', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_request_accepted',
                ],
            ])
            ->add([
                'name' => 'access_message_user_request_rejected',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to user for request rejected', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_request_rejected',
                ],
            ])

            ->add([
                'name' => 'access_message_visitor_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Visitor email subject', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_visitor_subject',
                ],
            ])
            ->add([
                'name' => 'access_message_visitor_request_created',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to visitor for new request', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources}, {session_url} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_visitor_request_created',
                ],
            ])
            ->add([
                'name' => 'access_message_visitor_request_accepted',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to visitor for request accepted', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources}, {session_url} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_visitor_request_accepted',
                ],
            ])
            ->add([
                'name' => 'access_message_visitor_request_rejected',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to visitor for request rejected', // @translate
                    'info' => '{main_title}, {main_url}, {site_title}, {site_url}, {email}, {name}, {message}, {resource}, {resources}, {session_url} and specific fields wrapped with "{}".', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_visitor_request_rejected',
                ],
            ])

            ->add([
                'name' => 'access_message_access_text',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message for the block "Access request text" when a resource is not available', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_access_text',
                ],
            ])
        ;
    }
}
