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

use Context;
use Employee as LegacyEmployee;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Core\Cache\CacheKeyGeneratorInterface;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\Employee\Exception\AuthenticatingEmployeeNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Employee\Query\GetEmployeeForAuthentication;
use PrestaShop\PrestaShop\Core\Domain\Employee\QueryResult\EmployeeForAuthentication;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Responsible for retrieving Employee entities for the Symfony security components.
 */
final class UserProvider implements UserProviderInterface
{
    /**
     * @var Context
     */
    private $legacyContext;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var CommandBusInterface
     */
    private $queryBus;

    /**
     * @var CacheKeyGeneratorInterface
     */
    private $employeeCacheKeyGenerator;

    /**
     * @param LegacyContext $context
     * @param CacheItemPoolInterface $cache
     * @param CommandBusInterface $queryBus
     * @param CacheKeyGeneratorInterface $employeeCacheKeyGenerator
     */
    public function __construct(
        LegacyContext $context,
        CacheItemPoolInterface $cache,
        CommandBusInterface $queryBus,
        CacheKeyGeneratorInterface $employeeCacheKeyGenerator
    ) {
        $this->legacyContext = $context->getContext();
        $this->cache = $cache;
        $this->queryBus = $queryBus;
        $this->context = $context;
        $this->employeeCacheKeyGenerator = $employeeCacheKeyGenerator;
    }

    /**
     * Fetch the Employee entity that matches the given username.
     * Cache system doesn't supports "@" character, so we rely on a sha1 expression.
     *
     * @param string $username
     *
     * @return Employee
     *
     * @throws InvalidArgumentException
     * @throws UsernameNotFoundException
     */
    public function loadUserByUsername($username)
    {
        $cacheKey = $this->employeeCacheKeyGenerator->generateFromString($username);
        $cachedEmployee = $this->cache->getItem($cacheKey);

        if ($cachedEmployee->isHit()) {
            return $cachedEmployee->get();
        }

        try {
            /** @var EmployeeForAuthentication $employeeForAuthentication */
            $employeeForAuthentication = $this->queryBus->handle(
                GetEmployeeForAuthentication::fromEmail($username)
            );
            // This is an Employee DTO, that's used by authentication system.
            // It is constructed by providing it with a legacy Employee instance.
            $employee = new Employee(
                new LegacyEmployee($employeeForAuthentication->getEmployeeId()->getValue())
            );
            $employee->setRoles($employeeForAuthentication->getRoles());

            $cachedEmployee->set($employee);
            $this->cache->save($cachedEmployee);

            return $cachedEmployee->get();
        } catch (AuthenticatingEmployeeNotFoundException $e) {
            throw new UsernameNotFoundException(
                sprintf('Username "%s" does not exist.', $username),
                0,
                $e
            );
        }
    }

    /**
     * Reload an Employee and returns a fresh instance.
     *
     * @param UserInterface $employee
     *
     * @return Employee
     */
    public function refreshUser(UserInterface $employee)
    {
        if (!$employee instanceof Employee) {
            throw new UnsupportedUserException(
                sprintf('Instances of "%s" are not supported.', get_class($employee))
            );
        }

        return $this->loadUserByUsername($employee->getUsername());
    }

    /**
     * Tests if the given class supports the security layer.
     * Here, only Employee class is allowed to be used to authenticate.
     *
     * @param string $class
     *
     * @return bool
     */
    public function supportsClass($class)
    {
        return $class === Employee::class;
    }
}
