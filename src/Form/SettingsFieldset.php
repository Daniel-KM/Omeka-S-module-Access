<?php declare(strict_types=1);

namespace Access\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\CkeditorInline;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Access resource'; // @translate

    protected $elementGroups = [
        'access' => 'Access resource', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'access-resource')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'access_message_send',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Send email to admin and user on access request or update', // @translate
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
                'type' => CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to admin for new request', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_admin_request_created',
                ],
            ])
            ->add([
                'name' => 'access_message_admin_request_updated',
                'type' => CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to admin for updated request', // @translate
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
                'type' => CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to user for new request', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_request_created',
                ],
            ])
            ->add([
                'name' => 'access_message_user_request_updated',
                'type' => CkeditorInline::class,
                'options' => [
                    'element_group' => 'access',
                    'label' => 'Message to user for updated request', // @translate
                ],
                'attributes' => [
                    'id' => 'access_message_user_request_updated',
                ],
            ])

            ->add([
                'name' => 'access_message_access_text',
                'type' => CkeditorInline::class,
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
