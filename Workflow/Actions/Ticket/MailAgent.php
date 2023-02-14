<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Workflow\Actions\Ticket;

use Webkul\UVDesk\AutomationBundle\Workflow\FunctionalGroup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webkul\UVDesk\AutomationBundle\Workflow\Action as WorkflowAction;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Ticket;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\EmailTemplates;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Thread;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\Attachment;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;

class MailAgent extends WorkflowAction
{
    public static function getId(): string
    {
        return 'uvdesk.ticket.mail_agent';
    }

    public static function getDescription(): string
    {
        return "Mail to agent";
    }

    public static function getFunctionalGroup(): string
    {
        return FunctionalGroup::TICKET;
    }

    public static function getOptions(ContainerInterface $container): array
    {
        $entityManager = $container->get('doctrine.orm.entity_manager');

        $emailTemplateCollection = array_map(function ($emailTemplate) {
            return [
                'id'   => $emailTemplate->getId(),
                'name' => $emailTemplate->getName(),
            ];
        }, $entityManager->getRepository(EmailTemplates::class)->findAll());

        $agentCollection = array_map(function ($agent) {
            return [
                'id'   => $agent['id'],
                'name' => $agent['name'],
            ];
        }, $container->get('user.service')->getAgentPartialDataCollection());

        array_unshift($agentCollection, [
            'id'   => 'responsePerforming',
            'name' => 'Response Performing Agent',
        ],            [
                          'id'   => 'assignedAgent',
                          'name' => 'Assigned Agent',
                      ]);

        return [
            'partResults' => $agentCollection,
            'templates'   => $emailTemplateCollection,
        ];
    }

    public static function applyAction(ContainerInterface $container, $entity, $value = null, $thread = null): void
    {
        $entityManager = $container->get('doctrine.orm.entity_manager');

        if (false === ($entity instanceof Ticket)) {
            return;
        }

        $emailTemplate = $entityManager->getRepository(EmailTemplates::class)->findOneById($value['value']);
        if (null === $emailTemplate) {
            return;
        }

        $emails = self::getAgentMails($value['for'], (($ticketAgent = $entity->getAgent()) ? $ticketAgent->getEmail() : ''), $container);
        if (0 === count($emails)) {
            return;
        }

        $ticketCollaborators = [];
        $emailHeaders = [];

        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('th.messageId as messageId')
            ->from(Thread::class, 'th')
            ->where('th.createdBy = :userType')->setParameter('userType', 'agent')
            ->orderBy('th.id', 'DESC')
            ->setMaxResults(1);

        /** @var Thread|null $inReplyTo */
        $inReplyTo = $queryBuilder->getQuery()->getOneOrNullResult();

        if (false === empty($inReplyTo)) {
            $emailHeaders['In-Reply-To'] = $inReplyTo;
        }

        if (false === empty($entity->getReferenceIds())) {
            $emailHeaders['References'] = $entity->getReferenceIds();
        }

        if ($thread == null) {
            $ticketId = $entity->getId();
            $thread = $container->get('ticket.service')->getInitialThread($ticketId);
        }

        // Only process attachments if required in the message body
        // @TODO: Revist -> Maybe we should always include attachments if they are provided??
        $createdThread = isset($entity->createdThread) && $entity->createdThread->getThreadType() != "note" ? $entity->createdThread : (isset($entity->currentThread) ? $entity->currentThread : "");

        $placeHolderValues = $container->get('email.service')->getTicketPlaceholderValues($entity, 'agent');
        $subject = $container->get('email.service')->processEmailSubject($emailTemplate->getSubject(), $placeHolderValues);
        $message = $container->get('email.service')->processEmailContent($emailTemplate->getMessage(), $placeHolderValues);
        $thread = ($thread != null) ? $thread : $createdThread;
        if ($thread != null && $thread->getThreadType() == "reply" && $thread->getCreatedBy() != "collaborator") {
            $ticketCollaborators = (($thread != null) && !empty($thread->getTicket()) && $thread != "") ? $thread->getTicket()->getCollaborators() : [];
        }

        foreach ($emails as $email) {
            if (true === is_array($email)) {
                $email = $email['email'];
            }

            $messageId = $container->get('email.service')->sendMail($subject, $message, $email, $emailHeaders, null, $attachments ?? []);

            if (!empty($messageId)) {
                $updatedReferenceIds = $entity->getReferenceIds() . ' ' . $messageId;
                $entity->setReferenceIds($updatedReferenceIds);

                $entityManager->persist($entity);
                $entityManager->flush();
            }
        }

        if (!empty($thread) && ($thread->getCc() || $thread->getBcc()) || $ticketCollaborators != null && count($ticketCollaborators) > 0) {
            self::sendCcBccMail($container, $entity, $thread, $subject, $attachments, $ticketCollaborators, $message);
        }
    }

