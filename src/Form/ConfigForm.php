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
        'propagation' => 'Propagation', // @translate
    ];

    public function init(): void
    {
        $this->setOption('element_groups', $this->elementGroups);

        $this
            ->add([
                'name' => 'access_levels_table_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'rights',
                    // HTML kept inline so it renders as a real <details> in the
                    // admin form. Translators only need to translate the
                    // human-facing strings inside cells.
                    'text' => '<details><summary>' . 'Access levels summary' /* @translate */ . '</summary>'
                        . '<table class="access-levels-table">'
                        . '<thead><tr>'
                        . '<th>' . 'Level' /* @translate */ . '</th>'
                        . '<th>' . 'Notice (if is_public=1)' /* @translate */ . '</th>'
                        . '<th>' . 'File' /* @translate */ . '</th>'
                        . '<th>' . 'Admin access request' /* @translate */ . '</th>'
                        . '<th>' . 'Author contact' /* @translate */ . '</th>'
                        . '</tr></thead><tbody>'
                        . '<tr><th><code>free</code></th>'
                        . '<td>' . 'Visible' /* @translate */ . '</td>'
                        . '<td>' . 'Downloadable by anyone' /* @translate */ . '</td>'
                        . '<td>-</td><td>-</td></tr>'
                        . '<tr><th><code>reserved</code></th>'
                        . '<td>' . 'Visible' /* @translate */ . '</td>'
                        . '<td>' . 'Blocked for anonymous; unlocked by any active bypass (IP, SSO IDP, guest, CAS, LDAP, external, email regex) or by an approved individual access request' /* @translate */ . '</td>'
                        . '<td>' . 'Yes' /* @translate */ . '</td>'
                        . '<td>' . 'Possible' /* @translate */ . '</td></tr>'
                        . '<tr><th><code>protected</code></th>'
                        . '<td>' . 'Visible' /* @translate */ . '</td>'
                        . '<td>' . 'Blocked for everyone; unlocked ONLY by an approved individual access request. No automatic bypass applies.' /* @translate */ . '</td>'
                        . '<td>' . 'Yes (mandatory)' /* @translate */ . '</td>'
                        . '<td>' . 'Possible' /* @translate */ . '</td></tr>'
                        . '<tr><th><code>forbidden</code></th>'
                        . '<td>' . 'Visible' /* @translate */ . '</td>'
                        . '<td>' . 'Blocked for everyone; no path through the admin access request flow' /* @translate */ . '</td>'
                        . '<td>' . 'No' /* @translate */ . '</td>'
                        . '<td>' . 'Sole recourse (theme-side "contact the author" feature)' /* @translate */ . '</td></tr>'
                        . '</tbody></table>'
                        . '<p><strong>' . 'Notice visibility' /* @translate */ . '</strong>: ' . 'follows Omeka core is_public only; the access level never hides a notice.' /* @translate */ . '</p>'
                        . '<p><strong>' . 'Cascade' /* @translate */ . '</strong>: ' . 'a level set on an item set or an item cascades automatically to the effective level of its items and medias (item set → items → media, strictest wins). File gating reads the effective level, materialized on every save.' /* @translate */ . '</p>'
                        . '</details>',
                    'disable_html_escape' => true,
                ],
            ])

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
                        'ip' => 'IP: visitors with specified ips have access to all reserved medias, or only to those in selected item sets', // @translate
                        'guest' => 'Guest: all users, included guests, have access to all reserved medias', // @translate
                        'auth_external' => 'External: users externally authenticated (cas, ldap, sso) have access to all reserved medias', // @translate
                        'auth_cas' => 'CAS: users authenticated by cas have access to all reserved medias', // @translate
                        'auth_ldap' => 'LDAP: users authenticated by ldap have access to all reserved medias', // @translate
                        'auth_sso' => 'SSO: users authenticated by sso have access to all reserved medias', // @translate
                        'auth_sso_idp' => 'SSO / IDP: users authenticated by specified identity providers have access to all reserved medias, or only to those in selected item sets', // @translate
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
                'name' => 'access_ip_proxy_trusted',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'modes',
                    'label' => 'Trusted proxies (one IP per line)', // @translate
                    'info' => 'When filled, the module reads the real client IP from the proxy headers X-Forwarded-For / X-Real-IP, but only when the request comes from one of these IPs. Without this list, proxy headers are ignored and every visitor is seen with REMOTE_ADDR. Put here the internal IPs of your reverse proxy (Traefik, nginx, Docker bridge, load balancer).', // @translate
                ],
                'attributes' => [
                    'id' => 'access_ip_proxy_trusted',
                    'rows' => 4,
                    'placeholder' => "172.18.0.1\n10.0.0.5",
                ],
            ])
            ->add([
                'name' => 'access_ip_item_sets',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'modes',
                    'label' => 'List of ips with open access, eventually limited to selected item sets', // @translate
                    'info' => <<<'TXT'
                        List the ips separated by a "=", one by line. Range ip are allowed (formatted as cidr). The value after "=" controls which item sets are reachable:
                        - empty (no item set after "="): the ip has unrestricted access to every reserved resource, including resources that are not attached to any item set;
                        - one or more item set ids: the ip has access only to resources attached to at least one of these item sets; resources not in any item set are denied;
                        - an item set id prepended with "-" is an excluded item set, useful when a global item set is defined to identify all reserved resources.
                        TXT, // @translate
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
                    'info' => <<<'TXT'
                        List the identity providers separated by a "=", one by line. The idp name is the entity id (or the value used in the login form). "federation" can be used as fallback for all users authenticated via a federated idp not listed above. The value after "=" controls which item sets are reachable:
                        - empty (no item set after "="): the idp has unrestricted access to every reserved resource, including resources that are not attached to any item set;
                        - one or more item set ids: the idp has access only to resources attached to at least one of these item sets; resources not in any item set are denied;
                        - an item set id prepended with "-" is an excluded item set, useful when a global item set is defined to identify all reserved resources.
                        Without any matching entry (and no "federation" fallback), the user is denied.
                        TXT, // @translate
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
                'name' => 'access_embargo_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'embargo',
                    'text' => 'Specify what the metadata for embargo becomes when the date is reached.', // @translate
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
            ->add([
                'name' => 'access_embargo_cascade',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'propagation',
                    'label' => 'Cascade embargo dates', // @translate
                    'info' => 'Off by default: an embargo is per-resource and only gates the resource it is set on. Check this box to make an embargo set on an item set or an item apply to its items and medias too (widest window: earliest start, latest end), the same way the access level cascades. The embargo is always checked independently from the level. After changing this option, run the "Rebuild access index" task so the effective embargo is recomputed.', // @translate
                ],
                'attributes' => [
                    'id' => 'access_embargo_cascade',
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
