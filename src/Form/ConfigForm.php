<?php declare(strict_types=1);

namespace AccessResource\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'accessresource_full',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Protection', // @translate
                    'value_options' => [
                        '0' => 'Protect media content only (files)', // @translate
                        '1' => 'Protect records and content (not supported currently)', // @translate
                    ],
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_full',
                    'disabled' => 'disabled',
                    'style' => 'display: inline-block;',
                ],
            ])

            ->add([
                'name' => 'accessresource_access_modes',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Access modes', // @translate
                    'value_options' => [
                        'ip' => 'IP: visitors with specified ips have access to all reserved medias', // @translate
                        'guest' => 'Guest: all users, included guests, have access to all reserved medias', // @translate
                        'external' => 'External: users externally authenticated (cas) have access to all reserved medias', // @translate
                        'individual' => 'Individual: users should request access to specific reserved medias', // @translate
                        'token' => 'Token: A user or a visitor with a token have access to specific reserved medias', // @translate
                    ],
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_access_modes',
                    'required' => false,
                    'style' => 'display: inline-block;',
                ],
            ])

            ->add([
                'name' => 'accessresource_level_via_property',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Set access via property', // @translate
                    'value_options' => [
                        '' => 'Do not use property', // @translate
                        'level' => 'Access via property with mode "level" (four possible values)', // @translate
                        // 'reserved' => 'Access via property with mode "reserved" (presence or not of a value)', // @translate
                        // 'protected' => 'Access via property with mode "protected" (presence or not of a value)', // @translate
                    ],
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_level_via_property',
                    'required' => false,
                    'style' => 'display: inline-block;',
                ],
            ])
            ->add([
                'name' => 'accessresource_level_property',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property when access uses property', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'accessresource_level_property',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])
            ->add([
                'name' => 'accessresource_level_property_levels',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Labels for the four levels for mode property/level', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'accessresource_level_property_levels',
                    'rows' => 4,
                    'placeholder' => 'free = free
reserved = reserved
protected = protected
forbidden = forbidden
',
                ],
            ])

            ->add([
                'name' => 'accessresource_embargo_via_property',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Set embargo dates via property', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_via_property',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'accessresource_embargo_property_start',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property to use for embargo start', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_property_start',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])
            ->add([
                'name' => 'accessresource_embargo_property_end',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property to use for embargo end', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_property_end',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
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
                'name' => 'accessresource_hide_in_advanced_tab',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Hide the access status in advanced tab of resource form properties are used', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_hide_in_advanced_tab',
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
65.43.21.0/24 = 1 7',
                ],
            ])
        ;

        // Process indexation of missing access levels for items and medias.
        $this
            ->add([
                'name' => 'fieldset_index',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Create missing access status of all resources', // @translate
                ],
            ]);

        $fieldset = $this->get('fieldset_index');
        $fieldset
            ->add([
                'name' => 'missing',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Fill missing statuses', // @translate
                    'value_options' => [
                        'skip' => 'Skip', // @translate
                        'free' => 'Set access level free for all resources without status', // @translate
                        'reserved' => 'Set access level reserved for all resources without status', // @translate
                        'protected' => 'Set access level protected for all resources without status', // @translate
                        'forbidden' => 'Set access level forbidden for all resources without status', // @translate
                        'visibility_reserved' => 'Set access level free when public and reserved when private', // @translate
                        'visibility_protected' => 'Set access level free when public and protected when private', // @translate
                        'visibility_forbidden' => 'Set access level free when public and forbidden when private', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'missing',
                ],
            ])
            ->add([
                'name' => 'process_index',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Process reindexation', // @translate
                ],
                'attributes' => [
                    'id' => 'process_index',
                    'value' => 'Process', // @translate
                ],
            ])
        ;

        $this->getInputFilter()
            ->add([
                'name' => 'accessresource_full',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_access_modes',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_level_via_property',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_level_property',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_embargo_property_start',
                'required' => false,
            ])
            ->add([
                'name' => 'accessresource_embargo_property_end',
                'required' => false,
            ])
            ->get('fieldset_index')
            ->add([
                'name' => 'missing',
                'required' => false,
            ])
        ;
    }
}
