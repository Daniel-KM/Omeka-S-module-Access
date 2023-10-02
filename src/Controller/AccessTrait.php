<?php declare(strict_types=1);

namespace Access\Controller;

use Access\Api\Representation\AccessRequestRepresentation;
use Omeka\Stdlib\Message;

trait AccessTrait
{
    /**
     * Send email to admin/user when a request is created.
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
        $isVisitor = $this->isVisitor($accessRequest, $post);
        $result1 = $this->sendMailToAdmin('created', $post);
        $result2 = $this->sendMailToUser('created', $post, $isVisitor);
        return $result1 && $result2;
    }

    /**
     * Send email to admin/user when a request is updated.
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
        $isVisitor = $this->isVisitor($accessRequest, $post);
        // $this->sendMailToAdmin('updated', $post);
        $result = $this->sendMailToUser('updated', $post, $isVisitor);
        return $result;
    }

    /**
     * Send a mail to administrator
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToAdmin(?string $action, array $post): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleConfig = require dirname(__DIR__, 2) . '/config/module.config.php';

        $adminEmail = $settings->get('administrator_email');

        // Mail to administrator.
        // Sender: Don't use user mail: the server may not be able to send it.
        // See mailer.
        $mail = [];
        $mail['from'] = $adminEmail;
        $mail['fromName'] = null;
        $mail['to'] = $adminEmail;
        $mail['toName'] = null;
        // $mail['toName'] = 'Omeka S Admin';
        if ($action === 'created') {
            $mail['subject'] = $settings->get('access_message_admin_subject', $this->translate($moduleConfig['access']['settings']['access_message_admin_subject']));
            $mail['body'] = $settings->get('access_message_admin_request_created', $this->translate($moduleConfig['access']['settings']['access_message_admin_request_created']));
        } elseif ($action === 'updated') {
            $mail['subject'] = $settings->get('access_message_admin_subject', $this->translate($moduleConfig['access']['settings']['access_message_admin_subject']));
            $mail['body'] = $settings->get('access_message_admin_request_updated', $this->translate($moduleConfig['access']['settings']['access_message_admin_request_updated']));
        }

        $mail['body'] = $this->replaceText($mail['body'], $post);

        /** @var \Omeka\Stdlib\Mailer $mailer */
        $mailer = $this->mailer();
        $message = $mailer->createMessage();
        $message
            // ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        try {
            $mailer->send($message);
            return true;
        } catch (\Exception $e) {
            $msg = new Message(
                "Unable to send message to admin. Message:\n%s",  // @translate
                $mail['subject'] . "\n" . $mail['body']
            );
            $this->logger()->err((string) $msg);
            return false;
        }
    }

    /**
     * Send a email to user or visitor.
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToUser(?string $action, array $post, bool $isVisitor): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleConfig = require dirname(__DIR__, 2) . '/config/module.config.php';

        $adminEmail = $settings->get('administrator_email');

        // Messages are not the same for users and visitors.
        $userVisitor = $isVisitor ? 'visitor' : 'user';

        // Mail to user.
        // Sender: Don't use user mail: the server may not be able to send it.
        // See mailer.
        $mail = [];
        $mail['from'] = $adminEmail;
        $mail['fromName'] = null;
        $mail['to'] = $post['o:email'];
        $mail['toName'] = $post['o:name'] ?? null;
        if ($action === 'created') {
            $mail['subject'] = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_subject"]));
            $mail['body'] = $settings->get("access_message_{$userVisitor}_request_created", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_request_created"]));
        } elseif ($action === 'updated') {
            $mail['subject'] = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_subject"]));
            $mail['body'] = $settings->get("access_message_{$userVisitor}_request_updated", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_request_updated"]));
        }

        $mail['body'] = $this->replaceText($mail['body'], $post);

        /** @var \Omeka\Stdlib\Mailer $mailer */
        $mailer = $this->mailer();
        $message = $mailer->createMessage();
        $message
            // ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        try {
            $mailer->send($message);
            return true;
        } catch (\Exception $e) {
            $msg = new Message(
                'Unable to send message from %1\$s to admin. Message:\n%2\$s',  // @translate
                sprintf('%1$s (%2$s)', $mail['toName'], $mail['to']), $mail['subject'] . "\n" . $mail['body']
            );
            $this->logger()->err((string) $msg);
            return false;
        }
    }

    protected function replaceText(string $string, array $post): string
    {
        $plugins = $this->getPluginManager();
        $url = $plugins->get('url');
        $helpers = $this->viewHelpers();
        $escape = $helpers->get('escapeHtml');

        try {
            $site = $plugins->get('currentSite')();
        } catch (\Exception $e) {
            $site = null;
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
        ];

        // Post is already checked, except fields, so they are escaped.
        foreach ($post['fields'] ?? [] as $key => $value) {
            if (!isset($replace[$key])) {
                $replace['{' . $key . '}'] = $escape($value);
            }
        }

        return str_replace(array_keys($replace), array_values($replace), $string);
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
