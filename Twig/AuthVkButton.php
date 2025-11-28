<?php

namespace BaksDev\Auth\Vk\Twig;

use BaksDev\Auth\Vk\Services\AuthVkUri\AuthVkUriGeneratorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AuthVkButton extends AbstractExtension
{

    public function __construct(
        private readonly AuthVkUriGeneratorInterface $authVkUriGenerator
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'vk_auth_button',
                [$this, 'vkAuthButton'],
                ['needs_environment' => true, 'is_safe' => ['html']]
            ),
        ];
    }

    public function vkAuthButton(Environment $twig): string
    {

        $uri = $this->authVkUriGenerator->getVkAutUri();

        return $twig->render('@auth-vk/twig/auth_uri/auth-button.html.twig', ['uri' => $uri]);
    }
}