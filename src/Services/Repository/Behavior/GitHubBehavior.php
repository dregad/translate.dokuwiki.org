<?php

namespace App\Services\Repository\Behavior;

class GitHubBehavior extends GitHostingProviderBehavior
{
    protected string $remote = 'github';
}
