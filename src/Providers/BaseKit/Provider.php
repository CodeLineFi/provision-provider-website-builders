<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\BaseKit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\WebsiteBuilders\Category;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountIdentifier;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\ChangePackageParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountInfo;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\LoginResult;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\UnSuspendParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\BaseKit\Data\Configuration;
use Upmind\ProvisionProviders\WebsiteBuilders\Utils\Helpers;

class Provider extends Category implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var Client
     */
    protected $client;

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('BaseKit')
            ->setDescription('Create, manage and log into BaseKit site builder accounts');
    }

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function create(CreateParams $params): AccountInfo
    {
        try {
            $userRef = $this->createUser($params);
            $this->createWebsite($userRef, $params->domain_name);
            $this->setUserPackage($userRef, $params->package_reference, $params->billing_cycle_months);

            return new AccountInfo([
                'account_reference' => $userRef,
                'domain_name' => $params->domain_name,
                'package_reference' => $params->package_reference,
                'suspended' => $params->package_reference == $this->configuration->suspension_package_ref,
                'site_count' => 1,
                'storage_used' => Helpers::humanReadableFileSize(0),
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function login(AccountIdentifier $params): LoginResult
    {
        try {
            $url = $this->getLoginUrl($params->account_reference, $params->domain_name);

            return new LoginResult([
                'login_url' => $url,
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getInfo(AccountIdentifier $params): AccountInfo
    {
        try {
            return $this->getAccountInfo($params->account_reference, $params->domain_name);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            $this->setUserPackage(
                $params->account_reference,
                $params->package_reference,
                $params->billing_cycle_months
            );

            return $this->getAccountInfo($params->account_reference, $params->domain_name);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function suspend(AccountIdentifier $params): AccountInfo
    {
        try {
            $this->setUserPackage(
                $params->account_reference,
                $this->configuration->suspension_package_ref
            );

            return $this->getAccountInfo($params->account_reference, $params->domain_name);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function unSuspend(UnSuspendParams $params): AccountInfo
    {
        try {
            $this->setUserPackage(
                $params->account_reference,
                $params->package_reference,
                $params->billing_cycle_months
            );

            return $this->getAccountInfo($params->account_reference, $params->domain_name);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function terminate(AccountIdentifier $params): ResultData
    {
        try {
            $this->terminateUser($params->account_reference);

            return $this->okResult('Account Terminated');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @param int $userReference
     * @param string $domainName
     */
    public function getAccountInfo($userReference, string $domainName): AccountInfo
    {
        $userData = $this->getUserData($userReference);
        $packageRef = $userData->subscriptionPackageRef;
        $storageBytesUsed = intval($userData->storageBytesUsed);

        $sitesData = $this->getUserSitesData($userReference);
        $numSites = count($sitesData);

        return AccountInfo::create([
            'account_reference' => $userReference,
            'domain_name' => $domainName,
            'package_reference' => $packageRef,
            'suspended' => $packageRef == $this->configuration->suspension_package_ref,
            'site_count' => $numSites,
            'storage_used' => Helpers::humanReadableFileSize($storageBytesUsed, 0),
        ]);
    }

    /**
     * @param int $userReference
     *
     * @return stdClass
     */
    public function getUserData($userReference): stdClass
    {
        $response = $this->client()->get('/users/' . $userReference);
        $userData = $this->getResponseData($response)->accountHolder;

        if ($userData->deleted) {
            throw $this->errorResult('User is deleted', [], ['user_data' => $userData]);
        }

        return $userData;
    }

    /**
     * @param int $userReference
     *
     * @return stdClass[]
     */
    public function getUserSitesData($userReference): array
    {
        $response = $this->client()->get(sprintf('/users/%s/sites', $userReference));
        return $this->getResponseData($response)->sites;
    }

    /**
     * Create a user and return the new user account reference.
     */
    public function createUser(CreateParams $params): int
    {
        @[$firstName, $lastName] = explode(' ', $params->customer_name, 2);

        $response = $this->client()->post('/users', [
            RequestOptions::JSON => [
                'brandRef' => $this->configuration->brand_ref,
                'username' => $this->getRandomUsername($firstName, $lastName),
                'email' => $params->customer_email,
                'password' => $params->password ?? $this->getRandomPassword(),
                'firstName' => $firstName,
                'lastName' => $lastName ?? 'UNKNOWN',
                'languageCode' => $params->language_code,
                'metadata' => [
                    'upmind_client_id' => $params->customer_id,
                ]
            ],
        ]);

        return $this->getResponseData($response)->accountHolder->ref; // user ref
    }

    /**
     * @param int $userReference
     * @param string $domainName
     */
    public function createWebsite($userReference, string $domainName): int
    {
        $response = $this->client()->post('/sites', [
            RequestOptions::JSON => [
                'brandRef' => $this->configuration->brand_ref,
                'accountHolderRef' => $userReference,
                'domain' => $domainName,
                // 'subdomain' => str_replace('.', '-', $domainName)
            ],
        ]);

        return $this->getResponseData($response)->site->ref; // site ref
    }

    /**
     * @param int $userReference
     * @param int $packageReference
     * @param int|null $billingCycleMonths
     */
    public function setUserPackage($userReference, $packageReference, ?int $billingCycleMonths = null)
    {
        $this->client()->post(sprintf('/users/%s/account-packages', $userReference), [
            RequestOptions::JSON => [
                'packageRef' => $packageReference,
                'billingFrequency' => $billingCycleMonths,
            ],
        ]);
    }

    /**
     * @param int $userReference
     * @param string $domainName
     */
    public function getLoginUrl($userReference, string $domainName): string
    {
        $response = $this->client()->post(sprintf('/users/%s/auto-login', $userReference), [
            RequestOptions::JSON => [
                'siteRef' => $this->getDomainSiteReference($userReference, $domainName),
            ],
        ]);
        $data = $this->getResponseData($response);

        if (!empty($data->flowUrl)) {
            return $data->flowUrl;
        }

        $hash = $data->hash;

        return Str::replaceFirst('rest', 'flow', $this->configuration->api_url)
            . '/login?' . http_build_query(compact('hash'));
    }

    /**
     * @param int $userReference
     * @param string $domainName
     */
    public function getDomainSiteReference($userReference, string $domainName): int
    {
        $sitesData = $this->getUserSitesData($userReference);

        foreach ($sitesData as $site) {
            if (strcasecmp($domainName, $site->primaryDomain->domainName) === 0) {
                return $site->ref;
            }
        }

        return $sitesData[0]->ref ?? $this->errorResult('User has no sites to login to');
    }

    public function terminateUser($userReference): void
    {
        // suspend
        $this->setUserPackage($userReference, $this->configuration->suspension_package_ref);

        foreach ($this->getUserSitesData($userReference) as $site) {
            // un-map domains
            // foreach ($site->domains as $domainRef => $domain) {
            //     $this->client()->delete(sprintf('/sites/%s/domains/%s', $site->ref, $domainRef));
            // }

            $this->client()->delete('/sites/' . $site->ref);
        }

        $this->client()->delete('/users/' . $userReference);
    }

    /**
     * @throws ProvisionFunctionError
     */
    public function getResponseData(ResponseInterface $response): stdClass
    {
        return json_decode($response->getBody()->__toString());
    }

    public function getRandomPassword(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * @param string $firstName
     * @param string|null $lastName
     *
     * @return string
     */
    public function getRandomUsername($firstName, $lastName): string
    {
        $username = $lastName ? (substr($firstName, 0, 1) . $lastName) : $firstName;
        $username .= str_pad(strval(rand(0, 99)), 2, '0', STR_PAD_LEFT);

        return str_replace(' ', '', strtolower($username));
    }

    /**
     * @return no-return
     *
     * @throws ProvisionFunctionError
     */
    public function handleException(Throwable $e): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $httpCode = $response->getStatusCode();
                $data = $this->getResponseData($response);

                $statusCode = $data->status ?? $data->code ?? $httpCode;
                if (!preg_match('/^2\d\d$/', strval($statusCode))) {
                    // an error occurred!
                    $message = $data->message;

                    if (!empty($data->errors)) {
                        $errorMessages = [];
                        foreach ($data->errors as $property => $errors) {
                            foreach ($errors as $type => $errorMessage) {
                                $errorMessages[] = $errorMessage;
                            }
                        }

                        if ($errorMessages) {
                            $message .= '; ' . implode(', ', $errorMessages);
                        }
                    }

                    throw $this->errorResult(
                        'Provider API Error: ' . $message,
                        [
                            'status_code' => $statusCode,
                            'errors' => $data->errors ?? [],
                        ],
                        ['response_data' => $data],
                        $e
                    );
                }

                $message = $httpCode . ' ' . $response->getReasonPhrase();

                throw $this->errorResult(
                    'Provider API Error: ' . $message,
                    ['status_code' => $statusCode],
                    ['response_data' => $data],
                    $e
                );
            }
        }

        if ($e instanceof ConnectException) {
            throw $this->errorResult('Provider API Connection Error', [], [], $e);
        }

        throw $e;
    }

    public function client(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => $this->configuration->api_url,
            RequestOptions::AUTH => [$this->configuration->username, $this->configuration->password],
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'upmind/provision-provider-website-builders v1.0'
            ],
        ]);
    }
}
