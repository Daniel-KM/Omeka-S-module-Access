<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class AccessReindexFieldset extends Fieldset
{
    protected $label = 'Access'; // @translate

    protected $elementGroups = [
        'access' => 'Access', // @translate
    ];

    public function init(): void
    {
        $this
            ->setName('access_reindex')
            ->setAttribute('id', 'access')
            ->setOption('element_groups', $this->elementGroups)
            ->setLabel('Jobs to create missing access status of all resources') // @translate

            ->add([
                'name' => 'access_tasks_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => 'Settings must be saved before running any of the following tasks.', // @translate
                ],
            ])
            ->add([
                'name' => 'access_propagation_table_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => <<<'HTML'
                        <details>
                            <summary>
                                Propagation modes summary
                            </summary>
                            <p><strong>Access level</strong></p>
                            <table class="access-propagation-table">
                                <thead>
                                    <tr>
                                        <th>Mode</th>
                                        <th>Child stricter than parent</th>
                                        <th>Child more permissive</th>
                                        <th>Child without status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th><code>skip if set</code> (default)</th>
                                        <td>Unchanged</td>
                                        <td>Unchanged (preserves "free preview" alongside a "forbidden" original)</td>
                                        <td>New row created with the parent level</td>
                                    </tr>
                                    <tr>
                                        <th><code>max restrictive</code></th>
                                        <td>Kept (never demoted)</td>
                                        <td>Promoted to the parent level (breaks a deliberately free child)</td>
                                        <td>New row created with the parent level</td>
                                    </tr>
                                    <tr>
                                        <th><code>overwrite</code></th>
                                        <td>Demoted to the parent level</td>
                                        <td>Promoted to the parent level</td>
                                        <td>New row created with the parent level</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p>In property mode, the level property value is also realigned from access status after the parent value is written, so a child preserved at a stricter level is never silently demoted at the next save.</p>
                            <p><strong>Embargo dates</strong></p>
                            <table class="access-propagation-table">
                                <thead>
                                    <tr>
                                        <th>Propagation embargo</th>
                                        <th>metadata mode</th>
                                        <th>property mode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>Off</th>
                                        <td>Embargo columns untouched on every child. New rows get null.</td>
                                        <td>Embargo property values left intact on every child.</td>
                                    </tr>
                                    <tr>
                                        <th>On</th>
                                        <td>Parent embargo start/end copied per the chosen level mode (skip if set, overwrite, max restrictive).</td>
                                        <td>Embargo property values rewritten from the post-propagation access status.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </details>
                        HTML, // @translate
                    'disable_html_escape' => true,
                ],
            ])
            ->add([
                'name' => 'auto',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Reindex database according to current settings', // @translate
                    'info' => 'Recommended after changing the storage mode or any property used for access level or embargo.', // @translate
                ],
                'attributes' => [
                    'id' => 'auto',
                ],
            ])
            ->add([
                'name' => 'recursive',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Copy level and embargo', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
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
                'name' => 'propagation_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Propagation mode', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'info' => 'Choose how the recursive copy combines an existing child level with the new parent level. If items in scope have heterogeneous media levels (e.g. a free preview alongside a forbidden high-res file), use "skip if set" or do not run the propagate option at all.', // @translate
                    'value_options' => [
                        'skip_if_set' => 'Skip if set (safest, recommended for items with media with different statuses)', // @translate
                        'max_restrictive' => 'Max restrictive (keep the strictest level between parent and child)', // @translate
                        'overwrite' => 'Overwrite (copy parent level, so a forbidden media may become free according to the status of the item or item set)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'propagation_mode',
                    'value' => 'skip_if_set',
                ],
            ])
            ->add([
                'name' => 'sync',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Copy access level and embargo', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'skip' => 'Skip', // @translate
                        'from_properties_to_accesses' => 'Copy data from property values into indexes', // @translate
                        'from_accesses_to_properties' => 'Copy data from indexes into property values', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync',
                    'value' => 'skip',
                ],
            ])
            ->add([
                'name' => 'missing',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Fill missing statuses', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
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
                    'value' => 'skip',
                ],
            ])
        ;
    }
}
