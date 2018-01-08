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
        //$jQueryUrl = $this->$assetsHelper->getUrl('app/bundles/CoreBundle/Assets/js/libraries/2.jquery.js', null, null, true);
        $js = <<<JS
                
MauticJS.initAutocompleteIfj = function () {
  MauticJS.mauticInsertedScripts = MauticJS.mauticInsertedScripts || {};
   
  if ("undefined" == typeof jQuery && "undefined" == typeof MauticJS.mauticInsertedScripts.jQuery) {
      console.log ('insert jquery')
      MauticJS.insertScript('https://code.jquery.com/jquery-1.12.4.min.js');
      MauticJS.mauticInsertedScripts.jQuery = true;
  }
  else{
    //cargamos jquery-ui cuando jQuery ya esta disponible  
    if ("undefined" == typeof jQuery.ui) {
        console.log ('insert jquery-ui');
        MauticJS.insertScript('https://code.jquery.com/ui/1.12.1/jquery-ui.min.js');  
        MauticJS.insertStyle('https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css');
    }
  }      
  if ("undefined" == typeof jQuery||"undefined" == typeof jQuery.ui) {
      console.log ('carga asincrona de jquery y jquery ui')
      window.setTimeout(MauticJS.initAutocompleteIfj, 500);
  } else {
      MauticJS.autocompleteIfj();
  }
}  

MauticJS.autocompleteIfj = function () {
  console.log('autocompleteIfj');
  var dtcInfoEmpresasURL = "https://ws.uniqua.es/business_search/companyinfo?code=%CI_DATACENTRIC";
  var el = jQuery(".dtc-razon_social");
  if (el.length) {
    el.autocomplete({
      minLength: 3,
      source: function(request, response) {
        var dtcAutocompleteURL = "https://ws.uniqua.es/business_search/autocomplete?query=%QUERY&num=10";

        dtcAutocompleteURL = dtcAutocompleteURL.replace('%QUERY', request.term);


        $.ajax({
          url: dtcAutocompleteURL,
          dataType: "json",
          success: function(data) {
            console.log(data);
            if (data.status == "SUCCESS") {
              console.log(data.response);
              response(data.response);
            }
          }
        });
      },
      select: function(event, ui) {
        jQuery('.dtc-razon_social').val(ui.item.RAZON_SOCIAL);
        jQuery('.dtc-id_salesforce').val(ui.item.CI_INFOJOBS);
        jQuery('.dtc-id_datacentric').val(ui.item.CI_DATACENTRIC);
        dtcInfoEmpresasURL = "https://ws.uniqua.es/business_search/companyinfo?code=%CI_DATACENTRIC";        
        dtcInfoEmpresasURL = dtcInfoEmpresasURL.replace('%CI_DATACENTRIC', ui.item.CI_DATACENTRIC);
        console.log(dtcInfoEmpresasURL);
        $.ajax({
          url: dtcInfoEmpresasURL,
          success: function(data) {
            if (data.status == "SUCCESS") {
              console.log(data);
              jQuery('.dtc-actividad').val(data.response.ACTIVIDAD); 
              jQuery('.dtc-nif').val(data.response.NIF);   
              jQuery('.dtc-direccion').val(data.response.DIRECCION);
              jQuery('.dtc-cod_postal').val(data.response.COD_POSTAL);
              jQuery('.dtc-localidad').val(data.response.LOCALIDAD);
              jQuery('.dtc-provincia').val(data.response.PROVINCIA);
              jQuery('.dtc-actividad').val(data.response.ACTIVIDAD);
              jQuery('.dtc-actividad').val(data.response.ACTIVIDAD);
              jQuery('.dtc-telefono_1').val(data.response.TELEFONO_1);
              jQuery('.dtc-nombre_cargo').val(data.response.NOMBRE_CARGO);
              jQuery('.dtc-apelld_1_cargo').val(data.response.APELLD_1_CARGO);
              jQuery('.dtc-apelld_2_cargo').val(data.response.APELLD_2_CARGO);
              jQuery('.dtc-cargo').val(data.response.CARGO);
              jQuery('.dtc-numero_empleados').val(data.response.NUMERO_EMPLEADOS);
              jQuery('.dtc-ingresos').val(data.response.INGRESOS);
              jQuery('.dtc-poligono').val(data.response.POLIGONO);
                
            }
          },
          error: function() {
            console.log('error llamando a companyinfo');
          }
        });
        return false;
      }
    }).autocomplete("instance")._renderItem = function(ul, item) {
      return 
        jQuery("<div class='ui-menu-item-wrapper'>")
        .append(    
        jQuery("<li>")
        .append(item.RAZON_SOCIAL))
        .appendTo(ul);
    };
  }
};             
                
MauticJS.documentReady(function() {
  console.log('ready');
   var x = document.getElementsByClassName("dtc-razon_social");
   if (x.length >0){
       console.log('cargar autocompletar infojobs');
        MauticJS.initAutocompleteIfj();
    }
});    

                
JS;
        $event->appendJs($js, 'Infojobs');
    }
}

