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
    }
}
