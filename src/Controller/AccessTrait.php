<?php declare(strict_types=1);

namespace Access\Controller;

use Access\Api\Representation\AccessRequestRepresentation;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Part as MimePart;

trait AccessTrait
{
    /**
     * Send email to admin and user when a request is created.
     */
    protected function sendRequestEmailCreate(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        if (!$this->settings()->get('access_message_send')) {
            return true;
        }

        // If no user, it should be already checked.
        if ($user = $accessRequest->user()) {
            $post['o:email'] = $user->email();
            $post['o:name'] = $user->name();
        }

        // Simplify process (for placeholders).
        $post['access_request'] = $accessRequest;
        if (!is_array($post['o:resource'])) {
            $post['o:resource'] = empty($post['o:resource']) ? [] : [$post['o:resource']];
        }

        $isVisitor = $this->isVisitor($accessRequest, $post);
        $result1 = $this->sendMailToAdmin('created', $post);
        $result2 = $this->sendMailToUser('created', $post, $isVisitor);

        return $result1 && $result2;
    }

    /**
     * Send email to admin and user when a request is updated.
     */
    protected function sendRequestEmailUpdate(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        if (!$this->settings()->get('access_message_send')) {
            return true;
        }

        // If no user, it should be already checked.
        if ($user = $accessRequest->user()) {
            $post['o:email'] = $user->email();
            $post['o:name'] = $user->name();
        }

        // Simplify process (for placeholders).
        $post['access_request'] = $accessRequest;
        if (!is_array($post['o:resource'])) {
            $post['o:resource'] = empty($post['o:resource']) ? [] : [$post['o:resource']];
        }

        $isVisitor = $this->isVisitor($accessRequest, $post);
        $isRejected = $accessRequest->status() === \Access\Entity\AccessRequest::STATUS_REJECTED;

        // $this->sendMailToAdmin('updated', $post);
        $result = $this->sendMailToUser('updated', $post, $isVisitor, $isRejected);

        return $result;
    }

    /**
     * Send a mail to administrator.
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToAdmin(?string $action, array $post): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleSettings = require dirname(__DIR__, 2) . '/config/module.config.php';
        $moduleSettings = $moduleSettings['access']['settings'];

        if ($action === 'created') {
            $subject = $settings->get('access_message_admin_subject', $this->translate($moduleSettings['access_message_admin_subject']));
            $body = $settings->get('access_message_admin_request_created', $this->translate($moduleSettings['access_message_admin_request_created']));
        } elseif ($action === 'updated') {
            $subject = $settings->get('access_message_admin_subject', $this->translate($moduleSettings['access_message_admin_subject']));
            $body = $settings->get('access_message_admin_request_updated', $this->translate($moduleSettings['access_message_admin_request_updated']));
        }

        $subject = $this->replacePlaceholders($subject, $post);
        $body = $this->replacePlaceholders($body, $post);

        return $this->sendEmail($subject, $body);
    }

    /**
     * Send a email to user or visitor.
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToUser(?string $action, array $post, bool $isVisitor, ?bool $isRejected = null): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleSettings = require dirname(__DIR__, 2) . '/config/module.config.php';
        $moduleSettings = $moduleSettings['access']['settings'];

        // Messages are not the same for users and visitors.
        $userVisitor = $isVisitor ? 'visitor' : 'user';
        $acceptedRejected = $isRejected ? 'rejected' : 'accepted';

        if ($action === 'created') {
            $subject = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleSettings["access_message_{$userVisitor}_subject"]));
            $body = $settings->get("access_message_{$userVisitor}_request_created", $this->translate($moduleSettings["access_message_{$userVisitor}_request_created"]));
        } elseif ($action === 'updated') {
            $subject = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleSettings["access_message_{$userVisitor}_subject"]));
            $body = $settings->get("access_message_{$userVisitor}_request_{$acceptedRejected}", $this->translate($moduleSettings["access_message_{$userVisitor}_request_{$acceptedRejected}"]));
        }

        $subject = $this->replacePlaceholders($subject, $post);
        $body = $this->replacePlaceholders($body, $post);

        return $this->sendEmail($subject, $body, [$post['to'] => (string) $post['toName']]);
    }

    protected function replacePlaceholders(string $string, array $post): string
    {
        $plugins = $this->getPluginManager();
        $url = $plugins->get('url');
        $helpers = $this->viewHelpers();
        $escape = $helpers->get('escapeHtml');

        try {
            $site = $plugins->get('currentSite')();
        } catch (\Exception $e) {
            $site = $plugins->get('defaultSite')();
        }

        $replace = [
            '{main_title}' => $this->mailer()->getInstallationTitle(),
            '{main_url}' => $url->fromRoute('top', [], ['force_canonical' => true]),
            '{site_title}' => $site ? $site->title() : null,
            '{site_url}' => $site ? $site->siteUrl() : null,
            '{email}' => $post['o:email'] ?? null,
            '{name}' => $post['o:name'] ?? null,
            '{message}' => $post['o:message'] ?? null,
            '{resources}' => empty($post['o:resource']) ? '' : implode(', ', array_map('intval', $post['o:resource'])),
            '{resource}' => empty($post['o:resource']) ? '' : (int) reset($post['o:resource']),
            '{session_url}' => '',
        ];

        // The session url is the resource url with an argument.
        if (strpos($string, '{session_url}') !== false && !empty($post['o:resource'])) {
            $siteSlug = $helpers->get('defaultSite')('slug');
            $accessRequest = $post['access_request'];
            // TODO Use a hash of the email with some data (created, etc.) for security.
            $tokenOrEmail = $accessRequest->token() ?: $accessRequest->email();
            $replace['{session_url}'] = $this->url()->fromRoute('site/access-request', ['site-slug' => $siteSlug], ['query' => ['access' => $tokenOrEmail], 'force_canonical' => true]);
            /* // TODO Use an event to manage direct access (store the session before processing).
             $resourceId = (int) reset($post['o:resource']);
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource * /
            try {
                $resource = $this->api()->read('resources', ['id' => $resourceId])->getContent();
            } catch (\Exception $e) {
                $resource = null;
            }
            if ($resource) {
                $accessRequest = $post['access_request'];
                $tokenOrEmail = $accessRequest->token() ?: $accessRequest->email();
                $replace['{session_url}'] = $resource->siteUrl($siteSlug, true) . '?access=' . $tokenOrEmail;
            }
            */
        }

        // Post is already checked, except fields, so they are escaped.
        foreach ($post['fields'] ?? [] as $key => $value) {
            if (!isset($replace[$key])) {
                $replace['{' . $key . '}'] = $escape($value);
            }
        }

        return strtr($string, $replace);
    }

    /**
     * Check if the requester is  a user or a visitor.
     *
     *  A visitor is not authenticated and can be identified by email or token.
     */
    protected function isVisitor(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        $modes = $this->settings()->get('access_modes');
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if (!$individualModes) {
            return false;
        }
        $user = $accessRequest->user();
        return $user === null;
    }
}
