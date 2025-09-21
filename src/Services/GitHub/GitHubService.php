<?php

namespace App\Services\GitHub;

use App\Services\GitHostingProviderService;
use Exception;
use Github\AuthMethod;
use Github\Client;
use Github\Exception\MissingArgumentException;
use Github\Exception\RuntimeException;
use Symfony\Component\HttpClient\HttplugClient;


class GitHubService extends GitHostingProviderService
{
    const REGEX_REPO_USER = '#^(https://github.com/|git@.*?github.com:|git://github.com/)(.*)\.git$#';

    protected string $provider = 'GitHub';
    protected string $exceptionType = GitHubServiceException::class;

    /**
     * @var Client
     */
    protected $client;


    public function __construct(string $apiToken, string $dataFolder, string $url, bool $autoStartup = true)
    {
        parent::__construct($apiToken, $dataFolder, $url, $autoStartup);
        if (!$autoStartup) {
            return;
        }

        $this->client = Client::createWithHttpClient(
            new HttplugClient()
        );

        $this->client->addCache($this->getCachePool($dataFolder));
        $this->client->authenticate($apiToken, null, AuthMethod::ACCESS_TOKEN);
    }

    /**
     * @inheritDoc
     *
     * @throws GitHubForkException
     * @throws GitHubServiceException
     */
    public function createFork(string $url): string
    {
        [$user, $repository] = $this->getUsernameAndRepositoryFromURL($url);
        try {
            $result = $this->client->api('repo')->forks()->create($user, $repository);
        } catch (RuntimeException $e) {
            throw new GitHubForkException($e->getMessage() . " $user/$repository", 0, $e);
        }
        return $this->gitHubUrlHack($result['ssh_url']);
    }

    /**
     * @inheritDoc
     *
     * @throws GitHubServiceException
     */
    public function deleteFork(string $remoteUrl): void
    {
        [$user, $repository] = $this->getUsernameAndRepositoryFromURL($remoteUrl);
        try {
            $this->client->api('repo')->remove($user, $repository);
        } catch (RuntimeException $e) {
            throw new GitHubServiceException($e->getMessage() . " $user/$repository", 0, $e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws GitHubCreatePullRequestException
     * @throws GitHubServiceException
     * @throws MissingArgumentException
     */
    public function createPullRequest(string $patchBranch, string $destinationBranch, string $languageCode, string $url, string $patchUrl): void
    {
        [$user, $repository] = $this->getUsernameAndRepositoryFromURL($url);
        [$repoName, ] = $this->getUsernameAndRepositoryFromURL($patchUrl);

        try {
            $this->client->api('pull_request')->create($user, $repository, [
                'base' => $destinationBranch,
                'head' => $repoName . ':' . $patchBranch,
                'title' => 'Translation update (' . $languageCode . ')',
                'body' => 'This pull request contains some translation updates.'
            ]);
        } catch (RuntimeException $e) {
            throw new GitHubCreatePullRequestException($e->getMessage() . " $user/$repository", 0, $e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws GitHubServiceException
     * @throws Exception only if in 'test' environment
     */
    public function getOpenPRListInfo(string $url, string $languageCode): array
    {
        [$user, $repository] = $this->getUsernameAndRepositoryFromURL($url);

        $info = [
            'listURL' => '',
            'title' => '',
            'count' => 0
        ];

        try {
            $q = 'Translation update (' . $languageCode . ') in:title repo:' . $user . '/' . $repository . ' type:pr state:open';
            $results = $this->client->api('search')->issues($q);

            $info = [
                'listURL' => 'https://github.com/' . $user . '/' . $repository . '/pulls?q=is%3Apr+is%3Aopen+Translation+update+%28' . $languageCode . '%29',
                'title' => 'GitHub',
                'count' => (int)$results['total_count']
            ];
        } catch (Exception $e) {
            // skip intentionally, shown only for testing
            if ($_ENV['APP_ENV'] === 'test') {
                throw $e;
            }
        }

        return $info;
    }

    /**
     * @param string $url git clone url
     * @return string modified git clone url
     */
    private function gitHubUrlHack(string $url): string
    {
        if ($this->url === 'github.com') {
            return $url;
        }
        return str_replace('github.com', $this->url, $url);
    }
}
