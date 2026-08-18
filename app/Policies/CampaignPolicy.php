<?php

namespace App\Policies;

class CampaignPolicy extends TenantResourcePolicy
{
    // Campaign access follows the shared tenant resource rules — create/update
    // are owner+manager only (ЭТАП 18.4's "human approves the launch").
}
