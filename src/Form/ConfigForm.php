<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'access_full',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Protection', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'value_options' => [
                        '0' => 'Protect media content only (files)', // @translate
                        '1' => 'Protect records and content (not supported currently)', // @translate
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
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Access modes', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
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
                    'placeholder' => <<<'TXT'
                        12.34.56.78
                        124.8.16.32 = 17 89 -1940
                        65.43.21.0/24 = -2005
                        TXT,
                ],
            ])

            ->add([
                'name' => 'access_ip_proxy',
                'type' => CommonElement\OptionalCheckbox::class,
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
                    'placeholder' => <<<'TXT'
                        idp.example.org =
                        shibboleth.another-example.org = 17 89 -1940
                        federation = -2005
                        TXT,
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
                'type' => CommonElement\OptionalCheckbox::class,
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
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Set access via property', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'value_options' => [
                        'level' => 'Access via property with mode "level" (four possible values)', // @translate
                        'reserved' => 'Access via property with mode "reserved" (presence or not of a value)', // @translate
                        'protected' => 'Access via property with mode "protected" (presence or not of a value)', // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
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
                    'placeholder' => <<<'TXT'
                        free = free
                        reserved = reserved
                        protected = protected
                        forbidden = forbidden
                        TXT,
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
                'type' => CommonElement\OptionalPropertySelect::class,
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
                'type' => CommonElement\OptionalPropertySelect::class,
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
                'type' => CommonElement\OptionalCheckbox::class,
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Bypass embargo dates for reserved resources', // @translate
                ],
                'attributes' => [
                    'id' => 'access_embargo_bypass',
                ],
            ])
            ->add([
                'name' => 'access_embargo_free',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Update access status when embargo ends', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'value_options' => [
                        'free_keep' => 'Set access level to "free" and keep embargo date', // @translate
                        'free_clear' => 'Set access level to "free" and remove embargo date', // @translate
                        'keep_keep' => 'Keep access level and embargo date', // @translate
                        'keep_clear' => 'Keep access level and remove embargo date', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_embargo_free',
                ],
            ])
        ;

        // Process indexation of missing access levels for items and medias.
        // Used in EasyAdmin too.

        $this
            ->add([
                'name' => 'access_reindex',
                'type' => AccessReindexFieldset::class,
                'options' => [
                    'use_as_base_fieldset' => false,
                ],
            ]);
        $fieldset = $this->get('access_reindex');
        $fieldset
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
    }
}
