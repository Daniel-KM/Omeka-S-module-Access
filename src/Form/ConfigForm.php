<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    protected $elementGroups = [
        'rights' => 'Access rights', // @translate
        'files' => 'Files to protect', // @translate
        'modes' => 'Access modes', // @translate
        'embargo' => 'Embargo', // @translate
    ];

    public function init(): void
    {
        $this->setOption('element_groups', $this->elementGroups);

        $this
            ->add([
                'name' => 'access_rights_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'rights',
                    'text' => 'Access level and embargo dates can be managed either separately as specific metadata of each resource set via a tab in the resource edit page, or as standard property values of the resource.', // @translate
                ],
            ])

            ->add([
                'name' => 'access_property',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'rights',
                    'label' => 'Storage of access level and embargo', // @translate
                    'value_options' => [
                        '0' => 'Resource metadata', // @translate
                        '1' => 'Property in the resource', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_property',
                    'required' => false,
                    'style' => 'display: inline;',
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
                    'element_group' => 'rights',
                    'label' => 'Set property when access uses property', // @translate
                    'info' => 'Warning: a full reindexation is needed when changing the property.', // @translate
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
                    'element_group' => 'rights',
                    'label' => 'Labels for access levels ("protected" is not used currently)', // @translate
                    'info' => 'One level by line, formatted as "key = label". The keys ("free", "reserved", "protected", "forbidden") are fixed; only the labels can be customized. The labels are used as values of the property above (typically a custom vocab) and must match the allowed terms.', // @translate
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
                    'element_group' => 'rights',
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
                    'element_group' => 'rights',
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
                    'element_group' => 'rights',
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
                    'element_group' => 'rights',
                    'label' => 'Show the access status in advanced tab of resource form', // @translate
                ],
                'attributes' => [
                    'id' => 'access_property_show_in_advanced_tab',
                    'class' => 'access-property',
                ],
            ])

            ->add([
                'name' => 'access_files_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'files',
                    'text' => 'Determine if you want to protect only original files or derivative thumbnails too. This option must be set in the file .htaccess of the server. It can be done automatically only if the web server has access to it.', // @translate
                ],
            ])

            ->add([
                'name' => 'access_htaccess_skip',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'files',
                    'label' => 'Do not modify .htaccess (manage Apache redirections manually)', // @translate
                    'info' => 'When checked, the module will not write or update the rewrite rule in the root .htaccess. Add the rules manually to redirect file requests through the Access controller.', // @translate
                ],
                'attributes' => [
                    'id' => 'access_htaccess_skip',
                ],
            ])

            ->add([
                'name' => 'access_htaccess_types',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'files',
                    'label' => 'File types to protect via .htaccess', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'info' => 'Select the file types that should be protected by an Apache rewrite rule in the root .htaccess. The rule redirects direct file access through the module Access controller, which checks access rights. When writable, the .htaccess is updated automatically; otherwise, the rule to copy is displayed.', // @translate
                    'value_options' => [
                        'original' => 'original', // @translate
                        'large' => 'large (derivative: 800px)', // @translate
                        'medium' => 'medium (derivative: 200px)', // @translate
                        'square' => 'square (derivative: 200px)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_htaccess_types',
                    'required' => false,
                    'style' => 'display: inline-block;',
                ],
            ])

            ->add([
                'name' => 'access_htaccess_custom_types',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'files',
                    'label' => 'Custom directory paths to protect via .htaccess', // @translate
                    'info' => 'Additional file subdirectories to protect, for example for the module Derivative Media (mp3, mp4, webm, ogg, pdf, etc.). Separate paths with spaces.', // @translate
                ],
                'attributes' => [
                    'id' => 'access_htaccess_custom_types',
                    'placeholder' => 'mp3 mp4 webm ogg pdf',
                ],
            ])

            ->add([
                'name' => 'access_full',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'files',
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
                'name' => 'access_modes_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'modes',
                    'text' => 'Two approaches can be combined: global rules (ip, guest, authentication…) grant access to a whole audience, while individual requests (user, email, token) let a visitor request access to a specific resource.', // @translate
                ],
            ])

            ->add([
                'name' => 'access_modes',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'modes',
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
                'name' => 'access_ip_proxy',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'modes',
                    'label' => 'Check forwarded ip first (in proxy environment)', // @translate
                ],
                'attributes' => [
                    'id' => 'access_ip_proxy',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'access_ip_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'modes',
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
                'name' => 'access_auth_sso_idp_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'modes',
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
                    'element_group' => 'modes',
                    'label' => 'Regex on email of users allowed to access reserved medias (option above)', // @translate
                ],
                'attributes' => [
                    'id' => 'access_email_regex',
                ],
            ])

            ->add([
                'name' => 'access_embargo_bypass',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'embargo',
                    'label' => 'Bypass embargo dates for reserved resources', // @translate
                ],
                'attributes' => [
                    'id' => 'access_embargo_bypass',
                ],
            ])
            ->add([
                'name' => 'access_embargo_ended_level',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'embargo',
                    'label' => 'Update access level when embargo ends', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'value_options' => [
                        'free' => 'Set access level to "free"', // @translate
                        'under' => 'Set access level to the level under ("free" for reserved, "reserved" for protected/forbidden)', // @translate
                        'keep' => 'Keep access level', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_embargo_ended_level',
                ],
            ])
            ->add([
                'name' => 'access_embargo_ended_date',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'embargo',
                    'label' => 'Update embargo dates when embargo ends', // @translate
                    'label_attributes' => [
                        'style' => 'display: block;',
                    ],
                    'value_options' => [
                        'clear' => 'Remove embargo dates', // @translate
                        'keep' => 'Keep embargo dates', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_embargo_ended_date',
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
