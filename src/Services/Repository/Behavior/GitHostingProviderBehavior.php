<?php

namespace App\Services\Repository\Behavior;

use App\Entity\LanguageNameEntity;
use App\Entity\RepositoryEntity;
use App\Entity\TranslationUpdateEntity;
use App\Services\Git\GitAddException;
use App\Services\Git\GitBranchException;
use App\Services\Git\GitCheckoutException;
use App\Services\Git\GitNoRemoteException;
use App\Services\Git\GitPullException;
use App\Services\Git\GitPushException;
use App\Services\Git\GitRepository;
use App\Services\GitHostingProviderService;
use App\Services\GitHostingProviderStatusService;

/**
 * Generic Git Hosting Provider Behavior.
 *
 * @see GitHubBehavior, GitLabBehavior
 */
abstract class GitHostingProviderBehavior implements RepositoryBehavior
{
    /**
     * Git Service api.
     */
    protected GitHostingProviderService $api;

    /**
     * Git Service status.
     */
    protected GitHostingProviderStatusService $status;

    /**
     * Git repository remote name
     */
    protected string $remote;


    public function __construct(GitHostingProviderService $api, GitHostingProviderStatusService $statusService)
    {
        $this->api = $api;
        $this->status = $statusService;
    }

    /**
     * Create branch and push it to remote, then submit a pull request
     *
     * @param GitRepository $tempGit temporary local git repository with the patch of the language update
     * @param TranslationUpdateEntity $update
     * @param GitRepository $forkedGit git repository cloned of the forked repository
     *
     * @throws GitAddException
     * @throws GitBranchException
     * @throws GitCheckoutException
     * @throws GitNoRemoteException
     * @throws GitPushException
     */
    public function sendChange(GitRepository $tempGit, TranslationUpdateEntity $update, GitRepository $forkedGit): void
    {
        $remoteUrl = $forkedGit->getRemoteUrl();
        $tempGit->remoteAdd($this->remote, $remoteUrl);
        $branchName = 'lang_update_' . $update->getId() . '_' . $update->getUpdated();
        $tempGit->branch($branchName);
        $tempGit->checkout($branchName);

        $tempGit->push($this->remote, $branchName);

        $this->api->createPullRequest(
            $branchName,
            $update->getRepository()->getBranch(),
            $update->getLanguage(),
            $update->getRepository()->getUrl(),
            $remoteUrl
        );
    }

    /**
     * Fork original repo and return the fork's url.
     *
     * @param RepositoryEntity $repository
     * @return string Git clone URL of the fork
     */
    public function createOriginURL(RepositoryEntity $repository): string
    {
        return $this->api->createFork($repository->getUrl());
    }

    /**
     * Remove the fork.
     *
     * @param GitRepository $forkedGit git repository cloned of the forked repository
     *
     * @throws GitNoRemoteException
     */
    public function removeRemoteFork(GitRepository $forkedGit): void
    {
        $remoteUrl = $forkedGit->getRemoteUrl();
        $this->api->deleteFork($remoteUrl);
    }

    /**
     * Update from original and push to fork of translate tool
     *
     * @param GitRepository $forkedGit git repository cloned of the forked repository
     * @param RepositoryEntity $repository
     * @return bool true if the repository is changed
     *
     * @throws GitPullException
     * @throws GitPushException
     */
    public function pull(GitRepository $forkedGit, RepositoryEntity $repository): bool
    {
        $changed = $forkedGit->pull($repository->getUrl(), $repository->getBranch()) === GitRepository::PULL_CHANGED;
        $forkedGit->push('origin', $repository->getBranch());
        return $changed;
    }

    /**
     * Update from original and push to fork of translate tool (assumes there are no local changes)
     *
     * @param GitRepository $forkedGit git repository cloned of the forked repository
     * @param RepositoryEntity $repository
     * @return bool true if the repository is changed
     *
     * @throws GitPullException
     * @throws GitPushException
     */
    public function reset(GitRepository $forkedGit, RepositoryEntity $repository): bool
    {
        $changed = $forkedGit->reset($repository->getUrl(), $repository->getBranch()) === GitRepository::PULL_CHANGED;
        $forkedGit->push('origin', $repository->getBranch());
        return $changed;
    }

    /**
     * Check if Git Hosting provider is functional.
     *
     * @return bool
     */
    public function isFunctional(): bool
    {
        return $this->status->isFunctional();
    }

    /**
     * Get information about the open pull requests i.e. url and count
     *
     * @param RepositoryEntity $repository
     * @param LanguageNameEntity $language
     * @return array{count: int, listURL: string, title: string}
     */
    public function getOpenPRListInfo(RepositoryEntity $repository, LanguageNameEntity $language): array
    {
        return $this->api->getOpenPRListInfo($repository->getUrl(), $language->getCode());
    }

}
