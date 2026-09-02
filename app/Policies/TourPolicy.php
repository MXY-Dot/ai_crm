<?php

namespace App\Policies;

class TourPolicy extends TenantResourcePolicy
{
    // Туры (каталог предложений) следуют общим правилам доступа к ресурсам тенанта, как Product/Course.
}
