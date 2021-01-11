<?php declare(strict_types=1);

namespace AccessResource\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\CkeditorInline;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Access resource'; // @translate

    public function init(): void
    {
        $this->add([
            'name' => 'accessresource_message_send',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Send email to admin and user on access request or update', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_send',
            ],
        ]);

        $this->add([
            'name' => 'accessresource_message_admin_subject',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Admin email subject', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_admin_subject',
            ],
        ]);
        $this->add([
            'name' => 'accessresource_message_admin_request_created',
            'type' => CkeditorInline::class,
            'options' => [
                'label' => 'Message to admin for new request', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_admin_request_created',
            ],
        ]);
        $this->add([
            'name' => 'accessresource_message_admin_request_updated',
            'type' => CkeditorInline::class,
            'options' => [
                'label' => 'Message to admin for updated request', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_admin_request_updated',
            ],
        ]);

        $this->add([
            'name' => 'accessresource_message_user_subject',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'User email subject', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_user_subject',
            ],
        ]);
        $this->add([
            'name' => 'accessresource_message_user_request_created',
            'type' => CkeditorInline::class,
            'options' => [
                'label' => 'Message to user for new request', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_user_request_created',
            ],
        ]);
        $this->add([
            'name' => 'accessresource_message_user_request_updated',
            'type' => CkeditorInline::class,
            'options' => [
                'label' => 'Message to user for updated request', // @translate
            ],
            'attributes' => [
                'id' => 'accessresource_message_user_request_updated',
            ],
        ]);
    }
}
