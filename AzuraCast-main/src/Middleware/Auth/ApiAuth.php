<?php

declare(strict_types=1);

namespace App\Middleware\Auth;

use App\Acl;
use App\Auth;
use App\Entity;
use App\Environment;
use App\Exception\CsrfValidationException;
use App\Http\ServerRequest;
use App\Session\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiAuth extends AbstractAuth
{
    public const API_CSRF_NAMESPACE = 'api';

    public function __construct(
        protected Entity\Repository\UserRepository $userRepo,
        protected Entity\Repository\ApiKeyRepository $apiKeyRepo,
        Entity\Repository\SettingsRepository $settingsRepo,
        Environment $environment,
        Acl $acl
    ) {
        parent::__construct($settingsRepo, $environment, $acl);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Initialize the Auth for this request.
        $user = $this->getApiUser($request);

        $request = $request->withAttribute(ServerRequest::ATTR_USER, $user);

        return parent::process($request, $handler);
    }

    protected function getApiUser(ServerRequestInterface $request): ?Entity\User
    {
        $apiKey = $this->getApiKey($request);

        if (!empty($apiKey)) {
            $apiUser = $this->apiKeyRepo->authenticate($apiKey);
            if (null !== $apiUser) {
                return $apiUser;
            }
        }

        // Fallback to session login if available.
        $auth = new Auth(
            userRepo:    $this->userRepo,
            session:     $request->getAttribute(ServerRequest::ATTR_SESSION),
            environment: $this->environment,
        );

        if ($auth->isLoggedIn()) {
            $user = $auth->getLoggedInUser();
            if ('GET' === $request->getMethod()) {
                return $user;
            }

            $csrfKey = $request->getHeaderLine('X-API-CSRF');
            if (empty($csrfKey) && !$this->environment->isTesting()) {
                return null;
            }

            $csrf = $request->getAttribute(ServerRequest::ATTR_SESSION_CSRF);

            if ($csrf instanceof Csrf) {
                try {
                    $csrf->verify($csrfKey, self::API_CSRF_NAMESPACE);
                    return $user;
                } catch (CsrfValidationException) {
                }
            }
        }

        return null;
    }

    protected function getApiKey(ServerRequestInterface $request): ?string
    {
        // Check authorization header
        $auth_headers = $request->getHeader('Authorization');
        $auth_header = $auth_headers[0] ?? '';

        if (preg_match("/Bearer\s+(.*)$/i", $auth_header, $matches)) {
            return $matches[1];
        }

        // Check API key header
        $api_key_headers = $request->getHeader('X-API-Key');
        if (!empty($api_key_headers[0])) {
            return $api_key_headers[0];
        }

        // Check cookies
        $cookieParams = $request->getCookieParams();
        if (!empty($cookieParams['token'])) {
            return $cookieParams['token'];
        }

        // Check URL parameters as last resort
        $queryParams = $request->getQueryParams();
        $queryApiKey = $queryParams['api_key'] ?? null;
        if (!empty($queryApiKey)) {
            return $queryApiKey;
        }

        return null;
    }
}
