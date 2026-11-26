<?php

namespace App\Enums;

enum InvitationStatus
{
    case Pending;
    case Redeemed;
    case Withdrawn;
    case Expired;
}
