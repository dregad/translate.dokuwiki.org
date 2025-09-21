<?php

namespace App\Services\Repository\Behavior;

class GitLabBehavior extends GitHostingProviderBehavior
{
    protected string $remote = 'gitlab';
}