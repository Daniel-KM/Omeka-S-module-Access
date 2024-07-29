<?php declare(strict_types=1);

namespace Access\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

/**
 * @see \Access\Form\AccessRequestFieldset
 * @see \ContactUs\Form\ContactUsFieldset
 * @see \ContactUs\Form\NewsletterFieldset
 */
class AccessRequestFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][heading]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Block title', // @translate
                ],
                'attributes' => [
                    'id' => 'access_heading',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][consent_label]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label for the consent checkbox', // @translate
                ],
                'attributes' => [
                    'id' => 'access_consent_label',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][fields]',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Other fields', // @translate
                    'info' => 'Set the name (ascii only and no space) and the label separated by a "=", one by line. The elements may be adapted via the theme.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'access_fields',
                    'placeholder' => 'phone = Phone', // @translate
                ],
            ])
        ;
        if (class_exists('BlockPlus\Form\Element\TemplateSelect')) {
            $this
                ->add([
                    'name' => 'o:block[__blockIndex__][o:data][template]',
                    'type' => \BlockPlus\Form\Element\TemplateSelect::class,
                    'options' => [
                        'label' => 'Template to display', // @translate
                        'info' => 'Templates are in folder "common/block-layout" of the theme and should start with "access-request".', // @translate
                        'template' => 'common/block-layout/access-request',
                    ],
                    'attributes' => [
                        'class' => 'chosen-select',
                    ],
                ]);
        }
    }
}
