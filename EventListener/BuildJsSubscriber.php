<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace MauticPlugin\MauticCrmBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\BuildJsEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Templating\Helper\AssetsHelper;
use Mautic\FormBundle\Model\FormModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
/**
 * Description of BuildJsSubscriber
 *
 * @author Oscar
 */
class BuildJsSubscriber extends CommonSubscriber
{
    
    /**
     * @var
     */
    protected $formModel;

    /**
     * @var AssetsHelper
     */
    protected $assetsHelper;

    /**
     * BuildJsSubscriber constructor.
     *
     * @param FormModel    $formModel
     * @param AssetsHelper $assetsHelper
     */
    public function __construct(
        FormModel $formModel,
        AssetsHelper $assetsHelper)
    {
        $this->formModel    = $formModel;
        $this->assetsHelper = $assetsHelper;
    }
    
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            CoreEvents::BUILD_MAUTIC_JS => array('onBuildJs', 300)
        );
    }

    /**
     * @param BuildJsEvent $event
     *
     * @return void
     */
    public function onBuildJs(BuildJsEvent $event)
    {
        $js = <<<JS
MauticJS.documentReady(function() {
    alert ('infojobs custom code');
});
JS;
        $event->appendJs($js, 'Infojobs');
    }
}

