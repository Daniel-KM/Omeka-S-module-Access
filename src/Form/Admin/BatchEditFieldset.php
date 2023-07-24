<?php declare(strict_types=1);

namespace AccessResource\Form\Admin;

use AccessResource\Entity\AccessStatus;
use AccessResource\Form\Element as AccessResourceElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        $accessViaProperty = (bool) $this->getOption('access_via_property');
        $embargoViaProperty = (bool) $this->getOption('embargo_via_property');

        $this
            ->setName('accessresource')
            ->setLabel('Access resource') // @translate
            ->setAttributes([
                'id' => 'accessresource',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'o-access:status',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'New status', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        AccessStatus::FREE => 'Free', // @translate
                        AccessStatus::RESERVED => 'Restricted', // @translate
                        AccessStatus::PROTECTED => 'Protected', // @translate
                        AccessStatus::FORBIDDEN => 'Forbidden', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_o_access_status',
                    'class' => 'accessresource',
                    'disabled' => $accessViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])

            ->add([
                'name' => 'embargo_start_update',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Embargo start', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        'remove' => 'Remove', // @translate
                        // 'set' => 'Set date below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_update',
                    'class' => 'accessresource',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            /*// TODO Issue with validation because there is a fieldset. EIther check array or remove vaiidator.
            ->add([
                'name' => 'embargo_start_date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'Embargo start date', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_date',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_start_time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_time',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            */
            ->add([
                'name' => 'embargo_end_update',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Embargo end', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        'remove' => 'Remove', // @translate
                        // 'set' => 'Set date below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_update',
                    'class' => 'accessresource',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            /*
            ->add([
                'name' => 'embargo_end_date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'Embargo end date', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_date',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_end_time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_time',
                    'disabled' => $embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            */
        ;
    }
}
