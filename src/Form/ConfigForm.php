<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    /**
     * Placeholder rendered inside the "Access modes" group and replaced by the
     * ip and sso rule collections in getConfigForm.
     */
    const SCOPE_RULES_PLACEHOLDER = '{{access_scope_rules}}';

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
                'name' => 'access_levels_table_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => <<<'HTML'
                        <details>
                            <summary>Quick help about access levels</summary>
                            <table class="access-levels-table">
                                <thead>
                                    <tr>
                                        <th>Level</th>
                                        <th>Public item</th>
                                        <th>Media file</th>
                                        <th>Admin access request</th>
                                        <th>Author contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th><strong>free</strong></th>
                                        <td>Visible</td>
                                        <td>Downloadable by anyone</td>
                                        <td>Useless</td>
                                        <td>Useless</td>
                                    </tr>
                                    <tr>
                                        <th><strong>reserved</strong></th>
                                        <td>Visible</td>
                                        <td>Blocked for anonymous; unlocked by any active bypass (IP, SSO IDP, guest, CAS, LDAP, external, email regex) or by an approved individual access request</td>
                                        <td>Yes</td>
                                        <td>Possible</td>
                                    </tr>
                                    <tr>
                                        <th><strong>protected</strong></th>
                                        <td>Visible</td>
                                        <td>Blocked for everyone; unlocked only by an approved individual access request. No automatic bypass applies.</td>
                                        <td>Yes (mandatory)</td>
                                        <td>Possible</td>
                                    </tr>
                                    <tr>
                                        <th><strong>forbidden</strong></th>
                                        <td>Visible</td>
                                        <td>Blocked for everyone; no path through the admin access request flow</td>
                                        <td>No</td>
                                        <td>Sole recourse</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p><strong>Notice visibility</strong>: The access level and embargo apply only on files and never hide the notice of a resource, that follow Omeka core rules for visibility.</p>
                            <p><strong>Independancy</strong>: The visibility public/private, the access status and the embargo are evaluated separately. Even marked for free access, a private item or media is never accessible on public side.</p>
                            <p><strong>Cascade</strong>: A level set on an item set applies automatically to its items and medias; a level set on an item applies to its medias. When several levels apply, the strictest wins.</p>
                        </details>
                        HTML, // @translate
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
                    'label' => 'Labels for access levels', // @translate
                    'info' => 'One level by line, formatted as "key = label". The keys ("free", "reserved", "protected", "forbidden") are fixed; only the labels can be customized. The labels are used as values of the property above (typically a custom vocab or a table) and must match the allowed terms.', // @translate
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
                    'info' => 'A data type like "literal" (default) or a controlled vocabulary like "customvocab:X" or "table:X". A specific data type is recommended to simplify resource edition and batch processes.', // @translate
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
                    'value_column' => true,
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
                    'value_column' => true,
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
                    'value_column' => true,
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

            // Anchor rendered inside the "Access modes" group; getConfigForm
            // replaces it with the ip and sso rule collections, so they appear
            // right below the access modes instead of in a separate section.
            ->add([
                'name' => 'access_scope_rules_anchor',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'modes',
                    'text' => self::SCOPE_RULES_PLACEHOLDER,
                    'disable_html_escape' => true,
                ],
            ])

            ->add([
                'name' => 'access_ip_rules',
                'type' => Element\Collection::class,
                'options' => [
                    'element_group' => 'modes',
                    'label' => 'IP addresses with open access to reserved files', // @translate
                    'info' => 'For each ip or range, choose the item sets it may reach. Leave both lists empty for access to every reserved resource.', // @translate
                    'count' => 0,
                    'allow_add' => true,
                    'allow_remove' => true,
                    'should_create_template' => true,
                    'template_placeholder' => '__index__',
                    'create_new_objects' => true,
                    'target_element' => [
                        'type' => Admin\AccessIpRuleFieldset::class,
                    ],
                ],
                'attributes' => [
                    'id' => 'access_ip_rules',
                    'class' => 'form-fieldset-collection',
                ],
            ])

            ->add([
                'name' => 'access_auth_sso_idp_rules',
                'type' => Element\Collection::class,
                'options' => [
                    'element_group' => 'modes',
                    'label' => 'SSO identity providers with open access to reserved files', // @translate
                    'info' => 'For each idp (or "federation" as a fallback), choose the item sets it may reach. Leave both lists empty for access to every reserved resource.', // @translate
                    'count' => 0,
                    'allow_add' => true,
                    'allow_remove' => true,
                    'should_create_template' => true,
                    'template_placeholder' => '__index__',
                    'create_new_objects' => true,
                    'target_element' => [
                        'type' => Admin\AccessSsoIdpRuleFieldset::class,
                    ],
                ],
                'attributes' => [
                    'id' => 'access_auth_sso_idp_rules',
                    'class' => 'form-fieldset-collection',
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
                    'value_column' => true,
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
                    'value_column' => true,
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
                    'element_group' => 'embargo',
                    'label' => 'Cascade embargo dates', // @translate
                    'info' => 'By default, an embargo applies only to the resource it is set on. Check this box to make an embargo set on an item set or an item also apply to its items and medias, like the access level. After changing this option, run the "Rebuild access index" task.', // @translate
                ],
                'attributes' => [
                    'id' => 'access_embargo_cascade',
                ],
            ])
        ;

        // Tasks fieldset (rebuild / reset). It provides its own submit buttons;
        // also used in EasyAdmin.
        $this
            ->add([
                'name' => 'access_reindex',
                'type' => Admin\AccessReindexFieldset::class,
                'options' => [
                    'use_as_base_fieldset' => false,
                ],
            ]);
    }
}
