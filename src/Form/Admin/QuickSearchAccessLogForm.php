<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class QuickSearchAccessLogForm extends Form
{
    public function init(): void
    {
        $this->setAttribute('method', 'get');
        $this->setAttribute('id', 'quick-search-form');

        // No csrf: see main search form.
        $this->remove('csrf');

        $this
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
                'name' => 'access_id',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Access id', // @translate
                ],
                'attributes' => [
                    'id' => 'access_id',
                ],
            ])
            ->add([
                'name' => 'action',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Action', // @translate
                    'value_options' => [
                        '' => 'All', // @translate
                        'accept' => 'Accept', // @translate
                        'reject' => 'Reject', // @translate
                        'renew' => 'Renew', // @translate
                        'revoke' => 'Revoke', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action',
                ],
            ])
            ->add([
                'name' => 'access_type',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Access type', // @translate
                    'value_options' => [
                        '' => 'All', // @translate
                        'request' => 'Request', // @translate
                        'token' => 'Token', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_type',
                ],
            ])
            ->add([
                'name' => 'date',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Date', // @translate
                ],
                'attributes' => [
                    'id' => 'date',
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
