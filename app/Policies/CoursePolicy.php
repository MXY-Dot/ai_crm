<?php

namespace App\Policies;

class CoursePolicy extends TenantResourcePolicy
{
    // Курсы (каталог предложений) следуют общим правилам доступа к ресурсам тенанта, как Product/Resource.
}
