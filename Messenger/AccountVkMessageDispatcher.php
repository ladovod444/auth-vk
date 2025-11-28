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

namespace BaksDev\Auth\Vk\Messenger;


use BaksDev\Auth\Email\Messenger\CreateAccount\CreateAccountMessage;
use BaksDev\Auth\Email\Type\Email\AccountEmail;
use BaksDev\Auth\Vk\Api\UserInfo\VkUserInfoRequest;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Reference\Gender\Type\Gender;
use BaksDev\Reference\Gender\Type\Genders\Collection\GenderMen;
use BaksDev\Reference\Gender\Type\Genders\Collection\GenderWomen;
use BaksDev\Users\Profile\TypeProfile\Type\Id\Choice\TypeProfileUser;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\UserProfileStatus\Status\UserProfileStatusActive;
use BaksDev\Users\Profile\UserProfile\Type\UserProfileStatus\UserProfileStatus;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Info\InfoDTO;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\UserProfileDTO;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\UserProfileHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class AccountVkMessageDispatcher
{

    public function __construct(
        #[Target('authVkLogger')] private LoggerInterface $logger,
        private RequestStack $requestStack,
        private readonly VkUserInfoRequest $vkUserInfoRequest,
        private readonly UserProfileHandler $userProfileHandler,
        private readonly MessageDispatchInterface $messageDispatch,
    ) {}

    public function __invoke(AccountVkMessage $message): void
    {

        /* TODO проверить вызов из консоли */

        try
        {
            $session = $this->requestStack->getSession();
        }
        catch(SessionNotFoundException)
        {
            return;
        }

        /* Получить vk_access_token из сессии */

        $vk_access_token = $session->get('vk_access_token') ?? false;

        if($vk_access_token === false)
        {
            return;
        }

        /* Получить данные пользователя Vk */
        $VkUserInfoDTO = $this->vkUserInfoRequest->get($vk_access_token);

        if(false !== $VkUserInfoDTO)
        {

            $this->logger->notice(sprintf('Данные пользователя c идентификатором vkid: %s ', $VkUserInfoDTO->getUserId()));

            /**
             * Создаем профиль пользователя по умолчанию
             */
            $UserUid = $message->getId();

            $UserProfileDTO = new UserProfileDTO();
            $UserProfileDTO->setSort(100);
            $UserProfileDTO->setType(new TypeProfileUid(TypeProfileUser::class));

            /** @var InfoDTO $InfoDTO */
            $InfoDTO = $UserProfileDTO->getInfo();
            $InfoDTO->setUrl(uniqid('', false));
            $InfoDTO->setUsr($UserUid);
            $InfoDTO->setStatus(new UserProfileStatus(UserProfileStatusActive::class));

            $UserProfileDTO->setInfo($InfoDTO);

            $PersonalDTO = $UserProfileDTO->getPersonal();


            /* Email Vk */
            if($VkUserInfoDTO->getEmail())
            {

                $AccountEmail = new AccountEmail($VkUserInfoDTO->getEmail());
                /* Отправляем сообщение в шину */
                $this->messageDispatch->dispatch(
                    message: new CreateAccountMessage($UserUid, $AccountEmail),
                    transport: 'auth-vk'
                );

            }

            /* Данные полученные из API по first_name и last_name использовать для username */
            if($VkUserInfoDTO->getFirstName() && $VkUserInfoDTO->getLastName())
            {
                $PersonalDTO->setUsername($VkUserInfoDTO->getFirstName().' '.$VkUserInfoDTO->getLastName());

            }

            /* Пол */
            if($VkUserInfoDTO->getSex())
            {
                $genderValue = $VkUserInfoDTO->getSex() == 2 ? GenderMen::GENDER : GenderWomen::GENDER;

                $this->logger->notice(
                    sprintf(
                        'Пол пользователя c идентификатором vkid: %s пол: %s',
                        $VkUserInfoDTO->getUserId(),
                        $genderValue
                    )
                );

                $gender = new Gender($genderValue);

                $PersonalDTO->setGender($gender);
            }


            /* День рождения */
            if($VkUserInfoDTO->getBirthday())
            {
                $birthday = new \DateTimeImmutable($VkUserInfoDTO->getBirthday());
                $PersonalDTO->setBirthday($birthday);
            }

            $UserProfileDTO->setPersonal($PersonalDTO);

            $UserProfile = $this->userProfileHandler->handle($UserProfileDTO);

            /* Сделать проверку и logger */
            if(false === ($UserProfile instanceof UserProfile))
            {
                $this->logger->error(
                    sprintf(
                        'Ошибка при создании Vk пользователя идентификатором vkid: %s',
                        $VkUserInfoDTO->getUserId()
                    )
                );
            }

        }
    }
}
