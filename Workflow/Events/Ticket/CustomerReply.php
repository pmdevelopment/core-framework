<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events\Ticket;

use Webkul\UVDesk\AutomationBundle\Workflow\FunctionalGroup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webkul\UVDesk\AutomationBundle\Workflow\Event as WorkflowEvent;

class CustomerReply extends WorkflowEvent
{
    public static function getId(): string
    {
        return 'uvdesk.ticket.customer_reply';
    }

    public static function getDescription(): string
    {
        return "Customer Reply";
    }

    public static function getFunctionalGroup(): string
    {
        return FunctionalGroup::TICKET;
    }
}
