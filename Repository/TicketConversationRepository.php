<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\TicketConversation;

class TicketConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketConversation::class);
    }

    public function findOneByConversationId(mixed $conversationId)
    {
        return $this->findOneBy(
            [
                'conversationId' => $conversationId,
            ]
        );
    }

}