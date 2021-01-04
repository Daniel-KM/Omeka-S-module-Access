<?php declare(strict_types=1);
namespace AccessResource\Mail;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Entity\User;

class RequestMailer
{
    use ServiceLocatorAwareTrait;
    protected $config;
    protected $entityManager;
    protected $admin_user;
    protected $mailer;

    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
        $this->config = $this->getServiceLocator()->get('Config');
        $this->entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        if ($site_admin = $this->entityManager->getRepository(User::class)->findOneByRole('site_admin')) {
            $this->admin_user = $site_admin;
        } else {
            $this->admin_user = $this->entityManager->getRepository(User::class)->findOneByRole('global_admin');
        }
        $this->mailer = $this->getServiceLocator()->get('Omeka\Mailer');
    }

    /**
     * @param string $action "created" or "updated".
     */
    public function sendMailToAdmin($action): void
    {
        if (!isset($action)) {
            return;
        }

        // Mail to administrator.
        $mail = [];
        $mail['from'] = $this->admin_user->getEmail();
        $mail['fromName'] = $this->admin_user->getName();
        $mail['to'] = $this->admin_user->getEmail();
        $mail['toName'] = $this->admin_user->getName();
        if ($action === 'created') {
            $mail['subject'] = $this->config['accessresource']['settings']['accessresource_message_admin_subject'];
            $mail['body'] = $this->config['accessresource']['settings']['accessresource_message_admin_request_created'];
        } elseif ($action === 'updated') {
            $mail['subject'] = $this->config['accessresource']['settings']['accessresource_message_admin_subject'];
            $mail['body'] = $this->config['accessresource']['settings']['accessresource_message_admin_request_updated'];
        }

        $message = $this->mailer->createMessage();
        $message
            ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        $this->mailer->send($message);
    }

    /**
     * @param string $action "created" or "updated".
     */
    public function sendMailToUser($action): void
    {
        if (!isset($action)) {
            return;
        }

        // Mail to user.
        $user = $this->serviceLocator->get('Omeka\AuthenticationService')->getIdentity();
        $mail = [];
        $mail['from'] = $this->admin_user->getEmail();
        $mail['fromName'] = $this->admin_user->getName();
        $mail['to'] = $user->getEmail();
        $mail['toName'] = $user->getName();
        if ($action === 'created') {
            $mail['subject'] = $this->config['accessresource']['settings']['accessresource_message_user_subject'];
            $mail['body'] = $this->config['accessresource']['settings']['accessresource_message_user_request_created'];
        } elseif ($action === 'updated') {
            $mail['subject'] = $this->config['accessresource']['settings']['accessresource_message_user_subject'];
            $mail['body'] = $this->config['accessresource']['settings']['accessresource_message_user_request_updated'];
        }

        $message = $this->mailer->createMessage();
        $message
            ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        $this->mailer->send($message);
    }
}
