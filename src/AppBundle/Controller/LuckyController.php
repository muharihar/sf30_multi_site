<?php

// src/AppBundle/Controller/LuckyController.php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

class LuckyController extends Controller {

    /**
     * @Route("/lucky/number")
     */
    public function numberAction() {
        //session
        $session = new Session();
        if (!$session->isStarted()){
            //$session->start();
        }
        // set and get session attributes
        $session->set('name', 'Drak');
        $session->get('name');

        // set flash messages
        $session->getFlashBag()->add('notice', 'Profile updated');

        // retrieve messages
        $_session_test = '';
        foreach ($session->getFlashBag()->get('notice', array()) as $message) {
            $_session_test .= '<div class="flash-notice">' . $message . '</div>';
        }
        //session.end
        
        $number = rand(0, 100);

        return new Response(
                '<html><body>Lucky number: ' . $number . $_session_test. '</body></html>'
        );
    }

}
