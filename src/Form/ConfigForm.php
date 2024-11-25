<?php declare(strict_types=1);

namespace Access\Form;

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
                'name' => 'access_full',
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
                    'id' => 'access_full',
                    'disabled' => 'disabled',
                    'style' => 'display: inline-block;',
                ],
            ])

            ->add([
                'name' => 'access_modes',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Access modes', // @translate
                    'value_options' => [
                        'ip' => 'IP: visitors with specified ips have access to a list of reserved media by item sets', // @translate
                        'guest' => 'Guest: all users, included guests, have access to all reserved medias', // @translate
                        'auth_external' => 'External: users externally authenticated (cas, ldap, sso) have access to all reserved medias', // @translate
                        'auth_cas' => 'CAS: users authenticated by cas have access to all reserved medias', // @translate
                        'auth_ldap' => 'LDAP: users authenticated by ldap have access to all reserved medias', // @translate
                        'auth_sso' => 'SSO: users authenticated by sso have access to all reserved medias', // @translate
                        'auth_sso_idp' => 'SSO / IDP: users authenticated by specified identity providers have access to a list of reserved media by item sets', // @translate
                        'email_regex' => 'Users authenticated with a specific email have access to all reserved medias', // @translate
                        'user' => 'User: authenticated users should request access to specific reserved medias', // @translate
                        'email' => 'Email: A visitor identified by email should request access to specific reserved medias', // @translate
                        'token' => 'Token: A user or visitor with a token have access to specific reserved medias', // @translate
                    ],
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                ],
                'attributes' => [
                    'id' => 'access_modes',
                    'required' => false,
                    'style' => 'display: inline-block;',
                ],
            ])

            ->add([
                'name' => 'access_ip_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of ips with open access, eventually limited to selected item sets', // @translate
                    'info' => 'These ips will have unrestricted access to all resources or only resources of the specified item sets. List them separated by a "=", one by line. Range ip are allowed (formatted as cidr). An item set prepended with a "-" means an excluded item set, in particular when a global item set is defined to identify all reserved resources.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'access_ip_item_sets',
                    'rows' => 12,
                    'placeholder' => '12.34.56.78
124.8.16.32 = 1 2 -5
65.43.21.0/24 = 1 -7',
                ],
            ])

            ->add([
                'name' => 'access_ip_proxy',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Check forwarded ip first (in proxy environment)', // @translate
                ],
                'attributes' => [
                    'id' => 'access_ip_proxy',
                    'required' => false,
                ],
            ])

            ->add([
                'name' => 'access_auth_sso_idp_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of sso idp with open access, eventually limited to selected item sets', // @translate
                    'info' => 'These identity providers will have unrestricted access to all resources or only resources of the specified item sets. List them separated by a "=", one by line. The idp name is the domain name or the value used in the login form. "federation" can be used for all users authenticated via a federated idp. An item set prepended with a "-" means an excluded item set, in particular when a global item set is defined to identify all reserved resources.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'access_auth_sso_idp_item_sets',
                    'rows' => 12,
                    'placeholder' => 'idp.example.org
shibboleth.another-example.org = 1 2 -5
federation = -4',
                ],
            ])

            ->add([
                'name' => 'access_email_regex',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Regex on email of users allowed to access reserved medias (option above)', // @translate
                ],
                'attributes' => [
                    'id' => 'access_email_regex',
                ],
            ])

            ->add([
                'name' => 'access_property',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Set access level and embargo via a property', // @translate
                ],
                'attributes' => [
                    'id' => 'access_property',
                    'required' => false,
                ],
            ])

            /* // TODO Only mode "level" (three/four values) is supported for now.
            ->add([
                'name' => 'access_property_level_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Set access via property', // @translate
                    'value_options' => [
                        'level' => 'Access via property with mode "level" (four possible values)', // @translate
                        'reserved' => 'Access via property with mode "reserved" (presence or not of a value)', // @translate
                        'protected' => 'Access via property with mode "protected" (presence or not of a value)', // @translate
                    ],
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                ],
                'attributes' => [
                    'id' => 'access_property_level_mode',
                    'class' => 'access-property',
                    'required' => false,
                    'style' => 'display: inline-block;',
                ],
            ])
            */

            ->add([
                'name' => 'access_property_level',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property when access uses property', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'access_property_level',
                    'class' => 'chosen-select access-property',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])
            ->add([
                'name' => 'access_property_levels',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Labels for the four levels for mode property/level', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'access_property_levels',
                    'class' => 'access-property',
                    'rows' => 4,
                    'placeholder' => 'free = free
reserved = reserved
protected = protected
forbidden = forbidden
',
                ],
            ])
            ->add([
                'name' => 'access_property_level_datatype',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Data type to use for the level', // @translate
                    'info' => 'A data type like "literal" (default) or "customvocab:X" (recommended), where X is the custom vocab id to set. This value is used for batch processes.', // @translate
                ],
                'attributes' => [
                    'id' => 'access_property_level_datatype',
                    'class' => 'access-property',
                    'placeholder' => 'customvocab:2',
                ],
            ])

            ->add([
                'name' => 'access_property_embargo_start',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property to use for embargo start', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'access_property_embargo_start',
                    'class' => 'chosen-select access-property',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])
            ->add([
                'name' => 'access_property_embargo_end',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Set property to use for embargo end', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'access_property_embargo_end',
                    'class' => 'chosen-select access-property',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])

            ->add([
                'name' => 'access_property_show_in_advanced_tab',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Show the access status in advanced tab of resource form when properties are used', // @translate
                ],
                'attributes' => [
                    'id' => 'access_property_show_in_advanced_tab',
                    'class' => 'access-property',
                ],
            ])

            ->add([
                'name' => 'access_embargo_bypass',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Bypass embargo dates for reserved resources', // @translate
                ],
                'attributes' => [
                    'id' => 'access_embargo_bypass',
                ],
            ])
        ;

        // Process indexation of missing access levels for items and medias.
        $this
            ->add([
                'name' => 'fieldset_index',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Jobs to create missing access status of all resources', // @translate
                ],
            ]);

        $fieldset = $this->get('fieldset_index');
        $fieldset
            ->add([
                'name' => 'recursive',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Copy level and embargo', // @translate
                    'value_options' => [
                        'from_item_sets_to_items_and_media' => 'From item sets to items and medias', // @translate
                        'from_items_to_media' => 'From items to medias', // @translate
                        // TODO Add "when not set".
                    ],
                ],
                'attributes' => [
                    'id' => 'recursive',
                ],
            ])
            ->add([
                'name' => 'sync',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Copy access level and embargo', // @translate
                    'value_options' => [
                        'skip' => 'Skip', // @translate
                        'from_properties_to_index' => 'Copy data from property values into indexes', // @translate
                        'from_index_to_properties' => 'Copy data from indexes into property values', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync',
                ],
            ])
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
                'name' => 'access_full',
                'required' => false,
            ])
            ->add([
                'name' => 'access_modes',
                'required' => false,
            ])
            ->add([
                'name' => 'access_property',
                'required' => false,
            ])
            ->add([
                'name' => 'access_property_level',
                'required' => false,
            ])
            ->add([
                'name' => 'access_property_embargo_start',
                'required' => false,
            ])
            ->add([
                'name' => 'access_property_embargo_end',
                'required' => false,
            ])
            ->get('fieldset_index')
            ->add([
                'name' => 'recursive',
                'required' => false,
            ])
            ->add([
                'name' => 'sync',
                'required' => false,
            ])
            ->add([
                'name' => 'missing',
                'required' => false,
            ])
        ;
    }
}
