<?php

declare(strict_types=1);

namespace Prooph\HttpEventStore\UserManagement\ClientOperations;

use Http\Client\HttpClient;
use Http\Message\RequestFactory;
use Http\Message\UriFactory;
use Prooph\EventStore\Exception\AccessDenied;
use Prooph\EventStore\UserCredentials;
use Prooph\EventStore\UserManagement\UserNotFound;
use Prooph\HttpEventStore\ClientOperations\Operation;
use Prooph\HttpEventStore\Http\RequestMethod;

/** @internal */
class UpdateUserOperation extends Operation
{
    /** @var string */
    private $login;
    /** @var string */
    private $fullName;
    /** @var string[] */
    private $groups;

    public function __construct(
        HttpClient $httpClient,
        RequestFactory $requestFactory,
        UriFactory $uriFactory,
        string $baseUri,
        string $login,
        string $fullName,
        array $groups,
        ?UserCredentials $userCredentials
    ) {
        parent::__construct($httpClient, $requestFactory, $uriFactory, $baseUri, $userCredentials);

        $this->login = $login;
        $this->fullName = $fullName;
        $this->groups = $groups;
    }

    public function __invoke(): void
    {
        $request = $this->requestFactory->createRequest(
            RequestMethod::Put,
            $this->uriFactory->createUri($this->baseUri . '/users/' . urlencode($this->login)),
            [
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'fullName' => $this->fullName,
                'groups' => $this->groups,
            ])
        );

        $response = $this->sendRequest($request);

        switch ($response->getStatusCode()) {
            case 200:
                return;
            case 401:
                throw AccessDenied::toUserManagementOperation();
            case 404:
                throw new UserNotFound();
            default:
                throw new \UnexpectedValueException('Unexpected status code ' . $response->getStatusCode() . ' returned');
        }
    }
}
