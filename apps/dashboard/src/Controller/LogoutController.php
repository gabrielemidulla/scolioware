<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogoutController extends AbstractController
{
    #[Route('/logout.php', name: 'logout', methods: ['POST'])]
    public function logout(): Response
    {
        sw_csrf_check();

        sw_logout();

        return $this->redirect('login.php');
    }
}
