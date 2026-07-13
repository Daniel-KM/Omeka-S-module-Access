<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;
use Laminas\InputFilter\InputFilterProviderInterface;

/**
 * Base of an access-scope rule: the two collection pickers (allow / forbid).
 *
 * The source field is added by the concrete fieldset, because Laminas runs
 * init() before the target-element options are applied, so the source type
 * cannot be read from an option here; a distinct class per source type is used
 * instead.
 */
abstract class AbstractAccessScopeRuleFieldset extends Fieldset implements InputFilterProviderInterface
{
    protected function addScopeCollections(): void
    {
        $this
            ->add([
                'name' => 'allow',
                'type' => CommonElement\OptionalItemSetSelect::class,
                'options' => [
                    'label' => 'Access only to these item sets', // @translate
                    'info' => 'Leave empty to grant access to every reserved resource.', // @translate
                    'empty_option' => '',
                    'disable_inarray_validator' => true,
                ],
                'attributes' => [
                    'class' => 'chosen-select access-scope-allow',
                    'multiple' => true,
                    'data-placeholder' => 'Select item sets…', // @translate
                ],
            ])
            ->add([
                'name' => 'forbid',
                'type' => CommonElement\OptionalItemSetSelect::class,
                'options' => [
                    'label' => 'Except these item sets', // @translate
                    'info' => 'Item sets excluded even when included above, useful with a global item set identifying all reserved resources.', // @translate
                    'empty_option' => '',
                    'disable_inarray_validator' => true,
                ],
                'attributes' => [
                    'class' => 'chosen-select access-scope-forbid',
                    'multiple' => true,
                    'data-placeholder' => 'Select item sets…', // @translate
                ],
            ])
        ;
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'allow' => [
                'required' => false,
            ],
            'forbid' => [
                'required' => false,
            ],
        ];
    }
}
