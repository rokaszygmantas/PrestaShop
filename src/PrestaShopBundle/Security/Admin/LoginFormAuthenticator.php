<?php
/**
 * 2007-2019 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShopBundle\Security\Admin;

use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use PrestaShop\PrestaShop\Core\Domain\Employee\Query\GetEmployeeForAuthentication;
use PrestaShop\PrestaShop\Core\Domain\Employee\QueryResult\EmployeeForAuthentication;
use PrestaShop\PrestaShop\Core\Security\EmployeeAuthenticationHandlerInterface;
use PrestaShopBundle\Form\Admin\Login\LoginType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Responsible for authenticating the admin user using the login form.
 */
final class LoginFormAuthenticator extends AbstractFormLoginAuthenticator
{
    /**
     * @var Hashing
     */
    private $hashing;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var CommandBusInterface
     */
    private $queryBus;

    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * @var EmployeeAuthenticationHandlerInterface
     */
    private $authenticationHandler;

    /**
     * @param Hashing $hashing
     * @param RouterInterface $router
     * @param CommandBusInterface $queryBus
     * @param LoggerInterface $logger
     * @param TranslatorInterface $translator
     * @param FormFactoryInterface $formFactory
     * @param EmployeeAuthenticationHandlerInterface $authenticationHandler
     */
    public function __construct(
        Hashing $hashing,
        RouterInterface $router,
        CommandBusInterface $queryBus,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        FormFactoryInterface $formFactory,
        EmployeeAuthenticationHandlerInterface $authenticationHandler
    ) {
        $this->hashing = $hashing;
        $this->router = $router;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->queryBus = $queryBus;
        $this->formFactory = $formFactory;
        $this->authenticationHandler = $authenticationHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request)
    {
        // only authenticate on login route
        return $this->getLoginRoute() === $request->attributes->get('_route')
            && $request->isMethod('POST')
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials(Request $request)
    {
        $form = $this->formFactory->create(LoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $credentials = $form->getData();
        }

        return [
            'username' => $credentials['email'] ?? null,
            'password' => $credentials['password'] ?? null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        if (null === $credentials['username']) {
            return null;
        }

        return $userProvider->loadUserByUsername($credentials['username']);
    }

    /**
     * {@inheritdoc}
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        return $this->hashing->checkHash($credentials['password'], $user->getPassword());
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $this->logger->info(
            $this->translator->trans(
                'Back office connection from %ip%',
                ['%ip%' => $request->getClientIp()],
                'Admin.Advparameters.Feature'
            ),
            [
                'allow_duplicate' => true,
            ]
        );

        /** @var UserInterface $user */
        $user = $token->getUser();
        $form = $this->formFactory->create(LoginType::class);
        $form->handleRequest($request);
        $formData = $form->getData();

        /** @var EmployeeForAuthentication $employeeForAuthentication */
        $employeeForAuthentication = $this->queryBus->handle(
            GetEmployeeForAuthentication::fromEmail($user->getUsername())
        );

        $this->authenticationHandler->setAuthenticationCredentials(
            $employeeForAuthentication,
            $formData['stay_logged_in']
        );

        return new RedirectResponse($formData['redirect_url'] ?? $employeeForAuthentication->getDefaultPageUrl());
    }

    /**
     * {@inheritdoc}
     */
    protected function getLoginUrl()
    {
        return $this->router->generate($this->getLoginRoute());
    }

    /**
     * Get the route to login page.
     *
     * @return string
     */
    private function getLoginRoute()
    {
        return '_admin_login';
    }
}
