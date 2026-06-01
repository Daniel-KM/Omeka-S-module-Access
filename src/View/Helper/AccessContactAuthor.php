<?php declare(strict_types=1);

namespace Access\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Render a "contact the author" recourse for current or specified resource.
 *
 * Default recourse when a file is marked "forbidden" and the standard admin
 * access request flow is not offered. The author of the document is rarely the
 * same person as the cataloguer (resource owner), so the helper does not use
 * $resource->owner(). Instead:
 *
 *  - If the ContactUs module is installed, delegate to its view helper with
 *    contact='author'. ContactUs resolves the author email from a configured
 *    property (e.g. dcterms:creator) and renders a styled contact form with
 *    consent, hashcash, etc.
 *  - Otherwise, render a short message inviting the visitor to contact the site
 *    administrator, who will forward the request to the author manually.
 */
class AccessContactAuthor extends AbstractHelper
{
    public function __invoke(?AbstractResourceEntityRepresentation $resource = null, array $options = []): string
    {
        $view = $this->getView();

        $resource ??= $view->resource ?? null;
        if (!$resource) {
            return '';
        }

        if (class_exists('ContactUs\Module', false)) {
            $contactUsOptions = $options + [
                'contact' => 'author',
                'resource' => $resource,
                'heading' => $view->translate('Contact the author'), // @translate
                'as_button' => true,
            ];
            return (string) $view->contactUs($contactUsOptions);
        }

        return $partial('common/access-contact-author', [
            'resource' => $resource,
        ] + $options);
    }
}
