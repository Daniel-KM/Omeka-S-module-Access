<?php declare(strict_types=1);

namespace AccessResource\Form;

use const AccessResource\ACCESS_MODE_GLOBAL;
use const AccessResource\ACCESS_MODE_IP;
use const AccessResource\ACCESS_MODE_INDIVIDUAL;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'accessresource_access_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Reserved access mode (to be set in config/local.config.php)', // @translate
                    'info' => 'Access to a media is "reserved" when it has the property "curation:reserved" filled, whatever it is.', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource#access-mode',
                    'value_options' => [
                        ACCESS_MODE_GLOBAL => 'Global: all users, included guests, have access to all reserved medias', // @translate
                        ACCESS_MODE_IP => 'IP: visitors with specified ips have access to all reserved medias', // @translate
                        ACCESS_MODE_INDIVIDUAL => 'Individual: guests should request access to each reserved media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_access_mode',
                    'required' => true,
                    'disabled' => 'disabled',
                    'style' => 'display: block;',
                ],
            ])
            ->add([
                'name' => 'accessresource_access_via_property',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Set access via property (to be set in config/local.config.php)', // @translate
                    'value_options' => [
                        '' => 'Do not use property', // @translate
                        'status' => 'Access via property with mode "status" (three possible values)', // @translate
                        'reserved' => 'Access via property with mode "reserved" (presence or not of a value)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_access_via_property',
                    'required' => false,
                    'disabled' => 'disabled',
                    'style' => 'display: block;',
                ],
            ])
            ->add([
                'name' => 'accessresource_access_via_property_statuses',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Labels for the three statuses (for mode property/status)', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'accessresource_access_via_property_statuses',
                    'rows' => 3,
                    'disabled' => 'disabled',
                    'placeholder' => 'free = free
reserved = reserved
forbidden = forbidden
',
                ],
            ])

            ->add([
                'name' => 'accessresource_hide_in_advanced_tab',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Hide the access status in advanced tab of resource form when mode is property', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_hide_in_advanced_tab',
                ],
            ])

            ->add([
                'name' => 'accessresource_embargo_bypass',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Bypass embargo dates for reserved resources', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_bypass',
                ],
            ])
            ->add([
                'name' => 'accessresource_embargo_auto_update',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Automatically update visibility status', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_auto_update',
                ],
            ])
            ->add([
                'name' => 'accessresource_ip_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of ips with open access, eventually limited to selected item sets', // @translate
                    'info' => 'These ips will have unrestricted access to all resources or only resources of the specified item sets. List them separated by a "=", one by line. Range ip are allowed (formatted as cidr).', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'accessresource_ip_item_sets',
                    'rows' => 12,
                    'placeholder' => '12.34.56.78
124.8.16.32 = 1 2 5
87.65.43.0/24 = 1 7',
                ],
            ])
        ;

        $this->getInputFilter()
            ->add([
                'name' => 'accessresource_access_mode',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_access_via_property',
                'required' => false,
            ])
        ;
    }
}
