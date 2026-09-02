<?php

namespace App\Policies;

class IntegrationApiKeyPolicy extends TenantResourcePolicy
{
    // Issuing/revoking API credentials is owner/manager-only, same
    // sensitivity tier as billing/settings -- deliberately NOT extended to
    // operator the way every business module's own operational entities
    // (bookings, shipments, enrollments...) were this session, since a
    // leaked or over-issued key is a real security exposure, not a workflow
    // convenience.
}
