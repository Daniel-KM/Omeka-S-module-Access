<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class QuickSearchAccessRequestForm extends Form
{
    public function init(): void
    {
        $this->setAttribute('method', 'get');
        $this->setAttribute('id', 'quick-search-form');

        // No csrf: see main search form.
        $this->remove('csrf');

        $this
            ->add([
                'name' => 'email',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Email', // @translate
                ],
                'attributes' => [
                    'id' => 'email',
                ],
            ])
            ->add([
                'name' => 'status',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Status', // @translate
                    'value_options' => [
                        '' => 'All', // @translate
                        'new' => 'New', // @translate
                        'renew' => 'Renew', // @translate
                        'accepted' => 'Accepted', // @translate
                        'rejected' => 'Rejected', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'status',
                ],
            ])
            ->add([
                'name' => 'enabled',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Enabled', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'enabled',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'temporal',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Temporal', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'temporal',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'resource_id',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Resource by id', // @translate
                ],
                'attributes' => [
                    'id' => 'resource_id',
                ],
            ])
            ->add([
                'name' => 'user_id',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'User by id', // @translate
                ],
                'attributes' => [
                    'id' => 'user_id',
                ],
            ])
            ->add([
                'name' => 'created',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Date', // @translate
                ],
                'attributes' => [
                    'id' => 'created',
                    'placeholder' => 'Set a date with optional comparator…', // @translate
                ],
            ])
            ->add([
                'name' => 'submit',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Search', // @translate
                ],
                'attributes' => [
                    'id' => 'submit',
                    'type' => 'submit',
                    'class' => 'button',
                ],
            ]);
    }
}
