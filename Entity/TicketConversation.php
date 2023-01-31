<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webkul\UVDesk\CoreFrameworkBundle\Repository\TicketConversationRepository;

/**
 * @ORM\Entity(repositoryClass=TicketConversationRepository::class)
 * @ORM\Table(name="uv_ticket_conversation", indexes={@ORM\Index("uv_ti_co_id", columns={"conversation_id"})})
 */
class TicketConversation
{
    /**
     * @ORM\Id()
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Ticket")
     * @ORM\JoinColumn(name="ticket_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private Ticket $ticket;

    /**
     * @ORM\Column(type="string")
     */
    private string $conversationId = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function setTicket(Ticket $ticket): TicketConversation
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function setConversationId(string $conversationId): TicketConversation
    {
        $this->conversationId = $conversationId;

        return $this;
    }

}