    public static function getAgentMails($for, $currentEmails, $container)
    {
        $agentMails = [];
        $entityManager = $container->get('doctrine.orm.entity_manager');

        foreach ($for as $agent) {
            if ($agent == 'assignedAgent') {
                if (is_array($currentEmails)) {
                    $agentMails = array_merge($agentMails, $currentEmails);
                } else {
                    $agentMails[] = $currentEmails;
                }
            } else {
                if ($agent == 'responsePerforming' && is_object($currentUser = $container->get('security.token_storage')->getToken()->getUser())) {
                    // Add current user email if any
                    $agentMails[] = $currentUser->getEmail();
                } else {
                    if ($agent == 'baseAgent') {
                        // Add selected user email if any
                        if (is_array($currentEmails)) {
                            $agentMails = array_merge($agentMails, $currentEmails);
                        } else {
                            $agentMails[] = $currentEmails;
                        }
                    } else {
                        if ((int)$agent) {
                            $qb = $entityManager->createQueryBuilder();
                            $emails = $qb->select('u.email')->from(User::class, 'u')
                                ->andwhere("u.id = :userId")
                                ->setParameter('userId', $agent)
                                ->getQuery()->getResult();

                            foreach ($emails as $email) {
                                $agent = $entityManager->getRepository(User::class)->findOneBy($email);
                                if ($agent != null && $agent->getAgentInstance() != null) {
                                    $agentMails[] = $email;
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_filter($agentMails);
    }

    public static function sendCcBccMail($container, $entity, $thread, $subject, $attachments, $ticketCollaborators, $message = null)
    {
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $collabrator = [];
        $cc = [];

        if ($thread->getCc() != null) {
            foreach ($thread->getCc() as $EmailCC) {
                if ($entityManager->getRepository(Ticket::class)->isTicketCollaborator($thread->getTicket(), $EmailCC) != false) {
                    $collabrator[] = $EmailCC;
                } else {
                    $cc[] = $EmailCC;
                }
            }
        }

        $emailOfcollabrator = !empty($thread) && $thread->getCreatedBy() == "collaborator" ? $thread->getUser()->getEmail() : null;

        if ($collabrator != null && !empty($collabrator) || $ticketCollaborators != null && !empty($ticketCollaborators)) {
            if (count($collabrator) == 0 && count($ticketCollaborators) > 0 && !empty($ticketCollaborators) && empty($collabrator)) {
                foreach ($ticketCollaborators as $collaborator) {
                    if (!empty($collaborator->getEmail()) && $collaborator->getEmail() != $emailOfcollabrator) {
                        $collabrator[] = $collaborator->getEmail();
                    }
                }
            }

            $messageId = $container->get('email.service')->sendMail($subject, $message, null, [], $entity->getMailboxEmail(), $attachments ?? [], $collabrator ?? [], []);
            if (!empty($messageId)) {
                $updatedReferenceIds = $entity->getReferenceIds() . ' ' . $messageId;
                $entity->setReferenceIds($updatedReferenceIds);

                $entityManager->persist($entity);
                $entityManager->flush();
            }

            if ($thread->getCc() != null && count($thread->getCc()) == count($collabrator) && $thread->getBcc() != null) {
                $message = '<html><body style="background-image: none"><p>' . html_entity_decode($thread->getMessage()) . '</p></body></html>';
                $messageId = $container->get('email.service')->sendMail($subject, $message, null, [], $entity->getMailboxEmail(), $attachments ?? [], [], $thread->getBcc() ?? []);
            }
        }

        if ($cc != null && !empty($cc)) {
            $message = '<html><body style="background-image: none"><p>' . html_entity_decode($thread->getMessage()) . '</p></body></html>';
            $messageId = $container->get('email.service')->sendMail($subject, $message, null, [], $entity->getMailboxEmail(), $attachments ?? [], $cc ?? [], $thread->getBcc() ?? []);
        }

        if ($thread->getBcc() != null && $thread->getCc() == null) {
            $message = '<html><body style="background-image: none"><p>' . html_entity_decode($thread->getMessage()) . '</p></body></html>';
            $messageId = $container->get('email.service')->sendMail($subject, $message, null, [], $entity->getMailboxEmail(), $attachments ?? [], $thread->getCc() ?? [], $thread->getBcc() ?? []);
        }
    }
}