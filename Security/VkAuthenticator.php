<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Auth\Vk\Security;


use BaksDev\Auth\Vk\Api\AuthToken\VkOAuthTokenRequest;
use BaksDev\Auth\Vk\Entity\AccountVk;
use BaksDev\Auth\Vk\Repository\ActiveUserVkAccount\ActiveUserVkAccountInterface;
use BaksDev\Auth\Vk\Repository\ActiveUserVkAccount\ActiveUserVkAccountResult;
use BaksDev\Auth\Vk\Type\AuthVkIdentifier\VkIdentifier;
use BaksDev\Auth\Vk\UseCase\User\Auth\Active\AccountVkActiveDTO;
use BaksDev\Auth\Vk\UseCase\User\Auth\Invariable\AccountVkInvariableDTO;
use BaksDev\Auth\Vk\UseCase\User\Auth\VkAuthDTO;
use BaksDev\Auth\Vk\UseCase\User\Auth\VkAuthHandler;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Users\User\Repository\GetUserById\GetUserByIdInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Translation\TranslatorInterface;

final class VkAuthenticator extends AbstractAuthenticator
{
    private const string LOGIN_ROUTE = 'auth-vk:public.auth';

    public function __construct(
        #[Target('authVkLogger')] private LoggerInterface $logger,
        private readonly AppCacheInterface $cache,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ActiveUserVkAccountInterface $ActiveUserVkAccount,
        private readonly GetUserByIdInterface $userById,
        private readonly TranslatorInterface $translator,
        private readonly VkOAuthTokenRequest $vkOAuthTokenRequest,
        private VkAuthHandler $vkAuthHandler,
    ) {}

    public function supports(Request $request): ?bool
    {

        /** Проверяем, что путь содержит auth/vk */
        $auth_uri = $this->urlGenerator->generate(name: self::LOGIN_ROUTE);
        return str_contains($request->getPathInfo(), $auth_uri);
    }

    public function authenticate(Request $request): Passport
    {

        $Session = $request->getSession();

        return new SelfValidatingPassport(
            new UserBadge('vk_authenticator', function() use ($request, $Session) {

                /* Получить code device_id  */
                $code = $request->query->get('code');
                $device_id = $request->query->get('device_id');

                /* Отправка данных на id.vk дляя получения user_id */
                $VkOAuthTokenDTO = $this->vkOAuthTokenRequest->get($code, $device_id);


                if($VkOAuthTokenDTO->getUserId() === null)
                {
                    return new SelfValidatingPassport(
                        new UserBadge('error', function() {
                            return null;
                        }),
                    );
                }


                if($VkOAuthTokenDTO->getUserId() !== null)
                {

                    $user_id = $VkOAuthTokenDTO->getUserId();

                    /* Передаем значения в сессию */
                    $Session->set('vk_access_token', $VkOAuthTokenDTO->getAccessToken());

                    $Session->set('vk_id_token', $VkOAuthTokenDTO->getIdToken());

                    /**
                     * Авторизуем пользователя по идентификатору  vk user_id
                     */

                    $vkid = new VkIdentifier($user_id);
                    $UserAccount = $this->ActiveUserVkAccount
                        ->findByVkId($vkid);

                    /* Если аккаунт не активен */
                    if(true == ($UserAccount instanceof ActiveUserVkAccountResult) && false === $UserAccount->isActive())
                    {

                        $this->logger->warning(sprintf('Пользователь c идентификатором %s не активен', $vkid));

                        $request->getSession()->getFlashBag()->add(
                            $this->translator->trans('user.active.error.header', domain: 'auth-vk.user'),
                            $this->translator->trans('user.active.error.message', domain: 'auth-vk.user')
                        );

                        return new SelfValidatingPassport(
                            new UserBadge('error', function() {
                                return null;
                            }),
                        );
                    }

                    /* Если нет аккаунта то создаем новый */
                    if(false === ($UserAccount instanceof ActiveUserVkAccountResult))
                    {

                        /* Создать новый AccountVk */
                        $VkAuthDTO = new VkAuthDTO();  // handler

                        $AccountVkInvariableDTO = new AccountVkInvariableDTO();
                        $AccountVkInvariableDTO->setVkid($vkid);

                        $VkAuthDTO->setInvariable($AccountVkInvariableDTO);

                        $AccountVkActiveDTO = new AccountVkActiveDTO();
                        $AccountVkActiveDTO->setActive(true);

                        $VkAuthDTO->setActive($AccountVkActiveDTO);

                        $AccountVk = $this->vkAuthHandler->handle($VkAuthDTO);

                        if(true === ($AccountVk instanceof AccountVk))
                        {
                            $UserUid = $AccountVk->getId();
                            return $this->userById->get($UserUid);
                        }

                        return null;

                    }

                    /** Сбросить кеш ролей пользователя */
                    $cache = $this->cache->init('UserGroup');
                    $cache->clear();

                    /** Удалить авторизацию доверенности пользователя */
                    $Session = $request->getSession();
                    $Session->remove('Authority');

                    if($UserAccount instanceof ActiveUserVkAccountResult)
                    {
                        $UserUid = $UserAccount->getAccount();
                        return $this->userById->get($UserUid);
                    }
                }

                return null;
            }),

        );

    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

}
